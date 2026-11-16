<?php

declare(strict_types=1);

/**
 * Auth / CSRF / rate-limit / permissions test (step 2).
 *
 * SAFETY: refuses to run unless the configured database name ends with "_test".
 * Run from the project root:  php tests/auth_test.php
 * It also re-runs the step-1 isolation test and requires ALL PASSED.
 */

use App\Controllers\AuthController;
use App\Controllers\SuperAdminAuthController;
use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Permissions;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Helpers\TenantPurger;
use App\Middleware\RequireActiveSubscription;
use App\Middleware\RequireSuperAdmin;
use App\Middleware\RequireTenant;
use App\Models\SuperAdminModel;

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

/** Expect a callable to throw an ApiException with the given code. */
function expectApi(callable $fn, string $code, string $name): void
{
    try {
        $fn();
        check($name, false, 'no exception thrown');
    } catch (ApiException $e) {
        check($name, $e->errorCode === $code, 'got code=' . $e->errorCode);
    } catch (Throwable $e) {
        check($name, false, get_class($e) . ': ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// Connect + verify schema.
// --------------------------------------------------------------------------
try {
    $db = Database::get();
} catch (Throwable $e) {
    out('Cannot connect to test database "' . $dbName . '". Check tests/test_config.php.');
    exit(1);
}
$existing = array_map(static fn (array $row): string => (string) array_values($row)[0], $db->all('SHOW TABLES'));
$missing = array_diff(['tenants', 'users', 'login_attempts', 'audit_log', 'plans'], $existing);
if ($missing !== []) {
    out('Missing tables: ' . implode(', ', $missing) . " — import schema.sql into {$dbName} first.");
    exit(1);
}

// --------------------------------------------------------------------------
// Setup: fresh state, one tenant + owner + staff (+ a product for strip tests).
// --------------------------------------------------------------------------
Request::setTestMethod('POST');
Auth::boot(); // user scope

$leftoverIds = array_map(
    static fn (array $row): int => (int) $row['id'],
    $db->all("SELECT id FROM tenants WHERE slug = 'auth-t1'")
);
TenantPurger::purge($db, $leftoverIds);
$db->execute("DELETE FROM login_attempts WHERE identifier LIKE ?", ['auth-t1|%']);
$db->execute("DELETE FROM login_attempts WHERE identifier LIKE ?", ['admin|%']);
$db->execute("DELETE FROM login_attempts WHERE ip = ?", ['0.0.0.0']);
$db->execute('DELETE FROM super_admins WHERE email = ?', ['root-auth@test.local']);

$tenantId = (int) $db->insert('tenants', [
    'name' => 'Auth T1', 'slug' => 'auth-t1', 'shop_type' => 'cosmetics',
    'subscription_expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400),
]);
$ownerId = (int) $db->insert('users', [
    'tenant_id' => $tenantId, 'role' => 'owner', 'name' => 'Owner T1',
    'email' => 'owner@auth.local', 'phone' => '09121112233',
    // deliberately old cost so the rehash path is exercised
    'password_hash' => password_hash('old-password', PASSWORD_BCRYPT, ['cost' => 4]),
    'can_see_cost' => 1,
]);
$staffId = (int) $db->insert('users', [
    'tenant_id' => $tenantId, 'role' => 'staff', 'name' => 'Staff T1',
    'email' => 'staff@auth.local', 'phone' => '09321112233',
    'password_hash' => password_hash('staff-password', PASSWORD_DEFAULT),
    'can_see_cost' => 0,
]);
$db->insert('products', ['tenant_id' => $tenantId, 'name' => 'Cream', 'buy_price' => 50000, 'sell_price' => 90000, 'stock' => 10]);

$adminId = (int) $db->insert('super_admins', [
    'name' => 'Root T1', 'email' => 'root-auth@test.local',
    'password_hash' => password_hash('admin-password', PASSWORD_DEFAULT),
]);

// --------------------------------------------------------------------------
// 1) Login validation + generic failures.
// --------------------------------------------------------------------------
Request::setTestInput(['slug' => 'auth-t1', 'identifier' => '', 'password' => 'x']);
expectApi(fn () => AuthController::login(), 'INVALID_INPUT', 'login with empty identifier rejected');

Request::setTestInput(['slug' => 'no-such-shop', 'identifier' => 'owner@auth.local', 'password' => 'old-password']);
expectApi(fn () => AuthController::login(), 'AUTH_FAILED', 'unknown shop gives generic AUTH_FAILED');

Request::setTestInput(['slug' => 'auth-t1', 'identifier' => 'owner@auth.local', 'password' => 'WRONG-password']);
expectApi(fn () => AuthController::login(), 'AUTH_FAILED', 'wrong password gives generic AUTH_FAILED');
$fails = (int) $db->value(
    'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND success = 0',
    ['auth-t1|owner@auth.local']
);
check('failed attempt recorded per slug+identifier', $fails === 1, 'count=' . $fails);

// --------------------------------------------------------------------------
// 2) Rate limiting: lock at 5 failures, release when the window passes.
// --------------------------------------------------------------------------
for ($i = 0; $i < 4; $i++) {
    RateLimiter::record('auth-t1', 'owner@auth.local', Request::ip(), false);
}
expectApi(
    fn () => RateLimiter::assertCanAttempt('auth-t1', 'owner@auth.local', Request::ip()),
    'AUTH_LOCKED',
    'account locked after 5 failures'
);

// Per-IP limit (30) is independent of the per-account limit (5):
// 30 failures spread over OTHER accounts, same IP.
for ($i = 0; $i < 30; $i++) {
    RateLimiter::record('auth-t1', "victim{$i}@auth.local", Request::ip(), false);
}
expectApi(
    fn () => RateLimiter::assertCanAttempt('auth-t1', 'fresh@auth.local', Request::ip()),
    'AUTH_LOCKED',
    'IP lock applies to a fresh account on a saturated IP'
);

// Release: age the account's failures past the lock window and use a fresh IP.
$db->execute(
    'UPDATE login_attempts SET created_at = ? WHERE identifier = ? AND success = 0',
    [date('Y-m-d H:i:s', time() - 20 * 60), 'auth-t1|owner@auth.local']
);
try {
    RateLimiter::assertCanAttempt('auth-t1', 'owner@auth.local', '203.0.113.77'); // fresh IP
    check('account lock releases after the window passes', true);
} catch (Throwable $e) {
    check('account lock releases after the window passes', false, $e->getMessage());
}

// --------------------------------------------------------------------------
// 3) Staff login with Persian-digit phone (normalization) + staff permissions.
// --------------------------------------------------------------------------
Request::setTestInput(['slug' => 'auth-t1', 'identifier' => '۰۹۳۲۱۱۱۲۲۳۳', 'password' => 'staff-password']);
$staffLogin = AuthController::login();
check('Persian-digit phone login works', is_array($staffLogin) && ($staffLogin['user']['id'] ?? 0) === $staffId);
check('staff session active', Auth::isLoggedIn() && Auth::role() === 'staff');
check('staff cannot see cost', Permissions::can('see_cost') === false && Auth::canSeeCost() === false);

expectApi(fn () => Permissions::assertCanSortOrFilter('profit'), 'FORBIDDEN_FIELD', 'staff cannot sort by profit');
expectApi(fn () => Permissions::assertCanSortOrFilter('margin'), 'FORBIDDEN_FIELD', 'staff cannot sort by margin');
Permissions::assertCanSortOrFilter('name'); // must NOT throw
check('staff can sort by non-cost field', true);

// --------------------------------------------------------------------------
// 4) Central cost stripping (nested payloads included).
// --------------------------------------------------------------------------
$payload = [
    'product' => ['name' => 'Cream', 'buy_price' => 50000, 'sell_price' => 90000],
    'items' => [
        ['name' => 'A', 'buy_price' => 1, 'profit' => 2],
        ['name' => 'B', 'line_cost' => 5],
    ],
    'summary' => ['cost_total' => 9, 'revenue' => 100, 'nested' => [['margin' => 7, 'ok' => 1]]],
];
$clean = Permissions::stripSensitive($payload);
check('strips cost keys at depth 1', !array_key_exists('buy_price', $clean['product']) && $clean['product']['sell_price'] === 90000);
check('strips cost keys inside lists', !array_key_exists('profit', $clean['items'][0]) && $clean['items'][0]['name'] === 'A');
check('strips cost keys at depth 3', !array_key_exists('margin', $clean['summary']['nested'][0]) && $clean['summary']['nested'][0]['ok'] === 1);
check('strips cost_total summary', !array_key_exists('cost_total', $clean['summary']) && $clean['summary']['revenue'] === 100);

// --------------------------------------------------------------------------
// 5) CSRF: missing/wrong rejected, correct accepted (constant-time compare).
// --------------------------------------------------------------------------
$token = Csrf::token();
Request::setTestInput([]);
expectApi(fn () => Csrf::handle(), 'CSRF_INVALID', 'missing csrf token rejected');
Request::setTestInput(['csrf_token' => str_repeat('a', 64)]);
expectApi(fn () => Csrf::handle(), 'CSRF_INVALID', 'wrong csrf token rejected');
Request::setTestInput(['csrf_token' => $token]);
try {
    Csrf::handle();
    check('correct csrf token accepted', true);
} catch (Throwable $e) {
    check('correct csrf token accepted', false, $e->getMessage());
}

// --------------------------------------------------------------------------
// 6) Owner login: session fixation defense + transparent rehash.
// --------------------------------------------------------------------------
$sidBefore = session_id();
Request::setTestInput(['slug' => 'auth-t1', 'identifier' => 'owner@auth.local', 'password' => 'old-password']);
$ownerLogin = AuthController::login();
$sidAfter = session_id();
check('owner login succeeds', is_array($ownerLogin) && ($ownerLogin['user']['role'] ?? '') === 'owner');
check('session id changed on login (fixation defense)', $sidBefore !== $sidAfter && $sidAfter !== '');
$newHash = (string) $db->value('SELECT password_hash FROM users WHERE id = ?', [$ownerId]);
check('password rehashed on login (old cost detected)', password_verify('old-password', $newHash) && !password_needs_rehash($newHash, PASSWORD_DEFAULT));

// --------------------------------------------------------------------------
// 7) me: user + tenant + subscription + days left.
// --------------------------------------------------------------------------
RequireTenant::handle(); // sets TenantContext like the router middleware
$me = AuthController::me();
check('me returns user + tenant', ($me['user']['id'] ?? 0) === $ownerId && ($me['tenant']['slug'] ?? '') === 'auth-t1');
check('me returns days_left ~30', ($me['tenant']['days_left'] ?? -1) >= 28 && ($me['tenant']['days_left'] ?? -1) <= 30);
check('me returns fresh csrf token', is_string($me['csrf_token'] ?? null) && ($me['csrf_token'] ?? '') !== '');

// --------------------------------------------------------------------------
// 8) change_password.
// --------------------------------------------------------------------------
Request::setTestInput(['current_password' => 'WRONG', 'new_password' => 'changed-pass-9']);
expectApi(fn () => AuthController::change_password(), 'INVALID_CREDENTIALS', 'wrong current password rejected');
Request::setTestInput(['current_password' => 'old-password', 'new_password' => 'short']);
expectApi(fn () => AuthController::change_password(), 'WEAK_PASSWORD', 'too-short new password rejected');
Request::setTestInput(['current_password' => 'old-password', 'new_password' => 'changed-pass-9']);
$changed = AuthController::change_password();
check('change_password succeeds', is_array($changed) && ($changed['changed'] ?? false) === true);
$newHash2 = (string) $db->value('SELECT password_hash FROM users WHERE id = ?', [$ownerId]);
check('new password stored and verifies', password_verify('changed-pass-9', $newHash2) && !password_verify('old-password', $newHash2));

// --------------------------------------------------------------------------
// 9) Session namespaces never mix (tenant vs super-admin).
// --------------------------------------------------------------------------
Request::setTestInput([]);
expectApi(fn () => RequireSuperAdmin::handle(), 'UNAUTHENTICATED', 'tenant session cannot call super-admin middleware');

Auth::boot(Auth::SCOPE_ADMIN); // switch to the admin namespace
Request::setTestInput(['email' => 'root-auth@test.local', 'password' => 'admin-password']);
$adminLogin = SuperAdminAuthController::login();
check('super-admin login succeeds', is_array($adminLogin) && ($adminLogin['admin']['email'] ?? '') === 'root-auth@test.local');
expectApi(fn () => RequireTenant::handle(), 'UNAUTHENTICATED', 'super-admin session cannot call tenant middleware');
try {
    RequireSuperAdmin::handle();
    $adminMe = SuperAdminAuthController::me();
    check('super-admin me works', ($adminMe['admin']['name'] ?? '') === 'Root T1');
} catch (Throwable $e) {
    check('super-admin me works', false, $e->getMessage());
}
Auth::boot(Auth::SCOPE_USER); // back to the shop-user namespace (owner session)

// --------------------------------------------------------------------------
// 10) Expired tenant: reads allowed, writes rejected.
// --------------------------------------------------------------------------
$db->execute('UPDATE tenants SET subscription_status = ? WHERE id = ?', ['expired', $tenantId]);
RequireTenant::handle(); // login/read stays possible
$tenantRow = (new App\Models\TenantModel())->findById($tenantId);
check('expired tenant loads (read allowed)', $tenantRow !== null && $tenantRow['subscription_status'] === 'expired');
Request::setTestMethod('POST');
expectApi(fn () => RequireActiveSubscription::handle(), 'SUBSCRIPTION_EXPIRED', 'write rejected for expired tenant');
Request::setTestMethod('GET');
try {
    RequireActiveSubscription::handle();
    check('read allowed for expired tenant', true);
} catch (Throwable $e) {
    check('read allowed for expired tenant', false, $e->getMessage());
}
$db->execute('UPDATE tenants SET subscription_status = ? WHERE id = ?', ['active', $tenantId]);
Request::setTestMethod('POST');

// --------------------------------------------------------------------------
// 11) Re-run the step-1 isolation test in a subprocess; require ALL PASSED.
// --------------------------------------------------------------------------
$php = PHP_BINARY ?: 'php';
$isolated = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tests/isolation_test.php') . ' 2>&1');
check('isolation test still passes', is_string($isolated) && str_contains($isolated, 'ALL PASSED'),
    is_string($isolated) ? substr($isolated, -300) : 'no output');

// --------------------------------------------------------------------------
// Cleanup.
// --------------------------------------------------------------------------
TenantPurger::purge($db, [$tenantId]);
$db->execute('DELETE FROM super_admins WHERE email = ?', ['root-auth@test.local']);
$db->execute("DELETE FROM login_attempts WHERE identifier LIKE ?", ['auth-t1|%']);
$db->execute("DELETE FROM login_attempts WHERE identifier LIKE ?", ['admin|%']);
$db->execute("DELETE FROM login_attempts WHERE ip = ?", ['0.0.0.0']);
$db->execute('DELETE FROM audit_log WHERE action = ? AND actor_id = ?', ['admin.login', $adminId]);

$left = (int) $db->value(
    'SELECT
        (SELECT COUNT(*) FROM products WHERE tenant_id IN (?,?))
      + (SELECT COUNT(*) FROM orders WHERE tenant_id IN (?,?))
      + (SELECT COUNT(*) FROM order_items WHERE tenant_id IN (?,?))
      + (SELECT COUNT(*) FROM expenses WHERE tenant_id IN (?,?))
      + (SELECT COUNT(*) FROM users WHERE tenant_id IN (?,?))',
    [$tenantId, $tenantId, $tenantId, $tenantId, $tenantId, $tenantId, $tenantId, $tenantId, $tenantId, $tenantId]
);
check('cleanup removed all seeded rows', $left === 0);

// --------------------------------------------------------------------------
// Summary.
// --------------------------------------------------------------------------
out('');
out("Results: {$pass} passed, {$fail} failed.");
if ($fail === 0) {
    out('ALL PASSED');
    exit(0);
}
exit(1);
