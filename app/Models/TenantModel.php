<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\ShopTypes\ShopTypes;

/**
 * Tenants are a platform-level table (no tenant_id column), so this model
 * does NOT extend BaseModel. Lookups here are pre-auth (login by slug) or
 * middleware-level (context load by session tenant id). Shop creation is a
 * super-admin action and always seeds the shop type preset's defaults.
 */
final class TenantModel
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Create a shop (super-admin action) and seed its preset-owned defaults:
     * field_schema, default expense categories, default payment methods, plus
     * an empty shop_settings row. The shop type is validated against the code
     * registry (empty/unknown -> default); field_schema is preset-owned and
     * never accepted from caller input.
     *
     * @param array<string, mixed> $data tenants columns (name, slug, plan_id, ...)
     * @return int new tenant id
     */
    public function create(array $data): int
    {
        $type = ShopTypes::normalize((string) ($data['shop_type'] ?? ''));
        $row = $data;
        $row['shop_type'] = $type;
        unset($row['field_schema']);

        $tenantId = (int) $this->db->insert('tenants', $row);
        ShopTypes::applyToTenant($type, $tenantId, $this->db);
        $this->db->insert('shop_settings', ['tenant_id' => $tenantId]); // schema defaults
        return $tenantId;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->one(
            'SELECT id, name, slug, shop_type, subscription_status, subscription_expires_at
             FROM tenants WHERE slug = ?',
            [$slug]
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->one(
            'SELECT id, name, slug, shop_type, subscription_status, subscription_expires_at
             FROM tenants WHERE id = ?',
            [$id]
        );
    }

    /** Days until expiry; null = unlimited; 0 = expired. */
    public static function daysLeft(?string $expiresAtUtc): ?int
    {
        if ($expiresAtUtc === null || $expiresAtUtc === '') {
            return null;
        }
        $ts = strtotime($expiresAtUtc);
        if ($ts === false) {
            return 0;
        }
        return max(0, (int) ceil(($ts - time()) / 86400));
    }
}
