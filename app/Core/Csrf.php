<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Per-session CSRF token. Required on every state-changing request
 * (POST/PUT/DELETE); checked in one place: the Router's 'csrf' middleware,
 * which calls Csrf::handle(). Comparison is constant-time (hash_equals).
 */
final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new ApiException('SERVER_ERROR', 500, 'Session not started before CSRF use.');
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    /** New token (called after login and password change). */
    public static function rotate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    /** @internal used by the Router middleware registry */
    public static function handle(): void
    {
        $method = Request::method();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $sent = (string) (Request::input('csrf_token') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            throw new ApiException('CSRF_INVALID', 403);
        }
    }
}
