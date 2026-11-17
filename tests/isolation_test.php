<?php

declare(strict_types=1);

/**
 * Tenant isolation test.
 *
 * PROVES: shop A cannot read or modify shop B's data through any model,
 * and cross-tenant references are impossible at the database level
 * (composite foreign keys + the central tenant-scoping helper).
 *
 * SAFETY: refuses to run unless the configured database name ends with
 * "_test" (tests/test_config.php). Never point it at a production DB.
 *
 * Run from the project root:   php tests/isolation_test.php
 * or open http://localhost/.../tests/isolation_test.php on your dev host.
 */

use App\Core\Auth;
use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\Database;
use App\Helpers\TenantPurger;
use App\Models\ExpenseCategoryModel;
use App\Models\ExpenseModel;
use App\Models\OrderModel;
use App\Models\PaymentMethodModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\ProductVariantModel;
use App\ShopTypes\ShopTypes;

$root = dirname(__DIR__);
require $root . '/app/Core/Bootstrap.php';
Bootstrap::start($root, __DIR__ . '/test_config.php');

$cli = PHP_SAPI === 'cli';
function out(string $line): void
{
    echo $line . (PHP_SAPI === 'cli' ? "\n" : "<br>\n");
}

// --------------------------------------------------------------------------
// SAFETY GATE: only run against a *_test database.
// --------------------------------------------------------------------------
$dbName = (string) Config::get('db.name', '');
if (!str_ends_with($dbName, '_test')) {
    out('REFUSING TO RUN: the configured database name "' . $dbName . '" does not end with "_test".');
    out('Copy tests/test_config.example.php to tests/test_config.php and use a dedicated test database.');
    exit(1);
}

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        out("PASS  $name");
    } else {
        $fail++;
        out("FAIL  $name" . ($detail !== '' ? "  -- $detail" : ''));
    }
}

// --------------------------------------------------------------------------
// Connect + verify schema was imported into the test database.
// --------------------------------------------------------------------------
try {
    $db = Database::get();
} catch (Throwable $e) {
    out('Cannot connect to test database "' . $dbName . '". Check tests/test_config.php.');
    exit(1);
}

$required = ['plans', 'tenants', 'users', 'super_admins', 'products', 'product_images',
    'product_variants', 'payment_methods', 'shop_settings', 'orders', 'order_items',
    'expense_categories', 'expenses', 'subscription_payments', 'audit_log', 'login_attempts'];
$existing = array_map(static fn (array $row): string => (string) array_values($row)[0], $db->all('SHOW TABLES'));
$missing = array_diff($required, $existing);
if ($missing !== []) {
    out('Missing tables: ' . implode(', ', $missing));
    out("Import schema.sql into {$dbName} first.");
    exit(1);
}

// --------------------------------------------------------------------------
// Re-runnable cleanup (crash-safe).
//
// Leftover test TENANTS are purged first (leaf-first through TenantPurger)
// and only then anything they could reference — this test seeds no plans,
// so there is no FK-1451 hazard here, but the ordering rule is the same as
// in product_test.php. Only rows matching THIS test's tenant names are
// ever touched, and the *_test gate is re-checked as defense in depth.
// --------------------------------------------------------------------------
$purgeTestData = static function (): void {
    if (!str_ends_with((string) Config::get('db.name', ''), '_test')) {
        return; // never run outside a *_test database
    }
    $db = Database::get();
    $leftover = array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->all("SELECT id FROM tenants WHERE name IN ('Isolation A', 'Isolation B', 'Isolation C')")
    );
    TenantPurger::purge($db, $leftover);
};

/** Safety-net cleanup. Swallows its own errors so it can never mask results. */
$runCleanup = static function () use ($purgeTestData): void {
    try {
        $purgeTestData();
    } catch (Throwable) {
        // ignore: a failing safety net must not break the run
    }
};
register_shutdown_function($runCleanup);

