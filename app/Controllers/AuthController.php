<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\TenantContext;
use App\Helpers\Audit;
use App\Helpers\Text;
use App\Models\TenantModel;
use App\Models\UserModel;

/**
 * Tenant auth routes: login, logout, me, change_password, csrf.
 * Controllers RETURN arrays; the Router wraps them as { ok, data } and
 * strips cost fields centrally (staff without can_see_cost never see them).
 */
final class AuthController
{
    /** A real hash of an unknown password: verified against on user-not-found
     * logins so response time does not reveal account existence. */
    private static ?string $dummyHash = null;

    public static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash('shopam-dummy-' . bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    /** GET csrf — issue a fresh token for the current session. */
    public static function csrf(): array
    {
        return ['csrf_token' => Csrf::token()];
    }

    /** POST login — shop slug + email/phone + password. */
    public static function login(): array
    {
        $slug = Text::normalizeSlug((string) (Request::input('slug') ?? ''));
        $identifier = Text::normalizePhone(trim((string) (Request::input('identifier') ?? '')));
        $password = (string) (Request::input('password') ?? '');

        if ($slug === '' || $identifier === '' || $password === '') {
            throw new ApiException('INVALID_INPUT', 422);
        }

        $ip = Request::ip();
        RateLimiter::assertCanAttempt($slug, $identifier, $ip);

        $tenant = (new TenantModel())->findBySlug($slug);
        $user = $tenant === null
            ? null
            : (new UserModel())->findByTenantLogin((int) $tenant['id'], $identifier);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            // Equalize response time and never reveal whether the account exists.
            password_verify($password, self::dummyHash());
            RateLimiter::record($slug, $identifier, $ip, false);
            throw new ApiException('AUTH_FAILED', 401);
        }

        RateLimiter::record($slug, $identifier, $ip, true);
        Auth::loginUser($user);
        TenantContext::set($tenant);

        // Transparent rehash when PHP's default algorithm/cost changed.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            (new UserModel())->update((int) $user['id'], [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        Audit::log('auth.login', 'user', (int) $user['id']);
        return self::mePayload();
    }

    /** POST logout — requires CSRF; full session destroy. */
    public static function logout(): array
    {
        Audit::log('auth.logout', 'user', Auth::userId());
        Auth::logout();
        return ['logged_out' => true];
    }

    /** GET me — current user + tenant + subscription state + days left. */
    public static function me(): array
    {
        return self::mePayload();
    }

    /** POST change_password — requires the current password. */
    public static function change_password(): array
    {
        $current = (string) (Request::input('current_password') ?? '');
        $new = (string) (Request::input('new_password') ?? '');
        if ($current === '' || $new === '') {
            throw new ApiException('INVALID_INPUT', 422);
        }
        $minLength = (int) Config::get('auth.min_password_length', 8);
        if (strlen($new) < $minLength) {
            throw new ApiException('WEAK_PASSWORD', 422);
        }
        $user = (new UserModel())->find(Auth::userId());
        if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
            throw new ApiException('INVALID_CREDENTIALS', 403);
        }
        (new UserModel())->update(Auth::userId(), [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
        ]);
        Csrf::rotate(); // token rotation after a sensitive change
        Audit::log('auth.change_password', 'user', Auth::userId());
        return ['changed' => true, 'csrf_token' => Csrf::token()];
    }

    /** Shared payload for login + me. */
    private static function mePayload(): array
    {
        $user = (new UserModel())->find(Auth::userId());
        if ($user === null) {
            throw new ApiException('UNAUTHENTICATED', 401);
        }
        $tenant = TenantContext::get();
        return [
            'user' => [
                'id'           => (int) $user['id'],
                'name'         => (string) $user['name'],
                'email'        => $user['email'],
                'phone'        => $user['phone'],
                'role'         => (string) $user['role'],
                'can_see_cost' => (bool) $user['can_see_cost'],
            ],
            'tenant' => $tenant === null ? null : [
                'id'                   => (int) $tenant['id'],
                'name'                 => (string) $tenant['name'],
                'slug'                 => (string) $tenant['slug'],
                'shop_type'            => (string) $tenant['shop_type'],
                'subscription_status'  => (string) $tenant['subscription_status'],
                'subscription_expires_at' => $tenant['subscription_expires_at'],
                'days_left'            => TenantModel::daysLeft($tenant['subscription_expires_at'] ?? null),
            ],
            'csrf_token' => Csrf::token(),
        ];
    }
}
