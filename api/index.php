<?php

declare(strict_types=1);

/**
 * API front controller. The frontend calls /api/?action=<action>.
 *
 * Step 2 registers the auth routes (tenant + super-admin), csrf and
 * health checks. Product/order/report/admin-panel routes come later.
 */

use App\Controllers\AuthController;
use App\Controllers\SuperAdminAuthController;
use App\Controllers\SystemController;
use App\Core\Bootstrap;
use App\Core\Router;

require dirname(__DIR__) . '/app/Core/Bootstrap.php';

Bootstrap::start(dirname(__DIR__));

$router = new Router();

// Health checks (no auth)
$router->add('ping', [SystemController::class, 'ping']);
$router->add('db_check', [SystemController::class, 'db_check']);

// Tenant auth (shop users; sessions in the "shopam_sess" namespace)
$router->add('csrf', [AuthController::class, 'csrf']);                       // GET (scope=admin param for admin pages)
$router->add('login', [AuthController::class, 'login'], ['csrf']);           // POST
$router->add('logout', [AuthController::class, 'logout'], ['auth', 'csrf']); // POST
$router->add('me', [AuthController::class, 'me'], ['auth', 'tenant']);       // GET
$router->add('change_password', [AuthController::class, 'change_password'], ['auth', 'csrf']); // POST

// Super-admin auth (SEPARATE "shopam_admin_sess" namespace)
$router->add('admin_login', [SuperAdminAuthController::class, 'login'], ['csrf']);   // POST
$router->add('admin_logout', [SuperAdminAuthController::class, 'logout'], ['admin', 'csrf']); // POST
$router->add('admin_me', [SuperAdminAuthController::class, 'me'], ['admin']);        // GET

// --- step 3+: products, orders, reports, accounts, analytics, settings, admin panel ---

$router->dispatch();
