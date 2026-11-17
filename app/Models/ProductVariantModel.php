<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Product variants (size + color with per-variant stock). The table and code
 * path are kept for future shop types (cosmetics has variants OFF by default,
 * see app/ShopTypes/); no UI ships in v1. Same tenant-scoping rules as every
 * other model: tenant_id comes from the session only.
 */
final class ProductVariantModel extends BaseModel
{
    protected string $table = 'product_variants';

    /** @return list<array<string, mixed>> */
    public function listForProduct(int $productId): array
    {
        return $this->scoped(
            'SELECT id, product_id, size, color, stock
             FROM product_variants
             WHERE tenant_id = :tenant_id AND product_id = :pid
             ORDER BY size ASC, color ASC',
            ['pid' => $productId]
        );
    }

    /** Stock check used by the order flow (step 4). */
    public function stockOf(int $variantId): ?int
    {
        $row = $this->find($variantId);
        return $row === null ? null : (int) $row['stock'];
    }
}
