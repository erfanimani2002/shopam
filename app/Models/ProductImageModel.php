<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use Throwable;

/**
 * Product images: max 5 per product (min of config and plan), position
 * 1 = cover. Position changes (reorder, cover promotion, delete) run in ONE
 * transaction using a temporary offset (+100) so the UNIQUE
 * (tenant_id, product_id, position) key is never violated mid-update:
 *   1. UPDATE all rows of the product: position = position + 100
 *   2. renumber the remaining/new order to 1..n
 */
final class ProductImageModel extends BaseModel
{
    protected string $table = 'product_images';

    /** @return list<array<string, mixed>> ordered by position (1 = cover) */
    public function listForProduct(int $productId): array
    {
        $rows = $this->scoped(
            'SELECT id, product_id, file_name, thumb_name, medium_name, position
             FROM product_images
             WHERE tenant_id = :tenant_id AND product_id = :pid
             ORDER BY position ASC',
            ['pid' => $productId]
        );
        return array_map([self::class, 'decorate'], $rows);
    }

    /**
     * Images for MANY products in ONE query (list views must not do N+1).
     *
     * @param list<int> $productIds
     * @return array<int, list<array<string, mixed>>> product_id => images ordered by position
     */
    public static function imagesForProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }
        $marks = [];
        $params = ['tenant_id' => Auth::tenantId()];
        foreach ($ids as $i => $id) {
            $marks[] = ':p' . $i;
            $params['p' . $i] = $id;
        }
        $rows = Database::get()->all(
            'SELECT id, product_id, file_name, thumb_name, medium_name, position
             FROM product_images
             WHERE tenant_id = :tenant_id AND product_id IN (' . implode(', ', $marks) . ')
             ORDER BY product_id ASC, position ASC',
            $params
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['product_id']][] = self::decorate($row);
        }
        return $map;
    }

    /**
     * Add ready-to-use RELATIVE paths (relative to the app root, e.g.
     * "uploads/3/17/ab12....t.webp"). The frontend prefixes them with its own
     * base URL — no absolute path is ever hardcoded on either side.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decorate(array $row): array
    {
        $tenant = Auth::tenantId();
        $productId = (int) $row['product_id'];
        $row['path'] = 'uploads/' . $tenant . '/' . $productId . '/' . (string) $row['thumb_name'];
        $row['medium_path'] = 'uploads/' . $tenant . '/' . $productId . '/' . (string) $row['medium_name'];
        $row['full_path'] = 'uploads/' . $tenant . '/' . $productId . '/' . (string) $row['file_name'];
        return $row;
    }

    public function countForProduct(int $productId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM product_images WHERE tenant_id = :tenant_id AND product_id = :pid',
            ['tenant_id' => Auth::tenantId(), 'pid' => $productId]
        );
    }

    /** Insert with the next free position (cover = position 1). */
    public function insertAtEnd(int $productId, array $names): int
    {
        $position = $this->countForProduct($productId) + 1;
        return $this->insert([
            'product_id'  => $productId,
            'file_name'   => (string) $names['file_name'],
            'medium_name' => (string) $names['medium_name'],
            'thumb_name'  => (string) $names['thumb_name'],
            'position'    => $position,
        ]);
    }

    /**
     * Apply a NEW full ordering (list of image ids, covering every image of
     * the product exactly once) transactionally via the +100 temp offset.
     *
     * @param list<int> $orderedIds
     * @return bool false when the list doesn't match the product's images exactly
     */
    public function applyOrder(int $productId, array $orderedIds): bool
    {
        $current = $this->listForProduct($productId);
        $currentIds = array_map(static fn (array $r): int => (int) $r['id'], $current);
        $wanted = array_map('intval', $orderedIds);
        sort($currentIds);
        $wantedSorted = $wanted;
        sort($wantedSorted);
        if ($currentIds !== $wantedSorted) {
            return false; // must be a permutation of the existing images
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // 1) push everything far above the legal range
            $this->scopedExec(
                'UPDATE product_images SET position = position + 100
                 WHERE tenant_id = :tenant_id AND product_id = :pid',
                ['pid' => $productId]
            );
            // 2) renumber 1..n in the wanted order
            foreach ($wanted as $index => $imageId) {
                $this->scopedExec(
                    'UPDATE product_images SET position = :pos
                     WHERE tenant_id = :tenant_id AND product_id = :pid AND id = :img',
                    ['pid' => $productId, 'pos' => $index + 1, 'img' => (int) $imageId]
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    /**
     * Delete one image and renumber the rest (the next image becomes the
     * cover). Returns the deleted row (with file names) or null when the id
     * is not an image of the session tenant's product.
     *
     * @return array<string, mixed>|null
     */
    public function deleteAndRenumber(int $productId, int $imageId): ?array
    {
        $row = $this->find($imageId);
        if ($row === null || (int) $row['product_id'] !== $productId) {
            return null;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->delete($imageId);
            // Renumber remaining images by OLD position: +100 offset first.
            $this->scopedExec(
                'UPDATE product_images SET position = position + 100
                 WHERE tenant_id = :tenant_id AND product_id = :pid',
                ['pid' => $productId]
            );
            $remaining = $this->scoped(
                'SELECT id FROM product_images
                 WHERE tenant_id = :tenant_id AND product_id = :pid
                 ORDER BY position ASC',
                ['pid' => $productId]
            );
            foreach ($remaining as $index => $r) {
                $this->scopedExec(
                    'UPDATE product_images SET position = :pos
                     WHERE tenant_id = :tenant_id AND product_id = :pid AND id = :img',
                    ['pid' => $productId, 'pos' => $index + 1, 'img' => (int) $r['id']]
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $row;
    }

    /**
     * Reorder helper for swap-like operations: move $imageId to the front
     * (cover) keeping the rest in their current order.
     */
    public function moveToCover(int $productId, int $imageId): bool
    {
        $current = $this->listForProduct($productId);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $current);
        $key = array_search($imageId, $ids, true);
        if ($key === false) {
            return false;
        }
        unset($ids[$key]);
        array_unshift($ids, $imageId);
        return $this->applyOrder($productId, array_values($ids));
    }
}
