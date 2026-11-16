<?php

declare(strict_types=1);

namespace App\Models;

/**
 * EXAMPLE MODEL showing how models use the central tenant-scoping helper.
 * Every query writes its SQL with :tenant_id and lets BaseModel bind it
 * from the authenticated session.
 */
final class ProductModel extends BaseModel
{
    protected string $table = 'products';

    /** Search non-archived products by name, scoped to the session tenant. */
    public function search(string $query, int $limit = 100): array
    {
        return $this->scoped(
            "SELECT * FROM `products`
             WHERE tenant_id = :tenant_id
               AND archived = 0
               AND name LIKE :q
             ORDER BY name
             LIMIT " . (int) $limit,
            ['q' => '%' . $query . '%']
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function allActive(): array
    {
        return $this->scoped(
            "SELECT * FROM `products`
             WHERE tenant_id = :tenant_id AND archived = 0
             ORDER BY name"
        );
    }
}
