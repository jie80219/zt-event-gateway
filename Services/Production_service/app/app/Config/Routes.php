<?php

namespace Config;

// Create a new instance of our RouteCollection class.
$routes = Services::routes();

// Load the system's routing file first, so that the app and ENVIRONMENT
// can override as needed.
if (file_exists(SYSTEMPATH . 'Config/Routes.php')) {
    require SYSTEMPATH . 'Config/Routes.php';
}

/*
 * --------------------------------------------------------------------
 * Router Setup
 * --------------------------------------------------------------------
 */
$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Home');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
$routes->setAutoRoute(true);

/*
 * --------------------------------------------------------------------
 * Route Definitions
 * --------------------------------------------------------------------
 */

// We get a performance increase by specifying the default
// route since we don't have to scan directories.
// $routes->get('/', 'Home::index');

$routes->group(
    'api/v1',
    [
        'namespace' => 'App\Controllers\v1',
    ],
    function(\CodeIgniter\Router\RouteCollection $routes)
    {
        //Production
        $routes->get('products', 'ProductionController::index');
        $routes->get('products/(:num)', 'ProductionController::show/$1');
        $routes->post('products', 'ProductionController::create');
        $routes->put('products/(:num)', 'ProductionController::update/$1');
        $routes->delete('products/(:num)', 'ProductionController::delete/$1');

        //Inventory
        $routes->post('inventory/addInventory', 'InventoryController::addInventory');
        $routes->post('inventory/reduceInventory', 'InventoryController::reduceInventory');
        $routes->delete('inventory/(:num)', 'InventoryController::delete/$1');
    }
);

$routes->group(
    'api/vDtm',
    [
        'namespace' => 'App\Controllers\Dtm',
    ],
    function (\CodeIgniter\Router\RouteCollection $routes) {
        //Production
        $routes->post('products/list', 'ProductionController::index');
        $routes->post('products/show', 'ProductionController::show');
        $routes->post('products/create', 'ProductionController::create');
        $routes->post('products/update', 'ProductionController::update');
        $routes->post('products/delete', 'ProductionController::delete');

        //Inventory
        $routes->post('inventory/addInventory', 'InventoryController::addInventory');
        $routes->post('inventory/reduceInventory', 'InventoryController::reduceInventory');
        $routes->post('inventory/delete', 'InventoryController::delete');
    }
);


/*
 * --------------------------------------------------------------------
 * Additional Routing
 * --------------------------------------------------------------------
 *
 * There will often be times that you need additional routing and you
 * need it to be able to override any defaults in this file. Environment
 * based routes is one such time. require() additional route files here
 * to make that happen.
 *
 * You will have access to the $routes object within that file without
 * needing to reload it.
 */
if (file_exists(APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php')) {
    require APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php';
}
