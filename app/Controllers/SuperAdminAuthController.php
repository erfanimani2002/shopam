<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Helpers\Audit;
use App\Models\SuperAdminModel;

/**
 * Super-admin auth routes (admin_login / admin_logout / admin_me).
 * These run in the SEPARATE admin session namespace (own cookie), so a
 * tenant session can never call them and vice versa.
 */
final class SuperAdminAuthController
{
    /** POST admin_login. */
    public static function login(): array
    {
        $email = trim((string) (Request::input('email') ?? ''));
        $password = (string) (Request::input('password') ?? '');
        if ($email === '' || $password === '') {
            throw new ApiException('INVALID_INPUT', 422);
        }

        $ip = Request::ip();
        RateLimiter::assertCanAttempt('admin', strtolower($email), $ip);

        $admin = (new SuperAdminModel())->findByEmail($email);
        if ($admin === null || !password_verify($password, (string) $admin['password_hash'])) {
            password_verify($password, AuthController::dummyHash());
            RateLimiter::record('admin', strtolower($email), $ip, false);
            throw new ApiException('AUTH_FAILED', 401);
        }

        RateLimiter::record('admin', strtolower($email), $ip, true);
        Auth::loginSuperAdmin($admin);
        Audit::log('admin.login', 'super_admin', (int) $admin['id']);
        return self::mePayload();
    }

    /** POST admin_logout. */
    public static function logout(): array
    {
        Audit::log('admin.logout', 'super_admin', (int) ($_SESSION['super_admin_id'] ?? 0));
        Auth::logout();
        return ['logged_out' => true];
    }

    /** GET admin_me. */
    public static function me(): array
    {
        return self::mePayload();
    }

    /** @return array<string, mixed> */
    private static function mePayload(): array
    {
        $admin = (new SuperAdminModel())->findById((int) ($_SESSION['super_admin_id'] ?? 0));
        if ($admin === null) {
            throw new ApiException('UNAUTHENTICATED', 401);
        }
        return [
            'admin' => [
                'id'    => (int) $admin['id'],
                'name'  => (string) $admin['name'],
                'email' => (string) $admin['email'],
            ],
            'csrf_token' => Csrf::token(),
        ];
    }
}
