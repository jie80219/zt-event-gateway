<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPSocketConnection;
use SDPMlab\ZtEventGateway\MessageQueue\Consumer;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\HandlerScanner;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\QueueTopology;
use SDPMlab\ZtEventGateway\Spiffe\LSVIDSignerRegistry;
use SDPMlab\ZtEventGateway\Spiffe\LSVIDValidatorRegistry;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeAudienceRegistry;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeBootstrap;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeMtlsRegistry;
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

    // ── LSVID wiring (SHM-backed) ───────────────────────────────
    //   Bootstrap both signer and validators from the shared-memory store
    //   populated by the spiffe-watcher daemon. The table reader exposes
    //   seqlock-consistent reads of meta.json / x509/*.json, so every call
    //   to sign() or verify() fetches the freshest SVID automatically —
    //   no background coroutine is needed for rotation awareness in the
    //   Worker (unlike Gateway, Worker isn't coroutine-based).
    //
    //   Opt-out:      LSVID_ENABLED=0
    //   SHM location: SPIFFE_SHM_DIR (default /tmp/spiffe-shared)
    //   Required:     LSVID_REQUIRED=1 → fail-closed if no SVID in SHM
    // Master toggle: when SPIFFE_ENABLED=0 the entire SPIFFE/LSVID/mTLS
    // layer is skipped — no bootstrap, no audience registry, no global
    // filter, no identity checks in the consumers. The three sub-flags
    // below are force-disabled so operators can flip a single switch
    // rather than keeping them in lockstep manually.
    $spiffeEnabled      = $env('SPIFFE_ENABLED', '1') !== '0';
    $lsvidEnabled       = $spiffeEnabled && $env('LSVID_ENABLED', '1') !== '0';
    $lsvidSigner        = null;
    $lsvidValidator     = null;
    $requestValidator   = null;
    $eventValidator     = null;
    $filterValidator    = null;
    $lsvidRequired      = $spiffeEnabled && $env('LSVID_REQUIRED', '1') === '1';
    $downstreamAudience = $env('DOWNSTREAM_SPIFFE_ID', '');
    $trustDomain        = $env('SPIFFE_TRUST_DOMAIN', 'zt.local');
    $shmDir             = $env('SPIFFE_SHM_DIR', '/tmp/spiffe-shared');
    $staleThreshold     = (int) $env('SPIFFE_STALE_THRESHOLD_SECS', '7200');

    if ($lsvidEnabled) {
        try {
            $boot = SpiffeBootstrap::fromShm($shmDir, [
                'trust_domain'                   => $trustDomain,
                'await_timeout'                  => (float) $env('SPIFFE_AWAIT_TIMEOUT', '30'),
                'clock_skew_seconds'             => 30,
                'require_audience_on_all_levels' => true,
                'spiffe_id'                      => $env('SPIFFE_ID', ''),
            ]);

            $primary = $boot->primary();
            if ($primary === null || empty($primary['key_pem']) || empty($primary['bundle_pem'])) {
                throw new \RuntimeException('SHM ready but primary SVID slot is empty or malformed');
            }

            $lsvidSigner = $boot->signer();
            // One jtiCache per consumer pipeline — RequestConsumer records
            // the L0 jti, and if EventConsumer shared the same cache it
            // would false-positive on the nested L0 it sees during chain
            // validation of L1 envelopes. Filter validator stays cache-less
            // (SpiffeLsvidFilter re-validates tokens it's about to extend).
            $requestValidator = $boot->validator(withJtiCache: true);
            $eventValidator   = $boot->validator(withJtiCache: true);
            $filterValidator  = $boot->validator(withJtiCache: false);
            $lsvidValidator   = $requestValidator;  // kept for legacy call sites

            if ($downstreamAudience === '' && $lsvidRequired) {
                fwrite(STDERR,
                    "[worker] FATAL: LSVID_REQUIRED=1 but DOWNSTREAM_SPIFFE_ID is empty. "
                    . "Set DOWNSTREAM_SPIFFE_ID to the next-hop SPIFFE ID.\n",
                );
                exit(1);
            }

            fwrite(STDOUT, sprintf(
                "[worker] LSVID enabled — signer+validator wired via SHM "
                . "(spiffe_id=%s, bundle_certs=%d, shm_version=%d, downstream=%s, required=%s)\n",
                (string) $primary['spiffe_id'],
                substr_count((string) $primary['bundle_pem'], 'BEGIN CERTIFICATE'),
                $boot->version(),
                $downstreamAudience !== '' ? $downstreamAudience : '(fallback to SPIFFE_ID)',
                $lsvidRequired ? 'yes' : 'no',
            ));

            if ($boot->shmReader()->isStale($staleThreshold)) {
                $msg = sprintf(
                    "SHM is already stale at boot — last update %ds ago (threshold %ds). Watcher may be down.\n",
                    $boot->shmReader()->secondsSinceLastUpdate(),
                    $staleThreshold,
                );
                if ($lsvidRequired) {
                    fwrite(STDERR, "[worker] FATAL: " . $msg);
                    exit(1);
                }
                fwrite(STDERR, "[worker] WARN: " . $msg);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[worker] LSVID bootstrap FAILED (shm=%s): %s\n",
                $shmDir,
                $e->getMessage(),
            ));
            if ($lsvidRequired) {
                fwrite(STDERR, "[worker] FATAL: LSVID_REQUIRED=1 — exiting.\n");
                exit(1);
            }
            fwrite(STDERR, "[worker] LSVID DEGRADED — running with prefix-check only.\n");
        }
    } elseif (!$spiffeEnabled) {
        fwrite(STDOUT, "[worker] SPIFFE disabled via SPIFFE_ENABLED=0 — bypassing LSVID/mTLS/identity checks\n");
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

        // 1b. Filter validator（不帶 jtiCache）for SpiffeLsvidFilter re-validate.
        //     跟 consumer validator 分離，避免對同一個 token 報 jti replay。
        if (isset($filterValidator)) {
            LSVIDValidatorRegistry::set($filterValidator);
            fwrite(STDOUT, "[worker] LSVIDValidatorRegistry initialized (no jtiCache)\n");
        }

        // 2. mTLS — 暫不支援（MVP 不啟用 mTLS，SPIFFE_MTLS_ENABLED=0）
        if ($env('SPIFFE_MTLS_ENABLED', '0') === '1') {
            fwrite(STDERR, "[worker] mTLS not yet supported in MVP mode (direct Workload API)\n");
        }
    }

    // 3. Service URL → SPIFFE ID audience mapping.
    //    The base URL must match what ServiceList registers in init.php.
    //    When SPIFFE_MTLS_ENABLED=1, services are reached via Docker
    //    container names on port 8443 (RoadRunner mTLS).
    //    When mTLS is off, services are on host.docker.internal with
    //    their original host-mapped ports (8081/8082/8083).
    //    When SPIFFE_ENABLED=0 the whole registry + global filter are
    //    skipped: downstream SimpleService calls go over plain HTTP
    //    without the X-LSVID header or mTLS material.
    if ($spiffeEnabled) {
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
    } else {
        fwrite(STDOUT, "[worker] SpiffeAudienceRegistry + SpiffeLsvidFilter skipped (SPIFFE_ENABLED=0)\n");
    }

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
    $requestConsumer = new RequestConsumer(
        $messageBus,
        $requestValidator ?? $lsvidValidator,
        $lsvidRequired,
        $spiffeEnabled,
    );
    $eventConsumer = new EventConsumer(
        $eventBus,
        $eventValidator ?? $lsvidValidator,
        $lsvidRequired,
        $spiffeEnabled,
    );
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
