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
            // EventConsumer's jtiCache disabled: the saga fan-out
            // (multiple handlers + AMQP redelivery semantics) legitimately
            // re-validates the same L1 envelope within the same process,
            // and a process-wide jti cache treats those as replay.
            // L1 envelopes are still signed/verified end-to-end; replay
            // protection at the entry point (RequestConsumer L0) is kept.
            $eventValidator   = $boot->validator(withJtiCache: false);
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

        // Run 8 (verification-type): the signer is wired exactly once here
        //   and reused for every downstream extend() via the registry —
        //   there is no per-request reconstruction. LSVID_PREP_DEBUG=1
        //   surfaces the singleton object id so the prep-cache hit rate can
        //   be confirmed at runtime (default 0 → no output, no behaviour
        //   change). Does not touch any validation path.
        if ($env('LSVID_PREP_DEBUG', '0') === '1') {
            fwrite(STDOUT, sprintf(
                "[worker] LSVID_PREP_DEBUG: signer#%d wired once at bootstrap (singleton reuse asserted)\n",
                spl_object_id($lsvidSigner),
            ));
        }

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
    //    their original host-mapped ports (order=8082, production=8083, user=8084).
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

    // ── Keycloak wiring (SHM-backed, independent of SPIFFE) ────
    //   Bootstrap the TokenProvider + JwtValidator from the shared-memory
    //   store populated by the keycloak-watcher daemon. Every publishEvent()
    //   re-reads the current access token from SHM (seqlock-consistent) so
    //   the Worker automatically follows rotations without its own timer.
    //
    //   Opt-out: KEYCLOAK_ENABLED=0 (default). When off no token is fetched,
    //   no JWT is validated, and CompositeAuthFilter skips the Keycloak hop.
    $keycloakEnabled    = $env('KEYCLOAK_ENABLED', '0') !== '0';
    $tokenProvider      = null;
    $jwtValidator       = null;
    $kcStaleThreshold   = (int) $env('KEYCLOAK_STALE_THRESHOLD_SECS', '900');
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
                'KEYCLOAK_SHM_DIR'            => $env('KEYCLOAK_SHM_DIR', ''),
                'KEYCLOAK_TOKEN_REFRESH_SKEW' => $env('KEYCLOAK_TOKEN_REFRESH_SKEW', '30'),
            ];
            $kcState = KeycloakBootstrap::fromEnv($kcEnvBag, writable: false);
            $tokenProvider = $kcState->tokenProvider;
            $jwtValidator  = $kcState->jwtValidator;

            fwrite(STDOUT, sprintf(
                "[worker] Keycloak enabled — client_id=%s, issuer=%s, realm=%s, shm=%s\n",
                $kcState->clientId,
                $kcState->issuer,
                $kcState->realm,
                $kcState->shmDir,
            ));

            if ($kcState->reader->isStale($kcStaleThreshold)) {
                fwrite(STDERR, sprintf(
                    "[worker] WARN: Keycloak SHM is stale at boot — last update %ds ago (threshold %ds)\n",
                    $kcState->reader->secondsSinceLastUpdate(),
                    $kcStaleThreshold,
                ));
            }

            // Audience registry — KeycloakBearerFilter consults this to
            // tag the X-Keycloak-Audience header per outbound URL.
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
            // Don't exit here — SPIFFE may still be operating. Fail-soft so
            // a misconfigured Keycloak doesn't take out the SPIFFE pipeline.
            $keycloakEnabled = false;
            $tokenProvider = null;
            $jwtValidator = null;
        }
    } else {
        fwrite(STDOUT, "[worker] Keycloak disabled via KEYCLOAK_ENABLED=0\n");
    }

    // ── Global filter — composite of SPIFFE + Keycloak ─────────
    //   Each sub-filter is internally guarded by its own enabled flag so
    //   the composite is safe regardless of which combination of stacks
    //   is active.
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
        $lsvidSigner,
        $downstreamAudience,
        $lsvidRequired,
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
        $requestValidator ?? $lsvidValidator,
        $lsvidRequired,
        $spiffeEnabled,
        $jwtValidator,
        $kcSelfAudience,
        $keycloakEnabled,
    );
    $eventConsumer = new EventConsumer(
        $eventBus,
        $eventValidator ?? $lsvidValidator,
        $lsvidRequired,
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

    // Run 3: downstream HTTP keep-alive (mTLS-off modes only).
    //
    // The saga's downstream calls go through Anser's singleton Guzzle client
    // (ServiceList::getHttpClient). By default the worker registers no global
    // handler, so each call relies on cURL's implicit reuse. Register a base
    // handler that wraps Guzzle's DEFAULT handler (sync + async safe — NOT the
    // Swow HTTPConnectionManager, which needs a coroutine scheduler this
    // synchronous worker doesn't run) and pins TCP keepalive + connection reuse
    // so bursts of saga steps don't churn fresh TCP/handshakes.
    //
    // Gated off when mTLS is on (per-request client certs must never share a
    // reused socket) or when DOWNSTREAM_HTTP_KEEPALIVE=0. Zero-security-loss:
    // transport reuse only; every request still carries its own Bearer + X-LSVID
    // headers, validated downstream regardless of socket reuse.
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

    // Run 5: tunable AMQP prefetch (throughput vs p99 trade-off).
    //
    // prefetch=1 (default): aligned with EXPERIMENT.md spec. Higher prefetch
    // (e.g. 8) boosts throughput but inflates p99 — one slow message blocks up
    // to N already-buffered messages behind it. Saga tail latency dominates the
    // claim we're benchmarking against Linkerd 1.x, so the default stays 1.
    //
    // AMQP_PREFETCH lets an experiment round sweep this lever (1 vs 8 @20k) and
    // record the saga-complete throughput vs p99 trade-off in run notes. Worker
    // concurrency is still scaled by container replicas — this only widens the
    // in-flight window for a single synchronous consumer. Zero-security-loss:
    // pure flow control; every message still runs the full triple validation.
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
