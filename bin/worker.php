<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPSocketConnection;
use SDPMlab\ZtEventGateway\MessageQueue\Consumer;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\HandlerScanner;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\QueueTopology;
use SDPMlab\ZtEventGateway\Vault\Tls\VaultMtlsRegistry;
use SDPMlab\ZtEventGateway\Vault\Tls\VaultTlsContext;
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

    // ── Vault PKI mTLS (transport-only identity) ─────────────────
    //   Service identity is the Vault-issued X.509 client certificate and
    //   nothing else — there is no SPIFFE Workload API, no SPIRE, no LSVID
    //   nested tokens, and no Keycloak JWT. When mTLS is enabled the worker
    //   populates VaultMtlsRegistry from the vault-agent rendered PEM files
    //   so the Anser global filter (VaultMtlsFilter) injects them into every
    //   downstream HTTP call.
    //
    //   Toggle:    VAULT_MTLS_ENABLED=1 (default 0 → plain HTTP downstream)
    //   Files:     VAULT_TLS_CERT / VAULT_TLS_KEY / VAULT_TLS_CA
    //              (defaults /vault/out/{tls.crt,tls.key,ca.crt})
    //   Required:  IDENTITY_REQUIRED=1 → fail-closed if the files are absent
    $mtlsEnabled = $env('VAULT_MTLS_ENABLED', '0') === '1';

    if ($mtlsEnabled) {
        $vCert = $env('VAULT_TLS_CERT', '/vault/out/tls.crt');
        $vKey  = $env('VAULT_TLS_KEY',  '/vault/out/tls.key');
        $vCa   = $env('VAULT_TLS_CA',   '/vault/out/ca.crt');
        try {
            $vaultCtx = VaultTlsContext::fromVaultFiles($vCert, $vKey, $vCa);
            $vaultCtx->current(); // force initial read — fail fast if files absent
            VaultMtlsRegistry::set($vaultCtx);
            fwrite(STDOUT, sprintf(
                "[worker] Vault mTLS registry initialized (id=%s)\n",
                $vaultCtx->id(),
            ));
        } catch (\Throwable $e) {
            fwrite(STDERR, "[worker] Vault mTLS init FAILED: " . $e->getMessage() . "\n");
            if ($env('IDENTITY_REQUIRED', '0') === '1') {
                exit(1);
            }
        }
    } else {
        fwrite(STDOUT, "[worker] Vault mTLS disabled via VAULT_MTLS_ENABLED=0 — downstream over plain HTTP\n");
    }

    // ── Downstream service URL scheme ────────────────────────────
    //   When mTLS is on, downstream services are reached over https on the
    //   mTLS port (default 8443). When off, plain http on their original
    //   host-mapped ports. Anser SimpleService base URLs are registered in
    //   init.php; the scheme/port logic here mirrors that so logging matches.
    $scheme = $mtlsEnabled ? 'https' : 'http';
    fwrite(STDOUT, sprintf(
        "[worker] downstream scheme=%s (mtls=%s)\n",
        $scheme,
        $mtlsEnabled ? 'on' : 'off',
    ));

    // ── Global filter — Vault mTLS injection ─────────────────────
    //   VaultMtlsFilter is a no-op when the registry is empty (mTLS off), so
    //   registering it unconditionally is safe.
    if ($mtlsEnabled) {
        ActionFilter::setGlobalFilter(\Filters\VaultMtlsFilter::class);
        fwrite(STDOUT, "[worker] VaultMtlsFilter registered\n");
    } else {
        fwrite(STDOUT, "[worker] no auth filter registered (mTLS off)\n");
    }

    $messageBus = new MessageBus($channel, $exchange);

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
    $requestConsumer = new RequestConsumer($messageBus);
    $eventConsumer = new EventConsumer($eventBus);
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

    // Run 3: downstream HTTP keep-alive (mTLS-off modes only).
    //
    // The saga's downstream calls go through Anser's singleton Guzzle client
    // (ServiceList::getHttpClient). By default the worker registers no global
    // handler, so each call relies on cURL's implicit reuse. Register a base
    // handler that wraps Guzzle's DEFAULT handler (sync + async safe) and pins
    // TCP keepalive + connection reuse so bursts of saga steps don't churn
    // fresh TCP/handshakes.
    //
    // Gated off when mTLS is on (per-request client certs must never share a
    // reused socket) or when DOWNSTREAM_HTTP_KEEPALIVE=0. Transport reuse only.
    if (!$mtlsEnabled
        && $env('DOWNSTREAM_HTTP_KEEPALIVE', '1') === '1') {
        $defaultHandler = \GuzzleHttp\Utils::chooseHandler();
        $keepAliveHandler = static function (
            \Psr\Http\Message\RequestInterface $request,
            array $options
        ) use ($defaultHandler) {
            $options['curl'] = ($options['curl'] ?? []) + [
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE  => 30,
                CURLOPT_TCP_KEEPINTVL => 15,
                CURLOPT_FORBID_REUSE  => 0,
                CURLOPT_FRESH_CONNECT => 0,
            ];
            return $defaultHandler($request->withHeader('Connection', 'keep-alive'), $options);
        };
        \SDPMlab\Anser\Service\ServiceList::setGlobalHandlerStack($keepAliveHandler);
        fwrite(STDOUT, "[worker] downstream HTTP keep-alive enabled (mTLS off)\n");
    }

    // Run 5: tunable AMQP prefetch (throughput vs p99 trade-off).
    //
    // prefetch=1 (default): one slow message blocks at most N already-buffered
    // messages behind it. AMQP_PREFETCH lets an experiment round sweep this
    // lever. Worker concurrency is still scaled by container replicas.
    $prefetch = (int) $env('AMQP_PREFETCH', '1');
    if ($prefetch < 1) {
        $prefetch = 1;
    }
    $channel->basic_qos(null, $prefetch, null);

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
