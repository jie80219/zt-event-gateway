<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPSocketConnection;
use SDPMlab\ZtEventGateway\MessageQueue\Consumer;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\HandlerScanner;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\QueueTopology;
use SDPMlab\LSVID\JtiReplayCache;
use SDPMlab\LSVID\LSVIDSigner;
use SDPMlab\LSVID\LSVIDValidator;
use SDPMlab\ZtEventGateway\Spiffe\LSVID\SpiffeTableSvidReader;
use SDPMlab\ZtEventGateway\Spiffe\LSVIDSignerRegistry;
use SDPMlab\ZtEventGateway\Spiffe\LSVIDValidatorRegistry;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeAudienceRegistry;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeMtlsRegistry;
use Spiffe\SharedMemory\SpiffeTableReader;
use Spiffe\TLS\SpiffeTlsContext;
use SDPMlab\Anser\Service\ActionFilter;
use SDPMlab\ZtEventGateway\EventStore\EventStoreDB;
use ZtEventGateway\Worker\EventConsumer;
use ZtEventGateway\Worker\RequestConsumer;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = static function (string $key, string $default): string {
    $value = getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

$host = $env('AMQP_HOST', 'rabbitmq');
$port = (int) $env('AMQP_PORT', '5672');
$user = $env('AMQP_USER', 'zt');
$password = $env('AMQP_PASSWORD', 'ztpass');
$exchange = $env('AMQP_EXCHANGE', 'events');
$exchangeType = $env('AMQP_EXCHANGE_TYPE', 'direct');
$requestQueue = $env('REQUEST_QUEUE', 'order_queue');
$requestRoutingKey = $env('REQUEST_ROUTING_KEY', 'request.new');
$sagaFilePath = dirname(__DIR__) . '/Sagas/OrderSaga.php';

$connection = null;
$channel = null;

try {
    $connection = new AMQPSocketConnection($host, $port, $user, $password);
    $channel = $connection->channel();

    // ── LSVID wiring ─────────────────────────────────────────────
    //   Probe the SHM SVID store at startup. If a primary X.509-SVID is
    //   available, wire LSVIDSigner + LSVIDValidator into the bus and
    //   consumers so every outbound event carries a nested JWS chain and
    //   every inbound event is cryptographically verified. When SVID is
    //   absent (e.g. local dev without spire-agent) we fall back to the
    //   plain prefix-check path and log the degradation clearly.
    //
    //   Opt-out: LSVID_ENABLED=0
    //   SHM override: SPIFFE_SHM_DIR (default: package built-in /tmp/spiffe-shared)
    $lsvidEnabled = $env('LSVID_ENABLED', '1') !== '0';
    $lsvidSigner = null;
    $lsvidValidator = null;
    $lsvidRequired = $env('LSVID_REQUIRED', '1') === '1';
    $downstreamAudience = $env('DOWNSTREAM_SPIFFE_ID', '');
    $trustDomain = $env('SPIFFE_TRUST_DOMAIN', 'zt.local');

    if ($lsvidEnabled) {
        $shmDir = $env('SPIFFE_SHM_DIR', '');
        $reader = $shmDir !== ''
            ? new SpiffeTableReader($shmDir)
            : new SpiffeTableReader();

        $primary = $reader->readX509Primary();
        if ($primary !== null && !empty($primary['key_pem']) && !empty($primary['bundle_pem'])) {
            $svidReader = new SpiffeTableSvidReader($reader);
            $jtiCache = new JtiReplayCache();
            $lsvidSigner = new LSVIDSigner($svidReader);
            $lsvidValidator = new LSVIDValidator(
                $svidReader,
                clockSkewSeconds: 30,
                jtiCache: $jtiCache,
                trustDomain: $trustDomain,
                requireNbf: false,
                requireAudienceOnAllLevels: true,
            );

            // Validate downstream audience — required when LSVID is fully enabled.
            if ($downstreamAudience === '' && $lsvidRequired) {
                fwrite(STDERR,
                    "[worker] FATAL: LSVID_REQUIRED=1 but DOWNSTREAM_SPIFFE_ID is empty. "
                    . "Set DOWNSTREAM_SPIFFE_ID to the next-hop SPIFFE ID.\n",
                );
                exit(1);
            }

            fwrite(STDOUT, sprintf(
                "[worker] LSVID enabled — signer+validator wired (spiffe_id=%s, bundle_certs=%d, downstream=%s, required=%s)\n",
                (string) ($primary['spiffe_id'] ?? '(unknown)'),
                substr_count((string) $primary['bundle_pem'], 'BEGIN CERTIFICATE'),
                $downstreamAudience !== '' ? $downstreamAudience : '(fallback to SPIFFE_ID)',
                $lsvidRequired ? 'yes' : 'no',
            ));
        } else {
            fwrite(STDERR, sprintf(
                "[worker] LSVID DEGRADED — no primary X.509-SVID in SHM store (dir=%s). "
                . "Running with prefix-check only. Start spiffe-watcher/spiffe-helper to enable LSVID.\n",
                $shmDir !== '' ? $shmDir : '/tmp/spiffe-shared',
            ));
        }
    } else {
        fwrite(STDOUT, "[worker] LSVID disabled via LSVID_ENABLED=0\n");
    }

    // ── mTLS + LSVID signer + audience map ─────────────────────
    //   Wire three static registries so the Anser global filter
    //   (SpiffeLsvidFilter) can:
    //     1. Extend the LSVID chain with a new level per HTTP call
    //        (LSVIDSignerRegistry → signer->extend())
    //     2. Set the correct `aud` claim for each target service
    //        (SpiffeAudienceRegistry → URL → SPIFFE ID mapping)
    //     3. Inject mTLS credentials into Guzzle options
    //        (SpiffeMtlsRegistry → cert/ssl_key/verify)
    if ($lsvidEnabled && $lsvidSigner !== null) {
        // 1. LSVID signer for extending the chain.
        LSVIDSignerRegistry::set($lsvidSigner);
        fwrite(STDOUT, "[worker] LSVIDSignerRegistry initialized\n");

        // 1b. LSVID validator for re-validating prior tokens before extend.
        //     This gives SpiffeLsvidFilter defence-in-depth: even if the
        //     LSVIDContext was populated from a compromised path, we re-run
        //     full chain validation against the CA bundle before producing L2.
        if ($lsvidValidator !== null) {
            LSVIDValidatorRegistry::set($lsvidValidator);
            fwrite(STDOUT, "[worker] LSVIDValidatorRegistry initialized\n");
        }

        // 2. mTLS context.
        try {
            $tlsContext = SpiffeTlsContext::fromReader($reader);
            SpiffeMtlsRegistry::set($tlsContext);
            fwrite(STDOUT, "[worker] mTLS registry initialized\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[worker] mTLS registry init failed (degraded): %s\n",
                $e->getMessage(),
            ));
        }
    }

    // 3. Service URL → SPIFFE ID audience mapping.
    //    The base URL must match what ServiceList registers in init.php.
    //    When SPIFFE_MTLS_ENABLED=1, services are reached via Docker
    //    container names on port 8443 (RoadRunner mTLS).
    //    When mTLS is off, services are on host.docker.internal with
    //    their original host-mapped ports (8081/8082/8083).
    $isMtls = ($env('SPIFFE_MTLS_ENABLED', '0')) === '1';
    $scheme = $isMtls ? 'https' : 'http';
    $defaultHost = $env('SERVICE_HOST', 'host.docker.internal');
    $mtlsPort = $env('MTLS_PORT', '8443');

    $orderHost = $env('ORDER_SERVICE_HOST', $defaultHost);
    $orderPort = $isMtls ? $mtlsPort : $env('ORDER_SERVICE_PORT', '8082');
    SpiffeAudienceRegistry::register(
        "{$scheme}://{$orderHost}:{$orderPort}",
        $env('ORDER_SPIFFE_ID', 'spiffe://zt.local/order-service'),
    );

    $productionHost = $env('PRODUCTION_SERVICE_HOST', $defaultHost);
    $productionPort = $isMtls ? $mtlsPort : $env('PRODUCTION_SERVICE_PORT', '8081');
    SpiffeAudienceRegistry::register(
        "{$scheme}://{$productionHost}:{$productionPort}",
        $env('PRODUCTION_SPIFFE_ID', 'spiffe://zt.local/production-service'),
    );

    $userHost = $env('USER_SERVICE_HOST', $defaultHost);
    $userPort = $isMtls ? $mtlsPort : $env('USER_SERVICE_PORT', '8083');
    SpiffeAudienceRegistry::register(
        "{$scheme}://{$userHost}:{$userPort}",
        $env('USER_SPIFFE_ID', 'spiffe://zt.local/user-service'),
    );

    fwrite(STDOUT, sprintf(
        "[worker] SpiffeAudienceRegistry: order=%s:%s, production=%s:%s, user=%s:%s (scheme=%s)\n",
        $orderHost, $orderPort,
        $productionHost, $productionPort,
        $userHost, $userPort,
        $scheme,
    ));

    // Register Anser global filter — extends LSVID chain + injects mTLS
    // into all outgoing SimpleService HTTP calls.
    ActionFilter::setGlobalFilter(\Filters\SpiffeLsvidFilter::class);

    $messageBus = new MessageBus(
        $channel,
        $exchange,
        $lsvidSigner,
        $downstreamAudience,
        $lsvidRequired,
    );

    // ── EventStoreDB wiring ─────────────────────────────────────
    $eventStoreDB = null;
    $esEnabled = $env('EVENTSTOREDB_ENABLED', '0') === '1';
    if ($esEnabled) {
        $esHost = $env('EVENTSTOREDB_HOST', 'localhost');
        $esPort = (int) $env('EVENTSTOREDB_PORT', '2113');
        $eventStoreDB = new EventStoreDB($esHost, $esPort, '', '');
        fwrite(STDOUT, sprintf("[worker] EventStoreDB enabled (host=%s:%d)\n", $esHost, $esPort));
    } else {
        fwrite(STDOUT, "[worker] EventStoreDB disabled\n");
    }

    $eventBus = new EventBus($messageBus, $eventStoreDB);
    $transportConsumer = new Consumer($channel);
    $requestConsumer = new RequestConsumer($messageBus, $lsvidValidator, $lsvidRequired);
    $eventConsumer = new EventConsumer($eventBus, $lsvidValidator, $lsvidRequired);
    $scanner = new HandlerScanner();
    $eventQueues = $scanner->scanEventTypesFromFile($sagaFilePath);

    QueueTopology::setupRequestAndEventQueues(
        $channel,
        $exchange,
        $exchangeType,
        $requestQueue,
        $requestRoutingKey,
        $eventQueues,
    );
    $scanner->scanAndRegisterHandlers('App\Sagas', $eventBus);
    $channel->basic_qos(null, 1, null);

    $transportConsumer->subscribe($requestQueue, [$requestConsumer, 'process']);
    foreach ($eventQueues as $queueName) {
        $transportConsumer->subscribe($queueName, [$eventConsumer, 'process']);
    }

    fwrite(
        STDOUT,
        sprintf(
            "[worker] listening order_queue=%s event_queues=%s exchange=%s routing_key=%s\n",
            $requestQueue,
            implode('|', $eventQueues),
            $exchange,
            $requestRoutingKey,
        ),
    );

    $transportConsumer->run();
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[worker] fatal: %s\n", $exception->getMessage()));
    exit(1);
} finally {
    if ($channel !== null) {
        $channel->close();
    }

    if ($connection !== null) {
        $connection->close();
    }
}
