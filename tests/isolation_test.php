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
use App\Models\ProductModel;

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
// Seed two shops (super-admin level: raw SQL with explicit tenant_id).
// --------------------------------------------------------------------------
// Remove leftover rows from a previous run through the TenantPurger
// (a direct DELETE FROM tenants can fail with FK error 1451 because the
// composite FKs do not all cascade, e.g. order_items -> products).
$leftover = array_map(
    static fn (array $row): int => (int) $row['id'],
    $db->all("SELECT id FROM tenants WHERE name IN ('Isolation A', 'Isolation B')")
);
TenantPurger::purge($db, $leftover);
$tenantA = (int) $db->insert('tenants', ['name' => 'Isolation A', 'shop_type' => 'clothing']);
$tenantB = (int) $db->insert('tenants', ['name' => 'Isolation B', 'shop_type' => 'cosmetics']);
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

check('product search(B name) empty', $productModel->search('ZZUNIQUEZZ') === []);
check('product search(A name) works', $productModel->search('Dress A') !== []);

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
// Cleanup (tenant delete cascades all seeded rows).
// --------------------------------------------------------------------------
TenantPurger::purge($db, [$tenantA, $tenantB]);

// Verify the purge left nothing behind, including tables the seed wrote
// through the models (products, orders, order_items, expenses, users).
// Positional "?" placeholders only — unique by construction, so no
// placeholder-reuse issues with emulation off.
$tenantIds = [$tenantA, $tenantB];
$marks = implode(', ', array_fill(0, count($tenantIds), '?'));
$allIds = [...$tenantIds, ...$tenantIds, ...$tenantIds, ...$tenantIds, ...$tenantIds];
$left = (int) $db->value(
    "SELECT
        (SELECT COUNT(*) FROM products WHERE tenant_id IN ($marks))
      + (SELECT COUNT(*) FROM orders WHERE tenant_id IN ($marks))
      + (SELECT COUNT(*) FROM order_items WHERE tenant_id IN ($marks))
      + (SELECT COUNT(*) FROM expenses WHERE tenant_id IN ($marks))
      + (SELECT COUNT(*) FROM users WHERE tenant_id IN ($marks))",
    $allIds
);
check('cleanup removed all seeded rows (products, orders, order_items, expenses, users)', $left === 0);

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
