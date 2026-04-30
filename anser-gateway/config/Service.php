<?php

// Service bootstrap file for Anser-Gateway startup.
// Registered by GatewayWorker::onWorkerStart (system/Worker/GatewayWorker.php).

use SDPMlab\Anser\Service\ServiceList;

$productHost = getenv('PRODUCTION_SERVICE_HOST');
if ($productHost === false || $productHost === '') {
    $productHost = '10.1.1.207';
}

$productPortRaw = getenv('PRODUCTION_SERVICE_PORT');
$productPort = ($productPortRaw === false || $productPortRaw === '') ? 8083 : (int) $productPortRaw;

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
