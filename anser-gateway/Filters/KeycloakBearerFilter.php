<?php

declare(strict_types=1);

namespace Filters;

use SDPMlab\Anser\Service\ActionInterface;
use SDPMlab\Anser\Service\FilterInterface;
use Keycloak\AudienceRegistry;
use Keycloak\TokenProviderRegistry;

/**
 * Anser global filter that injects the service-account bearer token into
 * every outgoing HTTP service call.
 *
 * Registered globally via ActionFilter::setGlobalFilter() in bin/worker.php
 * so every Anser SimpleService call (OrderService, ProductionService,
 * UserService) automatically carries Authorization: Bearer <jwt>.
 *
 * The token is minted for *this* service (Client Credentials grant) with
 * aud = downstream service's Keycloak client_id resolved via AudienceRegistry.
 * For the simple setup used here we mint one token per Keycloak client and
 * include it on every outbound call; the downstream JwtValidator checks
 * iss + signature + aud on receive.
 */
class KeycloakBearerFilter implements FilterInterface
{
    public function beforeCallService(ActionInterface $action): void
    {
        $provider = TokenProviderRegistry::tryGet();
        if ($provider === null) {
            return;
        }

        try {
            $jwt = $provider->getAccessToken();
        } catch (\Throwable $e) {
            fwrite(STDERR, "[keycloak-bearer-filter] token fetch failed: {$e->getMessage()}\n");
            return;
        }

        $headers = $action->getOption('headers');
        if (!is_array($headers)) {
            $headers = [];
        }
        $headers['Authorization'] = 'Bearer ' . $jwt;

        try {
            $url = $action->getRequestSetting()->url ?? '';
            if ($url !== '') {
                $audience = AudienceRegistry::resolve($url);
                if ($audience !== null) {
                    $headers['X-Keycloak-Audience'] = $audience;
                }
            }
        } catch (\Throwable) {
            // getRequestSetting() may not be available in all contexts.
        }

        $action->addOption('headers', $headers);
    }

    public function afterCallService(ActionInterface $action): void
    {
        // no-op
    }
}
