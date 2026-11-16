<?php

declare(strict_types=1);

/**
 * seed_demo.php — CLI only, LOCAL DEVELOPMENT ONLY.
 * Refuses to run unless config 'env' is exactly 'development'.
 *
 * Creates: a demo plan, one demo tenant (cosmetics preset, slug "demo"),
 * an owner and a staff user (staff can NOT see cost), payment methods and
 * shop settings. Passwords are random and printed ONCE to the console.
 *
 * Run:  php tools/seed_demo.php
 */

use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\Database;
use App\Helpers\Text;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
require $root . '/app/Core/Bootstrap.php';
Bootstrap::start($root);

if (Config::get('env', 'production') !== 'development') {
    fwrite(STDERR, "seed_demo.php runs only when config 'env' is 'development'.\n");
    exit(1);
}

$db = Database::get();

if ($db->one('SELECT id FROM tenants WHERE slug = ?', ['demo']) !== null) {
    fwrite(STDERR, "A tenant with slug 'demo' already exists; nothing seeded.\n");
    exit(1);
}

function randomPassword(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// Plan (reuse the default one when present)
$planId = (int) ($db->value("SELECT id FROM plans WHERE name = 'دمو' LIMIT 1") ?? 0);
if ($planId === 0) {
    $planId = $db->insert('plans', [
        'name' => 'دمو', 'monthly_price' => 0,
        'max_products' => 300, 'max_staff_users' => 3, 'max_images' => 5,
    ]);
}

// Demo tenant — cosmetics preset
$fieldSchema = json_encode([
    ['key' => 'brand',  'label' => 'برند',        'type' => 'text'],
    ['key' => 'volume', 'label' => 'حجم',         'type' => 'text'],
    ['key' => 'origin', 'label' => 'کشور سازنده', 'type' => 'text'],
], JSON_UNESCAPED_UNICODE);

$tenantId = $db->insert('tenants', [
    'name'                   => 'فروشگاه دمو',
    'slug'                   => Text::normalizeSlug('demo'),
    'shop_type'              => 'cosmetics',
    'field_schema'           => $fieldSchema,
    'plan_id'                => $planId,
    'subscription_status'    => 'active',
    'subscription_expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400),
]);
$db->insert('shop_settings', ['tenant_id' => $tenantId, 'shipping_fee' => 40000, 'rounding_step' => 1000]);

// Payment methods (per-shop toggles; fee math only)
$db->insert('payment_methods', ['tenant_id' => $tenantId, 'code' => 'cash', 'label' => 'نقدی', 'enabled' => 1, 'sort_order' => 1]);
$db->insert('payment_methods', ['tenant_id' => $tenantId, 'code' => 'card_to_card', 'label' => 'کارت به کارت', 'enabled' => 1, 'sort_order' => 2]);
$db->insert('payment_methods', [
    'tenant_id' => $tenantId, 'code' => 'snap_pay', 'label' => 'اسنپ پی', 'enabled' => 1,
    'is_credit' => 1, 'fee_type' => 'multiplier', 'fee_value' => 1.15,
    'rounding_step' => 1000, 'fee_bearer' => 'customer', 'sort_order' => 3,
]);

$ownerPassword = randomPassword();
$staffPassword = randomPassword();

$ownerId = $db->insert('users', [
    'tenant_id'     => $tenantId,
    'role'          => 'owner',
    'name'          => 'مدیر دمو',
    'email'         => 'owner@demo.local',
    'phone'         => Text::normalizePhone('۰۹۱۲۰۰۰۰۰۰۰'),
    'password_hash' => password_hash($ownerPassword, PASSWORD_DEFAULT),
    'can_see_cost'  => 1,
]);
$staffId = $db->insert('users', [
    'tenant_id'     => $tenantId,
    'role'          => 'staff',
    'name'          => 'کارمند دمو',
    'email'         => 'staff@demo.local',
    'phone'         => Text::normalizePhone('۰۹۱۲۰۰۰۰۰۰۱'),
    'password_hash' => password_hash($staffPassword, PASSWORD_DEFAULT),
    'can_see_cost'  => 0,
]);

echo "Demo data seeded. These credentials are printed ONCE:\n";
echo "  shop slug: demo\n";
echo "  owner (can see cost): owner@demo.local / {$ownerPassword}\n";
echo "  staff (cannot see cost): staff@demo.local / {$staffPassword}\n";
echo "  owner id={$ownerId}, staff id={$staffId}, tenant id={$tenantId}\n";
