<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Deletes every row belonging to the given tenants, in a safe leaf-first
 * dependency order, inside ONE transaction (rollback + rethrow on error).
 *
 * Why this exists: the composite foreign keys (tenant_id, id) intentionally do
 * not all cascade (e.g. order_items -> products), and InnoDB applies cascades
 * in its own internal order, so a plain "DELETE FROM tenants" can fail with
 * FK error 1451. This purger deletes child tables explicitly and the tenant
 * rows last.
 *
 * Reused by:
 *   - tests/isolation_test.php (leftover and final cleanup)
 *   - the super-admin "delete shop" feature (step 6)
 *
 * Usage: TenantPurger::purge(Database::get(), [3, 7]);
 */
final class TenantPurger
{
    /**
     * Deletion order: leaf tables first, tenants last.
     * Maps table => the column that links rows to the tenant. All tenant-owned
     * tables use tenant_id, except:
     *   - tenants itself, whose key is id;
     *   - shop_settings, whose primary key IS tenant_id (one row per tenant).
     */
    private const TABLE_ORDER = [
        'order_items'           => 'tenant_id',
        'orders'                => 'tenant_id',
        'expenses'              => 'tenant_id',
        'expense_categories'    => 'tenant_id',
        'product_images'        => 'tenant_id',
        'product_variants'      => 'tenant_id',
        'products'              => 'tenant_id',
        'payment_methods'       => 'tenant_id',
        'shop_settings'         => 'tenant_id',
        'audit_log'             => 'tenant_id',
        'subscription_payments' => 'tenant_id',
        'users'                 => 'tenant_id',
        'tenants'               => 'id',
    ];

    /**
     * @param array<int, int|string> $tenantIds tenant ids to wipe completely
     */
    public static function purge(Database $db, array $tenantIds): void
    {
        $ids = array_values(array_filter(
            array_map('intval', $tenantIds),
            static fn (int $id): bool => $id > 0
        ));
        if ($ids === []) {
            return;
        }

        // Positional "?" placeholders built once from the cast ids.
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $pdo = $db->pdo();

        $pdo->beginTransaction();
        try {
            // audit_log.tenant_id is nullable: "tenant_id IN (...)" evaluates to
            // NULL (not TRUE) for NULL rows, so platform-level rows are never touched.
            foreach (self::TABLE_ORDER as $table => $column) {
                $db->execute("DELETE FROM `$table` WHERE `$column` IN ($marks)", $ids);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException('Tenant purge failed and was rolled back.', 0, $e);
        }
    }
}
