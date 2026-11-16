<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session-based auth. The authenticated tenant context lives ONLY here:
 * models get tenant_id from Auth::tenantId(), never from client input.
 *
 * Cookie policy: HttpOnly, Secure (automatically when HTTPS), SameSite=Lax.
 * Session ID is regenerated on login to prevent session fixation.
 */
final class Auth
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        session_name((string) Config::get('session.name', 'shopam_sess'));
        session_set_cookie_params([
            'lifetime' => 0, // session cookie; idle timeout handled in step 2
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https');
    }

    /** Login a tenant user (owner or staff). */
    public static function loginUser(array $user): void
    {
        self::boot();
        session_regenerate_id(true); // session fixation defense
        $_SESSION['user_id']     = (int) $user['id'];
        $_SESSION['tenant_id']   = (int) $user['tenant_id'];
        $_SESSION['role']        = (string) $user['role'];
        $_SESSION['can_see_cost'] = (bool) $user['can_see_cost'];
        $_SESSION['auth_time']   = time();
        unset($_SESSION['super_admin_id']);
    }

    /** Login the platform super-admin (no tenant). */
    public static function loginSuperAdmin(array $admin): void
    {
        self::boot();
        session_regenerate_id(true);
        $_SESSION['super_admin_id'] = (int) $admin['id'];
        $_SESSION['role']           = 'super_admin';
        unset($_SESSION['user_id'], $_SESSION['tenant_id'], $_SESSION['can_see_cost']);
    }

    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        self::$booted = false;
    }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function isSuperAdmin(): bool
    {
        return isset($_SESSION['super_admin_id']);
    }

    /** tenant_id from the authenticated session. 0 when not in a tenant context. */
    public static function tenantId(): int
    {
        return (int) ($_SESSION['tenant_id'] ?? 0);
    }

    public static function userId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    public static function role(): string
    {
        return (string) ($_SESSION['role'] ?? '');
    }

    /** Permission flag: staff can be allowed by the owner to see buy price / profit. */
    public static function canSeeCost(): bool
    {
        return self::role() === 'owner' || (bool) ($_SESSION['can_see_cost'] ?? false);
    }
}
