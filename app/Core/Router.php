<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AuthController;
use App\Controllers\SuperAdminAuthController;
use App\Controllers\SystemController;
use App\Middleware\RequireActiveSubscription;
use App\Middleware\RequireAuth;
use App\Middleware\RequireSuperAdmin;
use App\Middleware\RequireTenant;
use Throwable;

/**
 * Declarative action router. Routes are registered as
 *   action => [Controller::class, 'method', ['middleware', ...]]
 * and every middleware runs (in order) before the handler.
 *
 * The session namespace is chosen from the middleware list BEFORE the session
 * starts: routes containing 'admin' run in the super-admin session namespace,
 * everything else in the shop-user namespace. Sessions therefore never mix.
 */
final class Router
{
    /** middleware name => [class, handle-method] */
    private const MIDDLEWARE = [
        'csrf'         => [Csrf::class, 'handle'],
        'auth'         => [RequireAuth::class, 'handle'],
        'tenant'       => [RequireTenant::class, 'handle'],
        'admin'        => [RequireSuperAdmin::class, 'handle'],
        'subscription' => [RequireActiveSubscription::class, 'handle'],
    ];

    /** @var array<string, array{0: class-string, 1: string, 2: list<string>}> */
    private array $routes = [];

    /** @param list<string> $middleware */
    public function add(string $action, array $handler, array $middleware = []): void
    {
        $this->routes[$action] = [$handler[0], $handler[1], $middleware];
    }

    public function dispatch(): void
    {
        header('X-Content-Type-Options: nosniff');

        $action = (string) (Request::input('action') ?? '');
        $route = $this->routes[$action] ?? null;
        if ($route === null) {
            Response::error('UNKNOWN_ACTION', 404);
        }
        [$class, $method, $middleware] = $route;

        // Pick the session namespace before the session starts.
        $isAdmin = in_array('admin', $middleware, true)
            || ($action === 'csrf' && Request::input('scope') === 'admin');
        Auth::boot($isAdmin ? Auth::SCOPE_ADMIN : Auth::SCOPE_USER);

        try {
            foreach ($middleware as $name) {
                $mw = self::MIDDLEWARE[$name] ?? null;
                if ($mw === null) {
                    throw new ApiException('SERVER_ERROR', 500, "Unknown middleware: $name");
                }
                $mw[0]::{$mw[1]}();
            }

            $result = [$class, $method]();
            if (is_array($result)) {
                Response::ok($result);
            }
        } catch (ApiException $e) {
            Response::error($e->errorCode, $e->status, $e->getMessage() ?: null);
        } catch (Throwable $e) {
            $debug = (bool) Config::get('debug', false);
            Response::error('SERVER_ERROR', 500, $debug ? $e->getMessage() : null);
        }
    }
}
