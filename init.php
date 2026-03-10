<?php

require_once './vendor/autoload.php';

use SDPMlab\Anser\Service\ServiceList;

ServiceList::addLocalService(
    name: "ProductionService",
    address: "host.docker.internal",
    port: 8081,
    isHttps: false
);

ServiceList::addLocalService(
    name: "UserService",
    address: "host.docker.internal",
    port: 8083,
    isHttps: false
);

ServiceList::addLocalService(
    name: "OrderService",
    address: "host.docker.internal",
    port: 8082,
    isHttps: false
);

define("LOG_PATH", __DIR__ . DIRECTORY_SEPARATOR ."Logs" . DIRECTORY_SEPARATOR);