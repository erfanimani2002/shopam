<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\TenantContext;
use App\Models\TenantModel;

/**
 * Requires a shop-user session and loads the tenant row into TenantContext.
 * This is the middleware that "sets the tenant context used by BaseModel"
 * (the id itself always comes from Auth::tenantId(), the session).
 */
final class RequireTenant
{
    public static function handle(): void
    {
        if (!Auth::isLoggedIn()) {
            throw new ApiException('UNAUTHENTICATED', 401);
        }
        Auth::checkFreshness(Auth::SCOPE_USER);
        $tenant = (new TenantModel())->findById(Auth::tenantId());
        if ($tenant === null) {
            throw new ApiException('UNAUTHENTICATED', 401);
        }
        TenantContext::set($tenant);
    }
}