// --------------------------------------------------------------------------
// Seed two shops (super-admin level: raw SQL with explicit tenant_id).
// The whole body lives in try/finally: a crash mid-run still triggers the
// same cleanup, so the test is re-runnable afterwards.
// --------------------------------------------------------------------------
try {
    // Remove leftover rows from a previous (possibly crashed) run through the
    // TenantPurger (a direct DELETE FROM tenants can fail with FK error 1451
    // because the composite FKs do not all cascade, e.g. order_items ->
    // products). Tenants first, referenced rows later.
    $purgeTestData();
    // shop_type: only 'cosmetics' is a valid v1 code (see app/ShopTypes/).
    $tenantA = (int) $db->insert('tenants', ['name' => 'Isolation A', 'slug' => 'isolation-a', 'shop_type' => 'cosmetics']);
    $tenantB = (int) $db->insert('tenants', ['name' => 'Isolation B', 'slug' => 'isolation-b', 'shop_type' => 'cosmetics']);
    $db->insert('shop_settings', ['tenant_id' => $tenantA, 'shipping_fee' => 30000]);
    $db->insert('shop_settings', ['tenant_id' => $tenantB, 'shipping_fee' => 20000]);

    $ownerA = (int) $db->insert('users', [
        'tenant_id' => $tenantA, 'role' => 'owner', 'name' => 'Owner A',
        'email' => 'owner-a@test.local', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'can_see_cost' => 1,
    ]);
    $ownerB = (int) $db->insert('users', [
        'tenant_id' => $tenantB, 'role' => 'owner', 'name' => 'Owner B',
        'email' => 'owner-b@test.local', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'can_see_cost' => 1,
    ]);

    $pmA = (int) $db->insert('payment_methods', [
        'tenant_id' => $tenantA, 'code' => 'snap_pay', 'label' => 'اسنپ پی',
        'is_credit' => 1, 'fee_type' => 'multiplier', 'fee_value' => 1.15,
        'rounding_step' => 1000, 'fee_bearer' => 'customer',
    ]);
    $pmB = (int) $db->insert('payment_methods', [
        'tenant_id' => $tenantB, 'code' => 'cash', 'label' => 'نقدی',
    ]);

    // Shop B data (the data shop A must never reach).
    $prodB = (int) $db->insert('products', [
        'tenant_id' => $tenantB, 'name' => 'ZZUNIQUEZZ-Secret-Cream', 'buy_price' => 50000, 'sell_price' => 90000, 'stock' => 7,
    ]);
    $orderB = (int) $db->insert('orders', [
        'tenant_id' => $tenantB, 'status' => 'new', 'customer_name' => 'B Customer',
        'customer_phone' => '09120000000', 'payment_method_id' => $pmB, 'pm_code' => 'cash',
        'subtotal' => 100000, 'cost_total' => 60000, 'shipping_fee' => 30000, 'total' => 130000,
    ]);
    $db->insert('order_items', [
        'tenant_id' => $tenantB, 'order_id' => $orderB, 'product_id' => $prodB,
        'product_name' => 'ZZUNIQUEZZ-Secret-Cream', 'qty' => 1, 'sell_price' => 100000, 'buy_price' => 60000, 'line_total' => 100000, 'line_cost' => 60000,
    ]);
    $catB = (int) $db->insert('expense_categories', ['tenant_id' => $tenantB, 'name' => 'تبلیغات']);
    $expB = (int) $db->insert('expenses', [
        'tenant_id' => $tenantB, 'category_id' => $catB, 'amount' => 50000, 'expense_date' => '2026-01-01',
    ]);

    // --------------------------------------------------------------------------
    // Simulate an authenticated session for shop A's owner (models read the
    // tenant context from the session, never from caller input).
    // --------------------------------------------------------------------------
    Auth::boot();
    $_SESSION['user_id'] = $ownerA;
    $_SESSION['tenant_id'] = $tenantA;
    $_SESSION['role'] = 'owner';
    $_SESSION['can_see_cost'] = 1;

    $productModel = new ProductModel();
    $orderModel = new OrderModel();
    $expenseModel = new ExpenseModel();
    $expenseCategoryModel = new ExpenseCategoryModel();
    $paymentMethodModel = new PaymentMethodModel();

    // Shop A creates its own data through the models (scoping must not break writes).
    $prodA = $productModel->insert(['name' => 'Dress A', 'buy_price' => 400000, 'sell_price' => 700000, 'stock' => 5]);
    check('model insert keeps session tenant', $productModel->find($prodA)['tenant_id'] == $tenantA);
    $catA = $expenseCategoryModel->insert(['name' => 'بسته‌بندی']);
    $expA = $expenseModel->insert(['category_id' => $catA, 'amount' => 20000, 'expense_date' => '2026-01-02']);

    // --------------------------------------------------------------------------
    // 1) Shop A cannot READ shop B through any model.
    // --------------------------------------------------------------------------
    check('product find(B) invisible', $productModel->find($prodB) === null);
    check('order find(B) invisible', $orderModel->find($orderB) === null);
    check('expense find(B) invisible', $expenseModel->find($expB) === null);
    check('expense category find(B) invisible', $expenseCategoryModel->find($catB) === null);
    check('payment method find(B) invisible', $paymentMethodModel->find($pmB) === null);

    check('product list(B name) empty', $productModel->list(['q' => 'ZZUNIQUEZZ', 'archived' => 'all'])['items'] === []);
    check('product list(A name) works', $productModel->list(['q' => 'Dress A', 'archived' => 'all'])['items'] !== []);

    // --------------------------------------------------------------------------
    // 2) Shop A cannot MODIFY shop B through any model.
    // --------------------------------------------------------------------------
    check('product update(B) blocked', $productModel->update($prodB, ['name' => 'HACKED']) === 0
        && $db->value('SELECT name FROM products WHERE id = :id', ['id' => $prodB]) === 'ZZUNIQUEZZ-Secret-Cream');
    check('order update(B) blocked', $orderModel->update($orderB, ['status' => 'cancelled']) === 0
        && $db->value('SELECT status FROM orders WHERE id = :id', ['id' => $orderB]) === 'new');
    check('expense update(B) blocked', $expenseModel->update($expB, ['amount' => 1]) === 0
        && (int) $db->value('SELECT amount FROM expenses WHERE id = :id', ['id' => $expB]) === 50000);
    check('product delete(B) blocked', $productModel->delete($prodB) === 0);
    check('order delete(B) blocked', $orderModel->delete($orderB) === 0);
    check('expense delete(B) blocked', $expenseModel->delete($expB) === 0);

    // Raw SQL with a wrong tenant_id must also change nothing (defense in depth).
    check('raw UPDATE with wrong tenant is a no-op',
        $db->execute('UPDATE orders SET status = :s WHERE id = :id AND tenant_id = :tenant_id',
            ['s' => 'cancelled', 'id' => $orderB, 'tenant_id' => $tenantA]) === 0);

    // --------------------------------------------------------------------------
    // 3) tenant_id can never be injected through the model API.
    // --------------------------------------------------------------------------
    foreach (['product' => $productModel, 'order' => $orderModel, 'expense' => $expenseModel,
        'expense category' => $expenseCategoryModel, 'payment method' => $paymentMethodModel] as $label => $model) {
        try {
            $model->insert(['tenant_id' => $tenantB, 'name' => 'x', 'amount' => 1, 'expense_date' => '2026-01-01']);
            check("insert with injected tenant_id refused ($label)", false, 'no exception thrown');
        } catch (RuntimeException $e) {
            check("insert with injected tenant_id refused ($label)", str_contains($e->getMessage(), 'tenant_id'));
        } catch (Throwable $e) {
            check("insert with injected tenant_id refused ($label)", false, get_class($e) . ': ' . $e->getMessage());
        }
    }

    // --------------------------------------------------------------------------
    // 4) Cross-tenant references are impossible at the DATABASE level
    //    (composite foreign keys tenant_id + parent id).
    // --------------------------------------------------------------------------
    $crossFk = function (callable $attempt, string $label) {
        try {
            $attempt();
            check($label, false, 'no FK exception thrown');
        } catch (PDOException $e) {
            check($label, $e->getCode() === '23000', 'code=' . $e->getCode());
        } catch (Throwable $e) {
            check($label, false, get_class($e) . ': ' . $e->getMessage());
        }
    };

    $crossFk(fn () => $expenseModel->insert(['category_id' => $catB, 'amount' => 1000, 'expense_date' => '2026-01-01']),
        'insert expense referencing B category rejected by FK');
    $crossFk(fn () => $orderModel->insert([
        'customer_name' => 'X', 'customer_phone' => '09123456789', 'payment_method_id' => $pmB,
    ]), 'insert order referencing B payment method rejected by FK');
    $crossFk(function () use ($db, $tenantA, $orderB, $prodA) {
        $db->insert('order_items', [
            'tenant_id' => $tenantA, 'order_id' => $orderB, 'product_id' => $prodA,
            'product_name' => 'Dress A', 'qty' => 1, 'sell_price' => 100, 'line_total' => 100,
        ]);
    }, 'raw insert order_item on B order with A tenant rejected by FK');

    // Session tenant B cannot see A either (symmetry).
    $_SESSION['tenant_id'] = $tenantB;
    $_SESSION['user_id'] = $ownerB;
    check('product find(A) invisible from B', $productModel->find($prodA) === null);
    $_SESSION['tenant_id'] = $tenantA;
    $_SESSION['user_id'] = $ownerA;

    // --------------------------------------------------------------------------
    // 5) product_images + product_variants isolation (step 3 models).
    // --------------------------------------------------------------------------
    $imageModel = new ProductImageModel();
    $variantModel = new ProductVariantModel();

    $imgA = $imageModel->insert([
        'product_id' => $prodA, 'file_name' => 'a_full.webp', 'medium_name' => 'a_m.webp',
        'thumb_name' => 'a_t.webp', 'position' => 1,
    ]);
    $varA = $variantModel->insert(['product_id' => $prodA, 'size' => 'M', 'color' => 'قرمز', 'stock' => 5]);

    check('image find(A) visible', (int) ($imageModel->find($imgA)['product_id'] ?? 0) === $prodA);
    check('variant find(A) visible', (int) ($variantModel->find($varA)['product_id'] ?? 0) === $prodA);

    // Shop B session must not reach A's image/variant.
    $_SESSION['tenant_id'] = $tenantB;
    $_SESSION['user_id'] = $ownerB;
    check('image find(A) invisible from B', $imageModel->find($imgA) === null);
    check('variant find(A) invisible from B', $variantModel->find($varA) === null);
    check('image update(A) blocked from B', $imageModel->update($imgA, ['position' => 9]) === 0);
    check('image delete(A) blocked from B', $imageModel->delete($imgA) === 0);
    check('variant update(A) blocked from B', $variantModel->update($varA, ['stock' => 999]) === 0);
    $_SESSION['tenant_id'] = $tenantA;
    $_SESSION['user_id'] = $ownerA;

    // Injected tenant_id refused for images and variants.
    foreach (['product image' => $imageModel, 'product variant' => $variantModel] as $label => $m) {
        try {
            $m->insert(['tenant_id' => $tenantB, 'product_id' => $prodA, 'file_name' => 'x',
                'medium_name' => 'x', 'thumb_name' => 'x', 'position' => 1]);
            check("insert with injected tenant_id refused ($label)", false, 'no exception thrown');
        } catch (RuntimeException $e) {
            check("insert with injected tenant_id refused ($label)", str_contains($e->getMessage(), 'tenant_id'));
        } catch (Throwable $e) {
            check("insert with injected tenant_id refused ($label)", false, get_class($e) . ': ' . $e->getMessage());
        }
    }

    // Cross-tenant FK: B product referenced from A rows is impossible.
    $crossFk(fn () => $imageModel->insert([
        'product_id' => $prodB, 'file_name' => 'x', 'medium_name' => 'x', 'thumb_name' => 'x', 'position' => 1,
    ]), 'insert image referencing B product rejected by FK');
    $crossFk(fn () => $variantModel->insert(['product_id' => $prodB, 'size' => 'L', 'color' => 'آبی', 'stock' => 1]),
        'insert variant referencing B product rejected by FK');

    // --------------------------------------------------------------------------
    // 6) Shop type preset seeding: field_schema + default expense categories +
    //    default payment methods, idempotent. Runs on a THIRD tenant because
    //    tenants A/B already have manually inserted payment methods whose codes
    //    would collide with the preset's unique (tenant_id, code) rows.
    // --------------------------------------------------------------------------
    $tenantC = (int) $db->insert('tenants', ['name' => 'Isolation C', 'slug' => 'isolation-c', 'shop_type' => 'cosmetics']);
    ShopTypes::preset('cosmetics')->applyToTenant($tenantC, $db);

    $schema = json_decode((string) $db->value('SELECT field_schema FROM tenants WHERE id = ?', [$tenantC]), true);
    check('preset writes tenants.field_schema', is_array($schema) && count($schema) >= 5,
        is_array($schema) ? 'fields=' . count($schema) : 'not JSON');

    $pmCount = (int) $db->value('SELECT COUNT(*) FROM payment_methods WHERE tenant_id = ?', [$tenantC]);
    $catCount = (int) $db->value('SELECT COUNT(*) FROM expense_categories WHERE tenant_id = ?', [$tenantC]);
    check('preset seeds default payment methods', $pmCount === 5, "count=$pmCount");
    check('preset seeds default expense categories', $catCount === 7, "count=$catCount");

    // Only cash + card_to_card are enabled; every credit/BNPL method is seeded
    // DISABLED with no fee, so the owner must enable it and enter a contract fee.
    $bnpl = $db->all(
        "SELECT code, enabled, fee_type, fee_value FROM payment_methods
         WHERE tenant_id = :t AND code IN ('snap_pay','digi_pay','torob_pay') ORDER BY code",
        ['t' => $tenantC]
    );
    $bnplOk = count($bnpl) === 3;
    foreach ($bnpl as $m) {
        $bnplOk = $bnplOk && (int) $m['enabled'] === 0 && $m['fee_type'] === 'none' && (float) $m['fee_value'] === 0.0;
    }
    check('preset seeds BNPL methods disabled with no fee', $bnplOk, print_r($bnpl, true));
    $basic = $db->all(
        "SELECT code, enabled FROM payment_methods WHERE tenant_id = :t AND code IN ('cash','card_to_card')",
        ['t' => $tenantC]
    );
    $basicOk = count($basic) === 2;
    foreach ($basic as $m) {
        $basicOk = $basicOk && (int) $m['enabled'] === 1;
    }
    check('preset seeds cash + card_to_card enabled', $basicOk, print_r($basic, true));

    // Idempotent: re-applying must not duplicate anything.
    ShopTypes::preset('cosmetics')->applyToTenant($tenantC, $db);
    check('preset seeding is idempotent',
        (int) $db->value('SELECT COUNT(*) FROM payment_methods WHERE tenant_id = ?', [$tenantC]) === $pmCount
        && (int) $db->value('SELECT COUNT(*) FROM expense_categories WHERE tenant_id = ?', [$tenantC]) === $catCount);

    // Registry rejects unknown codes; unknown client input normalizes to default.
    try {
        ShopTypes::preset('clothing');
        check('unknown preset code rejected', false, 'no exception thrown');
    } catch (InvalidArgumentException $e) {
        check('unknown preset code rejected', true);
    } catch (Throwable $e) {
        check('unknown preset code rejected', false, get_class($e) . ': ' . $e->getMessage());
    }
    check('unknown code normalizes to default', ShopTypes::normalize('no-such-type') === 'cosmetics');

    // Preset-seeded rows must vanish with the tenant (leaf-first purge).
    TenantPurger::purge($db, [$tenantC]);
    check('preset rows purged with tenant',
        (int) $db->value('SELECT COUNT(*) FROM payment_methods WHERE tenant_id = ?', [$tenantC]) === 0
        && (int) $db->value('SELECT COUNT(*) FROM expense_categories WHERE tenant_id = ?', [$tenantC]) === 0);

    // --------------------------------------------------------------------------
    // Cleanup (tenant delete cascades all seeded rows).
    // --------------------------------------------------------------------------
    TenantPurger::purge($db, [$tenantA, $tenantB]);

    // Verify the purge left nothing behind, including tables the seed wrote
    // through the models (products, orders, order_items, expenses, users).
    // Positional "?" placeholders only — unique by construction, so no
    // placeholder-reuse issues with emulation off.
    $tenantIds = [$tenantA, $tenantB];
    $marks = implode(', ', array_fill(0, count($tenantIds), '?'));
    $allIds = [...$tenantIds, ...$tenantIds, ...$tenantIds, ...$tenantIds, ...$tenantIds, ...$tenantIds, ...$tenantIds];
    $left = (int) $db->value(
        "SELECT
            (SELECT COUNT(*) FROM products WHERE tenant_id IN ($marks))
          + (SELECT COUNT(*) FROM orders WHERE tenant_id IN ($marks))
          + (SELECT COUNT(*) FROM order_items WHERE tenant_id IN ($marks))
          + (SELECT COUNT(*) FROM expenses WHERE tenant_id IN ($marks))
          + (SELECT COUNT(*) FROM users WHERE tenant_id IN ($marks))
          + (SELECT COUNT(*) FROM product_images WHERE tenant_id IN ($marks))
          + (SELECT COUNT(*) FROM product_variants WHERE tenant_id IN ($marks))",
        $allIds
    );
    check('cleanup removed all seeded rows (products, orders, order_items, expenses, users, images, variants)', $left === 0);
} finally {
    // Safety net: runs on ANY exit path of the body (exception, fatal-error
    // shutdown, normal end) so a crash never leaves the test DB unusable.
    $runCleanup();
}

// --------------------------------------------------------------------------
// Summary (same style as the reference tests.html).
// --------------------------------------------------------------------------
out('');
out("Results: {$pass} passed, {$fail} failed.");
if ($fail === 0) {
    out('ALL PASSED');
    exit(0);
}
exit(1);

