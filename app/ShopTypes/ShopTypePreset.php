<?php

declare(strict_types=1);

namespace App\ShopTypes;

use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Base class for shop type presets.
 *
 * A preset owns EVERYTHING that varies between shop types:
 *   - the product field schema (rendered by forms/lists/reports in later steps,
 *     never hardcoded per screen),
 *   - feature flags (variants, expiry_tracking, ...),
 *   - default expense categories and default payment methods seeded at shop
 *     creation.
 *
 * v1 ships only the cosmetics preset (see AGENTS.md "Scope update (v1)").
 * Adding a new shop type = one subclass + one entry in ShopTypes::PRESETS.
 * No code outside app/ShopTypes/ may branch on a shop type string.
 */
abstract class ShopTypePreset
{
    /** Machine-readable code, matches tenants.shop_type. */
    abstract public function code(): string;

    /** Persian display name shown in the UI. */
    abstract public function label(): string;

    /** @return array<string, bool> e.g. ['variants' => false, 'expiry_tracking' => true] */
    abstract public function featureFlags(): array;

    /**
     * Product field schema: data-driven extra fields stored per product in
     * products.extra_fields. One entry per field:
     *   key, label, type (text|number|date|choice), in_list, in_search,
     *   options (for choice), unit (for number), native (products column this
     *   field maps to instead of extra_fields, e.g. 'sku').
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function fieldSchema(): array;

    /**
     * Default expense categories seeded at shop creation.
     *
     * @return array<int, array{name: string, sort_order: int}>
     */
    abstract public function defaultExpenseCategories(): array;

    /**
     * Default payment methods seeded at shop creation (payment_methods rows
     * WITHOUT tenant_id; fees affect price math only, no real API integration).
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function defaultPaymentMethods(): array;

    public function hasFeature(string $flag): bool
    {
        return (bool) ($this->featureFlags()[$flag] ?? false);
    }

    /**
     * Seed preset-owned defaults for a (freshly created) tenant:
     *   1. tenants.field_schema = this preset's product field schema,
     *   2. default expense categories (missing names only),
     *   3. default payment methods (missing codes only).
     *
     * Idempotent: running it twice adds nothing. Runs in one transaction;
     * categories/methods the owner later edits are never overwritten.
     */
    public function applyToTenant(int $tenantId, ?Database $db = null): void
    {
        $db = $db ?? Database::get();
        $pdo = $db->pdo();

        $schema = json_encode($this->fieldSchema(), JSON_UNESCAPED_UNICODE);
        if ($schema === false) {
            throw new RuntimeException('Field schema could not be encoded to JSON.');
        }

        $pdo->beginTransaction();
        try {
            // 1) field_schema on the tenant row (JSON column takes the string).
            $db->execute(
                'UPDATE tenants SET field_schema = :schema WHERE id = :id',
                ['schema' => $schema, 'id' => $tenantId]
            );

            // 2) expense categories — only names the shop does not have yet.
            foreach ($this->defaultExpenseCategories() as $category) {
                $exists = $db->one(
                    'SELECT id FROM expense_categories WHERE tenant_id = :t AND name = :n',
                    ['t' => $tenantId, 'n' => (string) $category['name']]
                );
                if ($exists === null) {
                    $db->insert('expense_categories', [
                        'tenant_id'  => $tenantId,
                        'name'       => (string) $category['name'],
                        'sort_order' => (int) ($category['sort_order'] ?? 0),
                    ]);
                }
            }

            // 3) payment methods — only codes the shop does not have yet.
            foreach ($this->defaultPaymentMethods() as $method) {
                $exists = $db->one(
                    'SELECT id FROM payment_methods WHERE tenant_id = :t AND code = :c',
                    ['t' => $tenantId, 'c' => (string) $method['code']]
                );
                if ($exists === null) {
                    $db->insert('payment_methods', ['tenant_id' => $tenantId] + $method);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
