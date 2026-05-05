<?php
namespace Filters;

use SDPMlab\Anser\Service\FilterInterface;
use SDPMlab\Anser\Service\ActionInterface;

class LinkerdHostHeaderFilter implements FilterInterface
{
    public function beforeCallService(ActionInterface $action)
    {
        if (getenv('LINKERD_ENABLED') !== '1') {
            return;
        }

        $serviceName = $this->extractServiceName($action);
        if ($serviceName === null) {
            return;
        }

        $headers = $action->getOption('headers');
        if (!is_array($headers)) {
            $headers = [];
        }
        $headers['Host'] = $serviceName;
        $action->addOption('headers', $headers);
    }

    public function afterCallService(ActionInterface $action)
    {
        // no-op
    }

    private function extractServiceName(ActionInterface $action): ?string
    {
        try {
            $ref = new \ReflectionObject($action);
            if (!$ref->hasProperty('serviceName')) {
                return null;
            }
            $prop = $ref->getProperty('serviceName');
            $prop->setAccessible(true);
            $name = $prop->getValue($action);
            return is_string($name) && $name !== '' ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
