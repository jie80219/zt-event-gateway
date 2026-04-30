<?php

require_once __DIR__ . '/vendor/autoload.php';

use SDPMlab\Anser\Service\ServiceList;

$envOr = static function (string $key, string $default): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

$envIntOr = static function (string $key, int $default) use ($envOr): int {
    return (int) $envOr($key, (string) $default);
};

ServiceList::addLocalService(
    name: 'ProductionService',
    address: $envOr('PRODUCTION_SERVICE_HOST', 'production-service'),
    port: $envIntOr('PRODUCTION_SERVICE_PORT', 8080),
    isHttps: false,
);

ServiceList::addLocalService(
    name: 'UserService',
    address: $envOr('USER_SERVICE_HOST', 'user-service'),
    port: $envIntOr('USER_SERVICE_PORT', 8080),
    isHttps: false,
);

ServiceList::addLocalService(
    name: 'OrderService',
    address: $envOr('ORDER_SERVICE_HOST', 'order-service'),
    port: $envIntOr('ORDER_SERVICE_PORT', 8080),
    isHttps: false,
);

define('LOG_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR);
