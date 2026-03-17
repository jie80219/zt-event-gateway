<?php
namespace Config;

use AnserGateway\Router\RouteCollector;

return function (RouteCollector $route) {
    // Canonical APIs
    $route->get('/api/health', [\App\Controllers\HeartBeat::class, 'index']);
    $route->post('/api/orders', [\App\Controllers\Order::class, 'create']);

    // Optional convenience routes
    $route->get('/', [\App\Controllers\HeartBeat::class, 'index']);
    $route->get('/products', [\App\Controllers\Product::class, 'products']);
};
