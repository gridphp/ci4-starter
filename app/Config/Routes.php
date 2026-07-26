<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->post('/', 'Home::index');

// Added for GridPHP (both GET and POST are required: GridPHP's engine
// posts back to the same endpoint for CRUD actions) e.g.

// for detail grid
$routes->get('detail', 'Home::detail');
$routes->post('detail', 'Home::detail');