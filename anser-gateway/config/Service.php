<?php

// Service bootstrap file for Anser-Gateway startup.
// Registered by GatewayWorker::onWorkerStart (system/Worker/GatewayWorker.php).

use SDPMlab\Anser\Service\ServiceList;

$linkerdHost = getenv('LINKERD_HOST');
$linkerdPortRaw = getenv('LINKERD_PORT');
$linkerdEnabled = ($linkerdHost !== false && $linkerdHost !== '');

$productHost = getenv('PRODUCTION_SERVICE_HOST');
if ($productHost === false || $productHost === '') {
    $productHost = $linkerdEnabled ? $linkerdHost : '10.1.1.207';
}

$productPortRaw = getenv('PRODUCTION_SERVICE_PORT');
if ($productPortRaw === false || $productPortRaw === '') {
    $productPort = $linkerdEnabled
        ? (int) ($linkerdPortRaw !== false && $linkerdPortRaw !== '' ? $linkerdPortRaw : 4140)
        : 8083;
} else {
    $productPort = (int) $productPortRaw;
}

$productHttps = filter_var(getenv('PRODUCTION_SERVICE_HTTPS') ?: '0', FILTER_VALIDATE_BOOLEAN);

ServiceList::addLocalService(
    'product_service',
    $productHost,
    $productPort,
    $productHttps,
);

fwrite(STDOUT, sprintf(
    "[gateway] registered product_service => %s://%s:%d\n",
    $productHttps ? 'https' : 'http',
    $productHost,
    $productPort,
));
