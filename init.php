<?php

require_once __DIR__ . '/vendor/autoload.php';

use SDPMlab\Anser\Service\ServiceList;

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

$defaultHost = $env('SERVICE_HOST', 'localhost');

ServiceList::addLocalService(
    name: "ProductionService",
    address: $env('PRODUCTION_SERVICE_HOST', $defaultHost),
    port: (int) $env('PRODUCTION_SERVICE_PORT', '8081'),
    isHttps: false
);

ServiceList::addLocalService(
    name: "UserService",
    address: $env('USER_SERVICE_HOST', $defaultHost),
    port: (int) $env('USER_SERVICE_PORT', '8083'),
    isHttps: false
);

ServiceList::addLocalService(
    name: "OrderService",
    address: $env('ORDER_SERVICE_HOST', $defaultHost),
    port: (int) $env('ORDER_SERVICE_PORT', '8082'),
    isHttps: false
);

define("LOG_PATH", __DIR__ . DIRECTORY_SEPARATOR ."Logs" . DIRECTORY_SEPARATOR);
