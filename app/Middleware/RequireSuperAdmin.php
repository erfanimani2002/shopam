<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Auth;

/**
 * Requires the super-admin session namespace. Tenant sessions never pass,
 * even if one somehow exists in the same request.
 */
final class RequireSuperAdmin
{
    public static function handle(): void
    {
        if (!Auth::isSuperAdmin()) {
            throw new ApiException('UNAUTHENTICATED', 401);
        }
        if (Auth::isLoggedIn()) {
            throw new ApiException('FORBIDDEN', 403); // namespace mixing guard
        }
        Auth::checkFreshness(Auth::SCOPE_ADMIN);
    }
}
