<?php

declare(strict_types=1);

namespace Filters;

use SDPMlab\Anser\Service\ActionInterface;
use SDPMlab\Anser\Service\FilterInterface;
use SDPMlab\ZtEventGateway\Vault\Tls\VaultMtlsRegistry;

/**
 * Anser global filter that injects Vault PKI mTLS credentials into every
 * outgoing HTTP service call.
 *
 * Service identity is transport-layer only: the Guzzle cert/ssl_key/verify
 * options come from the worker's Vault-issued X.509 (rendered to disk by the
 * vault-agent sidecar), so the TLS handshake proves the caller's identity.
 * There is no application-layer token (no LSVID, no JWT) — the mutual-TLS
 * handshake is the whole story.
 *
 * Registered globally via ActionFilter::setGlobalFilter() in bin/worker.php
 * so all Anser SimpleService calls (OrderService, ProductionService,
 * UserService) automatically carry the client certificate.
 */
class VaultMtlsFilter implements FilterInterface
{
    public function beforeCallService(ActionInterface $action): void
    {
        $tlsCtx = VaultMtlsRegistry::get();
        if ($tlsCtx === null) {
            return;
        }

        try {
            $guzzleOpts = $tlsCtx->forGuzzle();
            foreach ($guzzleOpts as $key => $value) {
                $action->addOption($key, $value);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[vault-mtls-filter] mTLS injection failed: %s\n",
                $e->getMessage(),
            ));
        }
    }

    public function afterCallService(ActionInterface $action): void
    {
        // no-op
    }
}
