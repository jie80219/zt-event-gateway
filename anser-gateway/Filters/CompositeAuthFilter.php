<?php

declare(strict_types=1);

namespace Filters;

use SDPMlab\Anser\Service\ActionInterface;
use SDPMlab\Anser\Service\FilterInterface;

/**
 * Composite global filter that wires SPIFFE/LSVID and Keycloak filters
 * onto the same outbound HTTP call.
 *
 * Anser's {@see \SDPMlab\Anser\Service\ActionFilter::setGlobalFilter()} only
 * accepts a single class. To run both auth stacks side-by-side we register
 * this composite which delegates to each sub-filter in turn. Either filter
 * is a no-op when its respective stack is disabled (no signer / no token
 * provider in the registry), so this composite is safe to use even when
 * only one stack is active.
 *
 * Order: SPIFFE/LSVID runs first (it may also inject mTLS Guzzle options),
 * then Keycloak appends its Authorization header.
 */
class CompositeAuthFilter implements FilterInterface
{
    private SpiffeLsvidFilter $spiffeFilter;
    private KeycloakBearerFilter $keycloakFilter;

    public function __construct()
    {
        $this->spiffeFilter = new SpiffeLsvidFilter();
        $this->keycloakFilter = new KeycloakBearerFilter();
    }

    public function beforeCallService(ActionInterface $action): void
    {
        $spiffeEnabled   = (getenv('SPIFFE_ENABLED') ?: '1') !== '0';
        $keycloakEnabled = (getenv('KEYCLOAK_ENABLED') ?: '0') !== '0';

        if ($spiffeEnabled) {
            $this->spiffeFilter->beforeCallService($action);
        }
        if ($keycloakEnabled) {
            $this->keycloakFilter->beforeCallService($action);
        }
    }

    public function afterCallService(ActionInterface $action): void
    {
        // Both sub-filters are no-ops in afterCallService today; call them
        // anyway so we behave correctly if either grows post-call logic.
        $this->spiffeFilter->afterCallService($action);
        $this->keycloakFilter->afterCallService($action);
    }
}
