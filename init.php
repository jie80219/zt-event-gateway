<?php

require_once __DIR__ . '/vendor/autoload.php';

use SDPMlab\Anser\Service\ServiceList;

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

$spiffeEnabled = $env('SPIFFE_ENABLED', '1') !== '0';
$isHttps = $spiffeEnabled && $env('SPIFFE_MTLS_ENABLED', '0') === '1';
$defaultHost = $env('SERVICE_HOST', 'localhost');
$mtlsPort = (int) $env('MTLS_PORT', '8443');
$httpPort  = 8080;  // RoadRunner internal HTTP port

ServiceList::addLocalService(
    name: "ProductionService",
    address: $env('PRODUCTION_SERVICE_HOST', $defaultHost),
    port: $isHttps ? $mtlsPort : (int) $env('PRODUCTION_SERVICE_PORT', '8083'),
    isHttps: $isHttps
);

ServiceList::addLocalService(
    name: "UserService",
    address: $env('USER_SERVICE_HOST', $defaultHost),
    port: $isHttps ? $mtlsPort : (int) $env('USER_SERVICE_PORT', '8084'),
    isHttps: $isHttps
);

ServiceList::addLocalService(
    name: "OrderService",
    address: $env('ORDER_SERVICE_HOST', $defaultHost),
    port: $isHttps ? $mtlsPort : (int) $env('ORDER_SERVICE_PORT', '8082'),
    isHttps: $isHttps
);

define("LOG_PATH", __DIR__ . DIRECTORY_SEPARATOR ."Logs" . DIRECTORY_SEPARATOR);
