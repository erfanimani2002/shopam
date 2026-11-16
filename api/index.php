<?php

declare(strict_types=1);

/**
 * API front controller. The frontend calls /api/?action=<action>.
 *
 * Step 1 registers only health-check actions. Auth (login/logout/CSRF),
 * products, orders, reports, accounts, analytics, settings and the
 * super-admin panel routes are registered here in later steps.
 */

use App\Core\Auth;
use App\Core\Bootstrap;
use App\Core\Database;
use App\Core\Response;
use App\Core\Router;

require dirname(__DIR__) . '/app/Core/Bootstrap.php';

Bootstrap::start(dirname(__DIR__));
Auth::boot();

$router = new Router();

// Health check: does the API answer at all?
$router->add('ping', fn (): array => [
    'php'  => PHP_VERSION,
    'time' => gmdate('c'),
]);

// Health check: is the database reachable?
$router->add('db_check', function (): array {
    $v = Database::get()->value('SELECT VERSION()');
    return ['mysql' => (string) $v];
});

// --- step 2+: auth, products, orders, reports, accounts, analytics, settings, super-admin ---

$router->dispatch();
