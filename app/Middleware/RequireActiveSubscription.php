<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\TenantModel;

/**
 * Expired/disabled shops stay readable but read-only: users can still log in,
 * read and export; every WRITE (non-GET request) is rejected with
 * SUBSCRIPTION_EXPIRED. Add this middleware to every state-changing route.
 */
final class RequireActiveSubscription
{
    public static function handle(): void
    {
        $tenant = TenantContext::get();
        if ($tenant === null) {
            $tenant = (new TenantModel())->findById(Auth::tenantId());
        }
        if ($tenant === null) {
            return; // no tenant context (e.g. super-admin) — nothing to enforce here
        }
        if (Request::method() === 'GET') {
            return; // reads are always allowed
        }
        if (($tenant['subscription_status'] ?? 'active') !== 'active') {
            throw new ApiException('SUBSCRIPTION_EXPIRED', 403);
        }
    }
}
