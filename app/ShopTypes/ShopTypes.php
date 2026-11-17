<?php

declare(strict_types=1);

namespace App\ShopTypes;

use App\Core\Database;
use InvalidArgumentException;

/**
 * Registry of valid shop types. THE single source of truth for tenants.shop_type
 * (the DB column is VARCHAR — see schema.sql). v1 ships only 'cosmetics';
 * adding a type = one ShopTypePreset subclass + one entry in PRESETS.
 * Everything else (controllers, models, tests) goes through this class and
 * must never hardcode a type string.
 */
final class ShopTypes
{
    /** Default applied when a caller passes an empty/unknown type code. */
    public const DEFAULT_CODE = 'cosmetics';

    /** @var array<string, class-string<ShopTypePreset>> */
    private const PRESETS = [
        'cosmetics' => CosmeticsPreset::class,
    ];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::PRESETS);
    }

    public static function exists(string $code): bool
    {
        return isset(self::PRESETS[$code]);
    }

    /** Trim/lowercase a client-supplied code; unknown or empty -> default. */
    public static function normalize(?string $code): string
    {
        $code = strtolower(trim((string) $code));
        return self::exists($code) ? $code : self::DEFAULT_CODE;
    }

    /** @return ShopTypePreset */
    public static function preset(string $code): ShopTypePreset
    {
        $code = strtolower(trim($code));
        $class = self::PRESETS[$code] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("Unknown shop type: {$code}");
        }
        return new $class();
    }

    /**
     * Seed preset-owned defaults (field_schema, expense categories, payment
     * methods) for a tenant. Convenience wrapper around the preset itself.
     */
    public static function applyToTenant(string $code, int $tenantId, ?Database $db = null): void
    {
        self::preset($code)->applyToTenant($tenantId, $db);
    }
}
