<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Auth;

/**
 * Any authenticated session (shop user OR super-admin) with valid lifetime.
 * Tenant-only and admin-only checks happen in RequireTenant/RequireSuperAdmin.
 */
final class RequireAuth
{
    public static function handle(): void
    {
        if (!Auth::isLoggedIn() && !Auth::isSuperAdmin()) {
            throw new ApiException('UNAUTHENTICATED', 401);
        }
        Auth::checkFreshness(Auth::isLoggedIn() ? Auth::SCOPE_USER : Auth::SCOPE_ADMIN);
    }
}
