<?php

declare(strict_types=1);

namespace Filters;

use SDPMlab\Anser\Service\ActionInterface;
use SDPMlab\Anser\Service\FilterInterface;
use SDPMlab\LSVID\LSVIDContext;
use SDPMlab\LSVID\LSVIDException;
use SDPMlab\ZtEventGateway\Spiffe\LSVIDSignerRegistry;
use SDPMlab\ZtEventGateway\Spiffe\LSVIDValidatorRegistry;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeAudienceRegistry;
use SDPMlab\ZtEventGateway\Spiffe\SpiffeMtlsRegistry;

/**
 * Anser global filter that injects SPIFFE identity into every outgoing
 * HTTP service call:
 *
 *   1. X-LSVID header — extends the current LSVID chain with a new level
 *      whose `aud` is the target service's SPIFFE ID, so downstream
 *      services can verify the full nested chain with correct audience.
 *   2. mTLS options  — Guzzle cert/ssl_key/verify from the worker's SVID so
 *      the TLS handshake proves the caller's SPIFFE identity.
 *
 * Registered globally via ActionFilter::setGlobalFilter() in
 * bin/worker.php so all Anser SimpleService calls (OrderService,
 * ProductionService, UserService) automatically carry identity.
 */
class SpiffeLsvidFilter implements FilterInterface
{
    public function beforeCallService(ActionInterface $action): void
    {
        // 1. Extend the LSVID chain for the target service.
        //
        //    The current LSVIDContext holds the raw LSVID from the inbound
        //    RabbitMQ event (e.g. L1 with aud=worker). We MUST NOT forward
        //    it as-is — its audience targets this worker, not the downstream
        //    service. Instead we extend the chain:
        //
        //      L1(aud=worker) → signer->extend(L1, aud=service) → L2(aud=service)
        //
        //    The service then validates L2 with expectedAudience = its own
        //    SPIFFE ID and can walk the full chain (L0 → L1 → L2).
        $rawLsvid = LSVIDContext::current();
        $signer = LSVIDSignerRegistry::get();
        $validator = LSVIDValidatorRegistry::get();
        $failClosed = (getenv('LSVID_REQUIRED') === '1');

        if ($rawLsvid !== null && $signer !== null) {
            $targetAudience = $this->resolveAudience($action);

            // ── Defence in depth ─────────────────────────────────
            //   Re-validate the prior token (L1 produced by the worker's
            //   EventConsumer, whose audience points at *this* worker)
            //   before we extend it. If the context was somehow polluted
            //   we refuse to sign an L2 that embeds a bad L1 — when
            //   LSVID_REQUIRED=1 this also blocks the downstream call.
            if ($validator !== null) {
                try {
                    $workerSpiffeId = getenv('SPIFFE_ID') ?: null;
                    $validator->validate(
                        $rawLsvid,
                        expectedAudience: $workerSpiffeId ?: null,
                    );
                } catch (LSVIDException $e) {
                    fwrite(STDERR, sprintf(
                        "[spiffe-lsvid-filter] prior LSVID failed re-validation: %s\n",
                        $e->getMessage(),
                    ));
                    if ($failClosed) {
                        throw new \RuntimeException(
                            'SpiffeLsvidFilter: prior LSVID failed re-validation — refusing to extend.',
                            0,
                            $e,
                        );
                    }
                    return; // degraded: skip header injection entirely
                }
            }

            if ($targetAudience !== null) {
                try {
                    $extended = $signer->extend(
                        priorRawToken: $rawLsvid,
                        audience: $targetAudience,
                        extraClaims: [
                            'level' => 'http-call',
                        ],
                    );

                    $existing = $action->getOption('headers') ?? [];
                    if (is_array($existing)) {
                        $existing['X-LSVID'] = $extended->raw;
                        $action->addOption('headers', $existing);
                    }
                } catch (\Throwable $e) {
                    fwrite(STDERR, sprintf(
                        "[spiffe-lsvid-filter] LSVID extend failed: %s\n",
                        $e->getMessage(),
                    ));
                }
            } else {
                // No audience mapping found — forward raw token as fallback.
                // The downstream service should set LSVID_REQUIRED=0 during
                // the migration period, or add the missing URL mapping.
                fwrite(STDERR, sprintf(
                    "[spiffe-lsvid-filter] no SPIFFE audience mapping for %s — forwarding raw LSVID (audience will mismatch)\n",
                    $action->getRequestSetting()->url ?? '(unknown)',
                ));
                $existing = $action->getOption('headers') ?? [];
                if (is_array($existing)) {
                    $existing['X-LSVID'] = $rawLsvid;
                    $action->addOption('headers', $existing);
                }
            }
        } elseif ($rawLsvid !== null) {
            // Signer not available — forward raw token (degraded mode).
            $existing = $action->getOption('headers') ?? [];
            if (is_array($existing)) {
                $existing['X-LSVID'] = $rawLsvid;
                $action->addOption('headers', $existing);
            }
        }

        // 2. Inject mTLS credentials from the SpiffeMtlsRegistry.
        $tlsCtx = SpiffeMtlsRegistry::get();
        if ($tlsCtx !== null) {
            try {
                $guzzleOpts = $tlsCtx->forGuzzle();
                foreach ($guzzleOpts as $key => $value) {
                    $action->addOption($key, $value);
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, sprintf(
                    "[spiffe-lsvid-filter] mTLS injection failed: %s\n",
                    $e->getMessage(),
                ));
            }
        }
    }

    public function afterCallService(ActionInterface $action): void
    {
        // no-op
    }

    /**
     * Resolve the target service's SPIFFE ID from the Action's request URL.
     */
    private function resolveAudience(ActionInterface $action): ?string
    {
        try {
            $url = $action->getRequestSetting()->url ?? '';
            if ($url !== '') {
                return SpiffeAudienceRegistry::resolve($url);
            }
        } catch (\Throwable) {
            // getRequestSetting() may not be available in all contexts.
        }

        return null;
    }
}
