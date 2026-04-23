<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPSocketConnection;
use SDPMlab\ZtEventGateway\MessageQueue\Consumer;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\HandlerScanner;
use SDPMlab\ZtEventGateway\MessageQueue\MessageBus;
use SDPMlab\ZtEventGateway\QueueTopology;
use Keycloak\AudienceRegistry;
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

    // ── Keycloak wiring (SHM-backed) ───────────────────────────────
    //   Bootstrap the TokenProvider + JwtValidator from the shared-memory
    //   store populated by the keycloak-watcher daemon. Every publishEvent()
    //   re-reads the current access token from SHM (seqlock-consistent) so
    //   the Worker automatically follows rotations without its own timer.
    //
    //   Opt-out: KEYCLOAK_ENABLED=0 — no token fetch, no validation.
    $keycloakEnabled   = $env('KEYCLOAK_ENABLED', '1') !== '0';
    $tokenProvider     = null;
    $jwtValidator      = null;
    $staleThreshold    = (int) $env('KEYCLOAK_STALE_THRESHOLD_SECS', '900');

    if ($keycloakEnabled) {
        try {
            $envBag = [
                'KEYCLOAK_ENABLED'            => $env('KEYCLOAK_ENABLED', '1'),
                'KEYCLOAK_ISSUER'             => $env('KEYCLOAK_ISSUER', ''),
                'KEYCLOAK_TOKEN_URI'          => $env('KEYCLOAK_TOKEN_URI', ''),
                'KEYCLOAK_JWKS_URI'           => $env('KEYCLOAK_JWKS_URI', ''),
                'KEYCLOAK_CLIENT_ID'          => $env('KEYCLOAK_CLIENT_ID', ''),
                'KEYCLOAK_CLIENT_SECRET'      => $env('KEYCLOAK_CLIENT_SECRET', ''),
                'KEYCLOAK_SHM_DIR'            => $env('KEYCLOAK_SHM_DIR', ''),
                'KEYCLOAK_TOKEN_REFRESH_SKEW' => $env('KEYCLOAK_TOKEN_REFRESH_SKEW', '30'),
            ];
            $state = KeycloakBootstrap::fromEnv($envBag, writable: false);
            $tokenProvider = $state->tokenProvider;
            $jwtValidator  = $state->jwtValidator;

            fwrite(STDOUT, sprintf(
                "[worker] Keycloak enabled — client_id=%s, issuer=%s, realm=%s, shm=%s\n",
                $state->clientId,
                $state->issuer,
                $state->realm,
                $state->shmDir,
            ));

            if ($state->reader->isStale($staleThreshold)) {
                fwrite(STDERR, sprintf(
                    "[worker] WARN: Keycloak SHM is stale at boot — last update %ds ago (threshold %ds)\n",
                    $state->reader->secondsSinceLastUpdate(),
                    $staleThreshold,
                ));
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("[worker] Keycloak bootstrap FAILED: %s\n", $e->getMessage()));
            exit(1);
        }
    } else {
        fwrite(STDOUT, "[worker] KEYCLOAK_ENABLED=0 — bypassing JWT validation and token minting\n");
    }

    // ── Downstream service → client_id audience mapping ──────────
    //   KeycloakBearerFilter consults this to set the correct `aud`
    //   on outgoing tokens. Base URL must match ServiceList in init.php.
    if ($keycloakEnabled) {
        $defaultHost = $env('SERVICE_HOST', 'host.docker.internal');

        $orderHost = $env('ORDER_SERVICE_HOST', $defaultHost);
        $orderPort = $env('ORDER_SERVICE_PORT', '8082');
        AudienceRegistry::register(
            "http://{$orderHost}:{$orderPort}",
            $env('KEYCLOAK_AUDIENCE_ORDER', 'order-service'),
        );

        $productionHost = $env('PRODUCTION_SERVICE_HOST', $defaultHost);
        $productionPort = $env('PRODUCTION_SERVICE_PORT', '8081');
        AudienceRegistry::register(
            "http://{$productionHost}:{$productionPort}",
            $env('KEYCLOAK_AUDIENCE_PRODUCTION', 'production-service'),
        );

        $userHost = $env('USER_SERVICE_HOST', $defaultHost);
        $userPort = $env('USER_SERVICE_PORT', '8083');
        AudienceRegistry::register(
            "http://{$userHost}:{$userPort}",
            $env('KEYCLOAK_AUDIENCE_USER', 'user-service'),
        );

        fwrite(STDOUT, sprintf(
            "[worker] AudienceRegistry: order=%s:%s, production=%s:%s, user=%s:%s\n",
            $orderHost, $orderPort,
            $productionHost, $productionPort,
            $userHost, $userPort,
        ));

        ActionFilter::setGlobalFilter(\Filters\KeycloakBearerFilter::class);
    } else {
        fwrite(STDOUT, "[worker] AudienceRegistry + KeycloakBearerFilter skipped\n");
    }

    $messageBus = new MessageBus($channel, $exchange, $tokenProvider);

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

    $selfAudience = $env('KEYCLOAK_CLIENT_ID', 'worker');

    $eventBus = new EventBus($messageBus, $eventStoreDB);
    $transportConsumer = new Consumer($channel);
    $requestConsumer = new RequestConsumer(
        $messageBus,
        $jwtValidator,
        $selfAudience,
        $keycloakEnabled,
    );
    $eventConsumer = new EventConsumer(
        $eventBus,
        $jwtValidator,
        $selfAudience,
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
