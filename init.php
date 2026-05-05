<?php

require_once __DIR__ . '/vendor/autoload.php';

use SDPMlab\Anser\Service\ServiceList;

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

$defaultHost = $env('SERVICE_HOST', 'localhost');
$linkerdHost = $env('LINKERD_HOST', $defaultHost);
$linkerdPort = $env('LINKERD_PORT', '4140');

ServiceList::addLocalService(
    name: "ProductionService",
    address: $env('PRODUCTION_SERVICE_HOST', $linkerdHost),
    port: (int) $env('PRODUCTION_SERVICE_PORT', $linkerdHost === $defaultHost ? '8083' : $linkerdPort),
    isHttps: false,
);

ServiceList::addLocalService(
    name: "UserService",
    address: $env('USER_SERVICE_HOST', $linkerdHost),
    port: (int) $env('USER_SERVICE_PORT', $linkerdHost === $defaultHost ? '8084' : $linkerdPort),
    isHttps: false,
);

ServiceList::addLocalService(
    name: "OrderService",
    address: $env('ORDER_SERVICE_HOST', $linkerdHost),
    port: (int) $env('ORDER_SERVICE_PORT', $linkerdHost === $defaultHost ? '8082' : $linkerdPort),
    isHttps: false,
);

define("LOG_PATH", __DIR__ . DIRECTORY_SEPARATOR ."Logs" . DIRECTORY_SEPARATOR);
