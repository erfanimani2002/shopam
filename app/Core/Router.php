<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Minimal action router for the JSON API. Controllers are registered as
 * action => handler. Auth/CSRF middleware is added in step 2 with the
 * auth routes; the router only routes and formats errors.
 */
final class Router
{
    /** @var array<string, callable> */
    private array $routes = [];

    public function add(string $action, callable $handler): void
    {
        $this->routes[$action] = $handler;
    }

    public function dispatch(): void
    {
        $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
        if ($action === '' || !isset($this->routes[$action])) {
            Response::error('unknown_action', 404);
        }

        try {
            $result = ($this->routes[$action])();
            if (is_array($result)) {
                Response::ok($result);
            }
        } catch (Throwable $e) {
            $debug = (bool) Config::get('debug', false);
            Response::error('server_error', 500, [
                'message' => $debug ? $e->getMessage() : null,
            ]);
        }
    }
}
