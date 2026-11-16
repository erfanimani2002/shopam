<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session-based auth with TWO SEPARATE session namespaces:
 *   - shop users:    cookie session.name        (default shopam_sess)
 *   - super admins:  cookie session.admin_name  (default shopam_admin_sess)
 * The namespace is chosen at boot time (the Router picks it from the route's
 * middleware), so a tenant session can never be read as a super-admin session
 * and vice versa.
 *
 * Cookie policy: HttpOnly, Secure (automatically when HTTPS), SameSite=Lax.
 * Session ID is regenerated on every login (fixation defense); logout does a
 * full destroy. Idle timeout + absolute lifetime are enforced per namespace
 * in checkFreshness(). Models get tenant_id ONLY from Auth::tenantId().
 */
final class Auth
{
    public const SCOPE_USER  = 'user';
    public const SCOPE_ADMIN = 'admin';

    private static ?string $scope = null;

    public static function boot(string $scope = self::SCOPE_USER): void
    {
        if (self::$scope === $scope && session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Tests may switch scopes mid-process: close the previous session first.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            $_SESSION = [];
        }
        self::$scope = $scope;
        session_name((string) Config::get(
            $scope === self::SCOPE_ADMIN ? 'session.admin_name' : 'session.name',
            $scope === self::SCOPE_ADMIN ? 'shopam_admin_sess' : 'shopam_sess'
        ));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function scope(): ?string
    {
        return self::$scope;
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
        self::boot(self::SCOPE_USER);
        session_regenerate_id(true); // session fixation defense
        Csrf::rotate();
        $_SESSION['user_id']      = (int) $user['id'];
        $_SESSION['tenant_id']    = (int) $user['tenant_id'];
        $_SESSION['role']         = (string) $user['role'];
        $_SESSION['can_see_cost'] = (bool) $user['can_see_cost'];
        $_SESSION['auth_time']    = time();
        $_SESSION['last_activity'] = time();
        unset($_SESSION['super_admin_id']);
    }

    /** Login the platform super-admin (no tenant). */
    public static function loginSuperAdmin(array $admin): void
    {
        self::boot(self::SCOPE_ADMIN);
        session_regenerate_id(true);
        Csrf::rotate();
        $_SESSION['super_admin_id'] = (int) $admin['id'];
        $_SESSION['role']           = 'super_admin';
        $_SESSION['auth_time']      = time();
        $_SESSION['last_activity']  = time();
        unset($_SESSION['user_id'], $_SESSION['tenant_id'], $_SESSION['can_see_cost']);
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::$scope = null;
            return;
        }
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
        self::$scope = null;
    }

    /**
     * Enforce idle timeout + absolute lifetime for the given scope.
     * Destroys the session and throws SESSION_EXPIRED when exceeded;
     * otherwise refreshes last_activity.
     */
    public static function checkFreshness(string $scope): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $isAdmin = $scope === self::SCOPE_ADMIN;
        $idleMinutes = (int) Config::get(
            $isAdmin ? 'session.admin_idle_minutes' : 'session.user_idle_minutes',
            $isAdmin ? 30 : 120
        );
        $absoluteHours = (int) Config::get(
            $isAdmin ? 'session.admin_absolute_hours' : 'session.user_absolute_hours',
            $isAdmin ? 8 : 720
        );
        $now = time();
        $authTime = (int) ($_SESSION['auth_time'] ?? 0);
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($authTime > 0 && $now - $authTime > $absoluteHours * 3600) {
            self::logout();
            throw new ApiException('SESSION_EXPIRED', 401);
        }
        if ($last > 0 && $now - $last > $idleMinutes * 60) {
            self::logout();
            throw new ApiException('SESSION_EXPIRED', 401);
        }
        $_SESSION['last_activity'] = $now;
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
