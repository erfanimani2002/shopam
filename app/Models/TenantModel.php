<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Tenants are a platform-level table (no tenant_id column), so this model
 * does NOT extend BaseModel. Lookups here are pre-auth (login by slug) or
 * middleware-level (context load by session tenant id).
 */
final class TenantModel
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::get();
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
