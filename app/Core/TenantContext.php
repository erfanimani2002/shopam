<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Per-request tenant context, loaded by RequireTenant middleware (and set at
 * login). The tenant id used by BaseModel comes from the session
 * (Auth::tenantId()); this context carries the full tenant row for display
 * (name, subscription status, ...) and for the subscription middleware.
 */
final class TenantContext
{
    private static ?array $tenant = null;

    /** @param array<string, mixed> $tenant */
    public static function set(array $tenant): void
    {
        self::$tenant = $tenant;
    }

    /** @return array<string, mixed>|null */
    public static function get(): ?array
    {
        return self::$tenant;
    }

    public static function reset(): void
    {
        self::$tenant = null;
    }
}
