<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPSocketConnection;
use SDPMlab\ZtEventGateway\MessageQueue\Consumer;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\HandlerScanner;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\QueueTopology;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeAudienceRegistry;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeBootstrap;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeMtlsRegistry;
use Keycloak\AudienceRegistry as KeycloakAudienceRegistry;
use Keycloak\KeycloakBootstrap;
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

    // ── SPIFFE bootstrap (direct Workload API) ─────────────────
    //   This is the SPIFFE+KC naïve baseline — no LSVID, no SHM.
    //   The X509Source opens a FetchX509SVID stream against the
    //   SPIRE Agent's UDS endpoint and keeps the SVID always-fresh
    //   in-process.
    $spiffeEnabled      = $env('SPIFFE_ENABLED', '1') !== '0';
    $spiffeBoot         = null;
    $downstreamAudience = $env('DOWNSTREAM_SPIFFE_ID', '');
    $trustDomain        = $env('SPIFFE_TRUST_DOMAIN', 'zt.local');

    if ($spiffeEnabled) {
        try {
            $spiffeBoot = SpiffeBootstrap::fromUds(
                $env('SPIFFE_ENDPOINT_SOCKET', 'unix:/tmp/spire-agent/public/api.sock'),
                [
                    'trust_domain'  => $trustDomain,
                    'await_timeout' => (float) $env('SPIFFE_AWAIT_TIMEOUT', '30'),
                    'spiffe_id'     => $env('SPIFFE_ID', ''),
                ],
            );

            $primary = $spiffeBoot->primary();
            if ($primary === null || empty($primary['key_pem']) || empty($primary['bundle_pem'])) {
                throw new \RuntimeException('X509Source ready but primary SVID is empty or malformed');
            }

            fwrite(STDOUT, sprintf(
                "[worker] SPIFFE bootstrap OK (spiffe_id=%s, bundle_certs=%d, downstream=%s)\n",
                (string) $primary['spiffe_id'],
                substr_count((string) $primary['bundle_pem'], 'BEGIN CERTIFICATE'),
                $downstreamAudience !== '' ? $downstreamAudience : '(fallback to SPIFFE_ID)',
            ));

            if ($env('SPIFFE_MTLS_ENABLED', '0') === '1') {
                SpiffeMtlsRegistry::setSource($spiffeBoot->source());
                fwrite(STDOUT, "[worker] SpiffeMtlsRegistry initialized with X509Source\n");
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[worker] SPIFFE bootstrap FAILED (socket=%s): %s\n",
                $env('SPIFFE_ENDPOINT_SOCKET', 'unix:/tmp/spire-agent/public/api.sock'),
                $e->getMessage(),
            ));
            exit(1);
        }
    } else {
        fwrite(STDOUT, "[worker] SPIFFE disabled via SPIFFE_ENABLED=0 — bypassing identity layer\n");
    }

    // Service URL → SPIFFE ID audience mapping (consumed by downstream filters).
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
        $productionPort = $isMtls ? $mtlsPort : $env('PRODUCTION_SERVICE_PORT', '8083');
        SpiffeAudienceRegistry::register(
            "{$scheme}://{$productionHost}:{$productionPort}",
            $env('PRODUCTION_SPIFFE_ID', 'spiffe://zt.local/production-service'),
        );

        $userHost = $env('USER_SERVICE_HOST', $defaultHost);
        $userPort = $isMtls ? $mtlsPort : $env('USER_SERVICE_PORT', '8084');
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
    } else {
        fwrite(STDOUT, "[worker] SpiffeAudienceRegistry skipped (SPIFFE_ENABLED=0)\n");
    }

    // ── Keycloak wiring (direct OIDC, no SHM) ──────────────────
    //   TokenProvider hits the Keycloak token endpoint and JwksCache
    //   fetches the JWKS over HTTPS — no shared-memory caching layer
    //   between processes. Each worker maintains its own in-process
    //   token cache via TokenProvider's internal expiry tracking.
    $keycloakEnabled    = $env('KEYCLOAK_ENABLED', '0') !== '0';
    $tokenProvider      = null;
    $jwtValidator       = null;
    $kcSelfAudience     = $env('KEYCLOAK_CLIENT_ID', 'worker');

    if ($keycloakEnabled) {
        try {
            $kcEnvBag = [
                'KEYCLOAK_ENABLED'            => $env('KEYCLOAK_ENABLED', '1'),
                'KEYCLOAK_ISSUER'             => $env('KEYCLOAK_ISSUER', ''),
                'KEYCLOAK_TOKEN_URI'          => $env('KEYCLOAK_TOKEN_URI', ''),
                'KEYCLOAK_JWKS_URI'           => $env('KEYCLOAK_JWKS_URI', ''),
                'KEYCLOAK_CLIENT_ID'          => $env('KEYCLOAK_CLIENT_ID', ''),
                'KEYCLOAK_CLIENT_SECRET'      => $env('KEYCLOAK_CLIENT_SECRET', ''),
                'KEYCLOAK_TOKEN_REFRESH_SKEW' => $env('KEYCLOAK_TOKEN_REFRESH_SKEW', '30'),
            ];
            $kcState = KeycloakBootstrap::fromEnv($kcEnvBag);
            $tokenProvider = $kcState->tokenProvider;
            $jwtValidator  = $kcState->jwtValidator;

            fwrite(STDOUT, sprintf(
                "[worker] Keycloak enabled — client_id=%s, issuer=%s, realm=%s\n",
                $kcState->clientId,
                $kcState->issuer,
                $kcState->realm,
            ));

            $defaultHost   = $env('SERVICE_HOST', 'host.docker.internal');
            $orderHost2    = $env('ORDER_SERVICE_HOST', $defaultHost);
            $orderPort2    = $env('ORDER_SERVICE_PORT', '8082');
            KeycloakAudienceRegistry::register(
                "http://{$orderHost2}:{$orderPort2}",
                $env('KEYCLOAK_AUDIENCE_ORDER', 'order-service'),
            );

            $productionHost2 = $env('PRODUCTION_SERVICE_HOST', $defaultHost);
            $productionPort2 = $env('PRODUCTION_SERVICE_PORT', '8083');
            KeycloakAudienceRegistry::register(
                "http://{$productionHost2}:{$productionPort2}",
                $env('KEYCLOAK_AUDIENCE_PRODUCTION', 'production-service'),
            );

            $userHost2 = $env('USER_SERVICE_HOST', $defaultHost);
            $userPort2 = $env('USER_SERVICE_PORT', '8084');
            KeycloakAudienceRegistry::register(
                "http://{$userHost2}:{$userPort2}",
                $env('KEYCLOAK_AUDIENCE_USER', 'user-service'),
            );

            fwrite(STDOUT, sprintf(
                "[worker] KeycloakAudienceRegistry: order=%s:%s, production=%s:%s, user=%s:%s\n",
                $orderHost2, $orderPort2,
                $productionHost2, $productionPort2,
                $userHost2, $userPort2,
            ));
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("[worker] Keycloak bootstrap FAILED: %s\n", $e->getMessage()));
            $keycloakEnabled = false;
            $tokenProvider = null;
            $jwtValidator = null;
        }
    } else {
        fwrite(STDOUT, "[worker] Keycloak disabled via KEYCLOAK_ENABLED=0\n");
    }

    // ── Global filter — composite of SPIFFE + Keycloak ─────────
    if ($spiffeEnabled || $keycloakEnabled) {
        ActionFilter::setGlobalFilter(\Filters\CompositeAuthFilter::class);
        fwrite(STDOUT, sprintf(
            "[worker] CompositeAuthFilter registered (spiffe=%s, keycloak=%s)\n",
            $spiffeEnabled ? 'on' : 'off',
            $keycloakEnabled ? 'on' : 'off',
        ));
    } else {
        fwrite(STDOUT, "[worker] no auth filter registered (both stacks disabled)\n");
    }

    $messageBus = new MessageBus(
        $channel,
        $exchange,
        $tokenProvider,
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
        $spiffeEnabled,
        $jwtValidator,
        $kcSelfAudience,
        $keycloakEnabled,
    );
    $eventConsumer = new EventConsumer(
        $eventBus,
        $spiffeEnabled,
        $jwtValidator,
        $kcSelfAudience,
        $keycloakEnabled,
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

    // Downstream HTTP keep-alive (mTLS-off modes only). Pure transport
    // reuse; every request still carries its own Bearer header.
    if ($env('SPIFFE_MTLS_ENABLED', '0') !== '1'
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

    // AMQP prefetch (throughput vs p99 trade-off). prefetch=1 is the
    // default — tail-latency claims hinge on it; do not raise blindly.
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
