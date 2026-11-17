<?php

declare(strict_types=1);

/**
 * Step 3 test suite: products, extra fields, plan limits, bulk price,
 * image pipeline hardening, cross-tenant image isolation, expiring filter.
 *
 * SAFETY: refuses to run unless the configured database name ends with
 * "_test" (tests/test_config.php). Uploaded files go to a TEMP directory
 * (Config::setForTests('uploads.dir', ...)) — never into public/uploads.
 *
 * Run from the project root:  php tests/product_test.php
 */

use App\Controllers\ProductController;
use App\Controllers\ProductImageController;
use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\Database;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Core\TenantContext;
use App\Helpers\FieldSchema;
use App\Helpers\Jalali;
use App\Helpers\Text;
use App\Helpers\TenantPurger;
use App\Helpers\Uploads;
use App\Middleware\RequireActiveSubscription;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\TenantModel;

ob_start();

// Warnings/notices/deprecations are recorded and fail the run at the end.
$warnings = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
    $warnings[] = "[severity $severity] $message @ $file:$line";
    return true;
}, E_ALL);

$root = dirname(__DIR__);
require $root . '/app/Core/Bootstrap.php';
Bootstrap::start($root, __DIR__ . '/test_config.php');

function out(string $line): void
{
    echo $line . "\n";
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

function errDetail(Throwable $e): string
{
    if ($e instanceof ApiException) {
        $msg = $e->getMessage() !== '' ? ': ' . $e->getMessage() : '';
        return "ApiException {$e->errorCode} (status {$e->status})$msg";
    }
    return get_class($e) . ': ' . $e->getMessage();
}

function expectApi(callable $fn, string $code, string $name): void
{
    try {
        $fn();
        check($name, false, 'no exception thrown');
    } catch (ApiException $e) {
        check($name, $e->errorCode === $code, 'got ' . errDetail($e));
    } catch (Throwable $e) {
        check($name, false, errDetail($e));
    }
}

// --------------------------------------------------------------------------
// SAFETY GATE.
// --------------------------------------------------------------------------
$dbName = (string) Config::get('db.name', '');
if (!str_ends_with($dbName, '_test')) {
    out('REFUSING TO RUN: the configured database name "' . $dbName . '" does not end with "_test".');
    exit(1);
}

try {
    $db = Database::get();
} catch (Throwable $e) {
    out('Cannot connect to test database "' . $dbName . '".');
    exit(1);
}

$existing = array_map(static fn (array $row): string => (string) array_values($row)[0], $db->all('SHOW TABLES'));
$missing = array_diff(['tenants', 'users', 'products', 'product_images', 'product_variants',
    'payment_methods', 'shop_settings', 'plans', 'expense_categories', 'audit_log'], $existing);
if ($missing !== []) {
    out('Missing tables: ' . implode(', ', $missing) . " — import schema.sql into {$dbName} first.");
    exit(1);
}

// Redirect uploads to a temp dir so public/uploads is never touched.
// sys_get_temp_dir() has NO trailing slash and uses backslashes on Windows:
// normalize to forward slashes and join every path with '/'.
$uploadTmp = str_replace('\\', '/', sys_get_temp_dir())
    . '/shopam_test_uploads_' . getmypid();
Config::setForTests('uploads.dir', $uploadTmp);
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

// Order of operations: wipe leftovers from a previous run, then CREATE and
// VERIFY the directory BEFORE any sample image is written into it. (The
// per-tenant/per-product subfolders are created recursively by
// Uploads::productDir() later — but the base dir must exist for the sample
// files the test itself writes.)
rrmdir($uploadTmp);
if (!@mkdir($uploadTmp, 0777, true) && !is_dir($uploadTmp)) {
    out('FAILED to create the test upload directory: ' . $uploadTmp);
    ob_end_flush();
    exit(1);
}
if (!is_dir($uploadTmp) || !is_writable($uploadTmp)) {
    out('Test upload directory is not writable: ' . $uploadTmp);
    ob_end_flush();
    exit(1);
}

// --------------------------------------------------------------------------
// Re-runnable cleanup (crash-safe).
//
// ORDER MATTERS: leftover test TENANTS are purged first (leaf-first through
// TenantPurger) and only THEN leftover test PLANS are deleted. A previous
// run that crashed mid-seed leaves tenants still referencing the plan, so
// deleting the plan first fails with FK error 1451 (fk_tenants_plan). The
// closure only ever touches rows matching THIS test's slugs/plan name, and
// re-checks the *_test gate itself as defense in depth.
// --------------------------------------------------------------------------
$purgeTestData = static function (): void {
    if (!str_ends_with((string) Config::get('db.name', ''), '_test')) {
        return; // never run outside a *_test database
    }
    $db = Database::get();
    $leftover = array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->all("SELECT id FROM tenants WHERE slug IN ('prod-t1', 'prod-t2')")
    );
    TenantPurger::purge($db, $leftover);
    $db->execute('DELETE FROM plans WHERE name = ?', ['Product Test Plan']);
    $db->execute("DELETE FROM audit_log WHERE tenant_id IS NULL AND action LIKE 'product%'");
};

/** Safety-net cleanup: DB rows + the temp upload dir. Swallows its own errors. */
$runCleanup = static function () use ($purgeTestData, $uploadTmp): void {
    try {
        $purgeTestData();
        rrmdir($uploadTmp);
    } catch (Throwable) {
        // A failing safety-net cleanup must never mask the test's own result.
    }
};
register_shutdown_function($runCleanup);

try {
    Request::setTestMethod('POST');
    Auth::boot();
    $_SESSION = [];
    // Wipe leftovers from a previous (possibly crashed) run: tenants first,
    // then plans — the FK-1451-safe order ($purgeTestData above).
    $purgeTestData();

    // A tight plan so limits are testable: 3 products, 2 images.
    $planId = (int) $db->insert('plans', [
        'name' => 'Product Test Plan', 'monthly_price' => 0,
        'max_products' => 3, 'max_staff_users' => 2, 'max_images' => 2,
    ]);

    // Shop A created through TenantModel::create (exercises preset seeding).
    $tenantId = (new TenantModel())->create([
        'name' => 'Prod T1', 'slug' => 'prod-t1', 'plan_id' => $planId,
        'subscription_expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400),
    ]);
    $db->execute('UPDATE shop_settings SET rounding_step = ? WHERE tenant_id = ?', [1000, $tenantId]);

    $ownerId = (int) $db->insert('users', [
        'tenant_id' => $tenantId, 'role' => 'owner', 'name' => 'Owner P',
        'email' => 'owner-p@test.local', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'can_see_cost' => 1,
    ]);
    $staffId = (int) $db->insert('users', [
        'tenant_id' => $tenantId, 'role' => 'staff', 'name' => 'Staff P',
        'email' => 'staff-p@test.local', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'can_see_cost' => 0,
    ]);

    // Shop B for isolation checks (raw insert, no preset needed).
    $tenantB = (int) $db->insert('tenants', ['name' => 'Prod T2', 'slug' => 'prod-t2', 'shop_type' => 'cosmetics']);
    $ownerB = (int) $db->insert('users', [
        'tenant_id' => $tenantB, 'role' => 'owner', 'name' => 'Owner B',
        'email' => 'owner-b2@test.local', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'can_see_cost' => 1,
    ]);

    $controller = new ProductController();
    $imageController = new ProductImageController();
    $model = new ProductModel();

    // Authenticate as shop A's owner.
    function loginAs(int $userId, int $tenantId, string $role, int $canSeeCost): void
    {
        Auth::boot();
        $_SESSION['user_id'] = $userId;
        $_SESSION['tenant_id'] = $tenantId;
        $_SESSION['role'] = $role;
        $_SESSION['can_see_cost'] = (bool) $canSeeCost;
        TenantContext::set((new TenantModel())->findById($tenantId));
    }

    loginAs($ownerId, $tenantId, 'owner', 1);

    // ----------------------------------------------------------------------
    // 1) Product CRUD + extra fields validation.
    // ----------------------------------------------------------------------
    Request::setTestInput([
        'name' => 'کرم آبرسان', 'category' => 'مراقبت پوست',
        'buy_price' => 500000, 'sell_price' => 900000, 'stock' => 12,
        'extra_fields' => [
            'brand' => 'لا روشه', 'volume' => 50, 'volume_unit' => 'میلی‌لیتر',
            'expiry_date' => '2027-05-01', 'batch_number' => 'B-2201', 'skin_type' => 'خشک',
            'barcode' => 'SKU-100',
        ],
    ]);
    $created = $controller->product_create();
    $p1 = $created['product'];
    check('create: sku from native barcode field', ($p1['sku'] ?? '') === 'SKU-100');
    check('create: extra fields stored', ($p1['extra_fields']['brand'] ?? '') === 'لا روشه'
        && ($p1['extra_fields']['expiry_date'] ?? '') === '2027-05-01');
    check('create: barcode NOT duplicated in extra_fields', !array_key_exists('barcode', $p1['extra_fields']));

    Request::setTestInput([
        'name' => 'شامپو ضدشوره', 'sell_price' => 350000, 'stock' => 3,
        'extra_fields' => ['brand' => 'کلیرز', 'expiry_date' => '2026-01-01'],
    ]);
    $p2 = $controller->product_create()['product'];
    check('create second product', ($p2['id'] ?? 0) > 0);

    Request::setTestInput([
        'name' => 'x', 'extra_fields' => ['not_in_schema' => 'v'],
    ]);
    expectApi(fn () => $controller->product_create(), 'INVALID_INPUT', 'unknown extra field rejected');

    Request::setTestInput([
        'name' => 'x', 'extra_fields' => ['volume' => 'abc'],
    ]);
    expectApi(fn () => $controller->product_create(), 'INVALID_INPUT', 'wrong-typed extra field rejected');

    Request::setTestInput([
        'name' => 'x', 'extra_fields' => ['volume_unit' => 'متر مکعب'],
    ]);
    expectApi(fn () => $controller->product_create(), 'INVALID_INPUT', 'choice outside options rejected');

    // Required-flag unit test on a synthetic schema (cosmetics has none required).
    $synthetic = [['key' => 'batch_number', 'label' => 'شماره بچ', 'type' => 'text', 'required' => true]];
    expectApi(fn () => FieldSchema::validate([], $synthetic), 'INVALID_INPUT', 'missing required extra field rejected');
    check('required field passes when present',
        (FieldSchema::validate(['batch_number' => 'B1'], $synthetic)['extra']['batch_number'] ?? '') === 'B1');

    // Jalali helper round-trips (PHP side).
    check('jalali 1403/01/01 -> 2024-03-20', Jalali::jalaliToSqlDate('1403/01/01') === '2024-03-20');
    check('jalali 1403/12/30 -> 2025-03-20 (leap)', Jalali::jalaliToSqlDate('۱۴۰۳/۱۲/۳۰') === '2025-03-20');
    check('jalali invalid 1404/12/30 -> null', Jalali::jalaliToSqlDate('1404/12/30') === null);
    check('jalali gregorian round-trip', Jalali::sqlDateToJalali(Jalali::jalaliToSqlDate('1405/08/26')) === '1405/08/26');

    // Update: partial + expiry change.
    Request::setTestInput([
        'id' => $p2['id'], 'extra_fields' => ['brand' => 'کلیرز', 'expiry_date' => '2026-12-01', 'skin_type' => 'چرب'],
    ]);
    $updated = $controller->product_update()['product'];
    check('update: extra fields replaced', ($updated['extra_fields']['expiry_date'] ?? '') === '2026-12-01'
        && ($updated['extra_fields']['skin_type'] ?? '') === 'چرب');

    // ----------------------------------------------------------------------
    // 2) Staff rules: no reading OR writing cost.
    // ----------------------------------------------------------------------
    loginAs($staffId, $tenantId, 'staff', 0);

    Request::setTestInput([
        'name' => 'محصول کارمند', 'buy_price' => 100, 'sell_price' => 200, 'stock' => 1,
    ]);
    expectApi(fn () => $controller->product_create(), 'FORBIDDEN_FIELD', 'staff cannot write buy_price');

    Request::setTestInput(['name' => 'محصول کارمند', 'sell_price' => 200000, 'stock' => 1]);
    $staffProduct = $controller->product_create()['product'];
    check('staff create: buy_price defaults to 0 (never sent)', (int) $staffProduct['buy_price'] === 0);

    // Central stripping: the raw controller payload contains buy_price (the
    // router's Response::ok() strips it) — prove the stripper removes it.
    $stripped = Permissions::stripSensitive(['product' => $staffProduct, 'list' => [$staffProduct]]);
    check('stripSensitive removes buy_price from product payloads',
        !array_key_exists('buy_price', $stripped['product']) && !array_key_exists('buy_price', $stripped['list'][0]));

    expectApi(fn () => $model->list(['sort' => 'buy_price']), 'FORBIDDEN_FIELD', 'staff cannot sort by buy_price');
    expectApi(fn () => $model->list(['sort' => 'profit']), 'FORBIDDEN_FIELD', 'staff cannot sort by profit');
    check('staff can sort by sell_price', $model->list(['sort' => 'sell_price']) !== []);
    Request::setTestMethod('GET');

    // ----------------------------------------------------------------------
    // 3) Plan limits (max_products = 3).
    // ----------------------------------------------------------------------
    loginAs($ownerId, $tenantId, 'owner', 1);
    Request::setTestMethod('POST');
    // Active so far: p1 + staff product = 2. Archive p2 first.
    Request::setTestInput(['id' => $p2['id']]);
    $controller->product_archive();
    Request::setTestInput(['name' => 'سوم', 'sell_price' => 100000, 'stock' => 1]);
    $p3 = $controller->product_create()['product'];
    check('third product within plan limit', ($p3['id'] ?? 0) > 0);
    Request::setTestInput(['name' => 'چهارم', 'sell_price' => 100000, 'stock' => 1]);
    expectApi(fn () => $controller->product_create(), 'PLAN_LIMIT_REACHED', 'plan max_products enforced');
    // Archived products free the quota: archive p3, then the 4th fits.
    Request::setTestInput(['id' => $p3['id']]);
    $controller->product_archive();
    Request::setTestInput(['name' => 'چهارم', 'sell_price' => 100000, 'stock' => 1]);
    $p4 = $controller->product_create()['product'];
    check('archived products free plan quota', ($p4['id'] ?? 0) > 0);

    // ----------------------------------------------------------------------
    // 4) Bulk price (owner only): percent / fixed / clamp / rounding / audit.
    // ----------------------------------------------------------------------
    Request::setTestInput(['mode' => 'percent', 'value' => '10', 'ids' => [$p1['id']]]);
    $controller->products_bulk_price();
    check('bulk percent +10% rounded to step', (int) $model->find($p1['id'])['sell_price'] === 990000);

    Request::setTestInput(['mode' => 'fixed', 'value' => '-۵۰۰۰', 'ids' => [$p1['id']], 'include_buy_price' => 1]);
    $controller->products_bulk_price();
    $row = $model->find($p1['id']);
    check('bulk fixed -5000 (persian digits) applied to sell+buy',
        (int) $row['sell_price'] === 985000 && (int) $row['buy_price'] === 495000);

    Request::setTestInput(['mode' => 'fixed', 'value' => '-999999', 'ids' => [$p1['id']]]);
    $controller->products_bulk_price();
    check('bulk clamps at 0', (int) $model->find($p1['id'])['sell_price'] === 0);

    $auditCount = (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'products.bulk_price' AND tenant_id = ?", [$tenantId]);
    check('bulk price audited', $auditCount === 3, "count=$auditCount");

    loginAs($staffId, $tenantId, 'staff', 0);
    Request::setTestInput(['mode' => 'percent', 'value' => '10', 'ids' => [$p1['id']]]);
    expectApi(fn () => $controller->products_bulk_price(), 'FORBIDDEN', 'bulk price is owner-only');
    loginAs($ownerId, $tenantId, 'owner', 1);

    // ----------------------------------------------------------------------
    // 5) Image pipeline (requires GD; skipped cleanly when missing).
    // ----------------------------------------------------------------------
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        out('SKIP: GD extension not loaded — image pipeline tests skipped.');
    } else {
        // Valid PNG via GD.
        $pngPath = $uploadTmp . '/src_valid.png';
        $im = imagecreatetruecolor(200, 150);
        imagefill($im, 0, 0, (imagecolorallocate($im, 120, 40, 40)));
        imagepng($im, $pngPath);
        imagedestroy($im);

        $names = Uploads::process(['tmp_name' => $pngPath, 'name' => 'x.png', 'size' => filesize($pngPath), 'error' => 0], $tenantId, $p1['id']);
        foreach (['file_name', 'medium_name', 'thumb_name'] as $col) {
            check("image: random name ($col)", (bool) preg_match('/^[0-9a-f]{32}(\.[a-z]+)?\.(webp|jpg)$/', $names[$col]), $names[$col]);
            check("image: file stored under tenant/product dir ($col)", is_file(Uploads::productDir($tenantId, $p1['id']) . '/' . $names[$col]));
        }
        check('image: output decodes cleanly (no payload survives)',
            @imagecreatefromstring((string) file_get_contents(Uploads::productDir($tenantId, $p1['id']) . '/' . $names['file_name'])) !== false);

        // PHP file renamed to .jpg is rejected.
        $fake = $uploadTmp . '/fake.jpg';
        file_put_contents($fake, "<?php echo 'pwn'; ?>");
        expectApi(fn () => Uploads::process(['tmp_name' => $fake, 'name' => 'fake.jpg', 'size' => filesize($fake), 'error' => 0], $tenantId, $p1['id']),
            'UPLOAD_BAD_TYPE', 'php file renamed .jpg rejected');

        // Polyglot: valid PNG header + appended PHP -> re-encoded clean.
        $poly = $uploadTmp . '/poly.png';
        copy($pngPath, $poly);
        file_put_contents($poly, "<?php evil(); ?>", FILE_APPEND);
        $names2 = Uploads::process(['tmp_name' => $poly, 'name' => 'poly.png', 'size' => filesize($poly), 'error' => 0], $tenantId, $p1['id']);
        $out = (string) file_get_contents(Uploads::productDir($tenantId, $p1['id']) . '/' . $names2['file_name']);
        check('polyglot: no PHP survives re-encoding', !str_contains($out, '<?php') && stripos($out, 'evil') === false);

        // Oversize file (>3 MB) rejected before any decoding.
        $big = $uploadTmp . '/big.png';
        file_put_contents($big, random_bytes(4 * 1024 * 1024));
        expectApi(fn () => Uploads::process(['tmp_name' => $big, 'name' => 'big.png', 'size' => filesize($big), 'error' => 0], $tenantId, $p1['id']),
            'UPLOAD_TOO_LARGE', 'oversize file rejected');

        // Crafted PNG header claiming 6001x6001 -> dimension cap (before decode).
        $ihdr = 'IHDR' . pack('N', 6001) . pack('N', 6001) . "\x08\x02\x00\x00\x00";
        $pngBig = "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
        $hdrPath = $uploadTmp . '/bigdims.png';
        file_put_contents($hdrPath, $pngBig);
        expectApi(fn () => Uploads::process(['tmp_name' => $hdrPath, 'name' => 'bigdims.png', 'size' => 13, 'error' => 0], $tenantId, $p1['id']),
            'UPLOAD_TOO_LARGE', '6000x6000 dimension cap enforced');

        // PHP upload error codes.
        expectApi(fn () => Uploads::process(['tmp_name' => $pngPath, 'name' => 'a.png', 'size' => 1, 'error' => UPLOAD_ERR_INI_SIZE], $tenantId, $p1['id']),
            'UPLOAD_TOO_LARGE', 'UPLOAD_ERR_INI_SIZE mapped');
        expectApi(fn () => Uploads::process(['tmp_name' => $pngPath, 'name' => 'a.png', 'size' => 1, 'error' => UPLOAD_ERR_PARTIAL], $tenantId, $p1['id']),
            'UPLOAD_PARTIAL', 'UPLOAD_ERR_PARTIAL mapped');
        expectApi(fn () => Uploads::process(['tmp_name' => $pngPath, 'name' => 'a.png', 'size' => 1, 'error' => UPLOAD_ERR_NO_FILE], $tenantId, $p1['id']),
            'UPLOAD_NO_FILE', 'UPLOAD_ERR_NO_FILE mapped');

        // post_max_size overflow: empty $_FILES + big Content-Length.
        $_SERVER['CONTENT_LENGTH'] = 20 * 1024 * 1024;
        $savedFiles = $_FILES;
        $_FILES = [];
        expectApi(fn () => Uploads::assertRequestReadable(), 'POST_TOO_LARGE', 'post_max_size overflow detected');
        $_FILES = $savedFiles;
        unset($_SERVER['CONTENT_LENGTH']);

        // Controller-level upload: 2 images (plan max_images = 2), then the 3rd fails.
        $uploadOnce = function (int $n) use ($imageController, $pngPath, $tenantId, $p1): array {
            // The controller reads product_id from the request (never from
            // $_FILES) — without it productId() throws INVALID_INPUT.
            Request::setTestInput(['product_id' => $p1['id']]);
            $files = ['name' => [], 'tmp_name' => [], 'size' => [], 'error' => []];
            for ($i = 0; $i < $n; $i++) {
                $files['name'][] = "v$i.png";
                $files['tmp_name'][] = $pngPath;
                $files['size'][] = filesize($pngPath);
                $files['error'][] = 0;
            }
            $saved = $_FILES;
            $_FILES['files'] = $files;
            try {
                return $imageController->product_image_upload();
            } finally {
                $_FILES = $saved;
            }
        };

        $up1 = null;
        try {
            $up1 = $uploadOnce(2);
            check('upload: two images accepted', ($up1['inserted'] ?? 0) === 2 && count($up1['images']) === 2);
            check('upload: cover is position 1', (int) $up1['images'][0]['position'] === 1);
        } catch (Throwable $e) {
            check('upload: two images accepted', false, errDetail($e));
        }

        if ($up1 !== null) {
            // Per-REQUEST cap: 6 files in one request -> UPLOAD_TOO_MANY, a
            // DISTINCT code from the per-product limit, checked BEFORE it.
            expectApi(fn () => $uploadOnce(6), 'UPLOAD_TOO_MANY', 'max 5 files per request enforced');

            // Per-PRODUCT limit: plan max_images = 2 and both slots are used.
            expectApi(function () use ($uploadOnce) { $uploadOnce(1); }, 'IMAGE_LIMIT_REACHED', 'per-product image limit enforced');

            $ids = array_map(static fn (array $r): int => (int) $r['id'], $up1['images']);
            $fileOf = [];
            foreach ($up1['images'] as $row) {
                $fileOf[(int) $row['id']] = (string) $row['file_name'];
            }

            // Swap the two images (transactional renumber). Every step below
            // is guarded so one failure reports a FAIL and the rest still runs.
            try {
                Request::setTestInput(['product_id' => $p1['id'], 'ids' => [$ids[1], $ids[0]]]);
                $swapped = $imageController->product_image_reorder();
                check('reorder: swap keeps positions 1..2', (int) $swapped['images'][0]['id'] === $ids[1] && (int) $swapped['images'][0]['position'] === 1
                    && (int) $swapped['images'][1]['position'] === 2);
            } catch (Throwable $e) {
                check('reorder: swap keeps positions 1..2', false, errDetail($e));
            }

            // Set cover: the second image becomes position 1.
            try {
                Request::setTestInput(['product_id' => $p1['id'], 'id' => $ids[0]]);
                $cover = $imageController->product_image_set_cover();
                check('set cover promotes to position 1', (int) $cover['images'][0]['id'] === $ids[0] && (int) $cover['images'][0]['position'] === 1);
            } catch (Throwable $e) {
                check('set cover promotes to position 1', false, errDetail($e));
            }

            // Delete the cover -> the other image is renumbered to position 1.
            try {
                Request::setTestInput(['product_id' => $p1['id'], 'id' => $ids[0]]);
                $after = $imageController->product_image_delete();
                check('delete cover promotes the next image', (int) $after['images'][0]['position'] === 1 && (int) $after['images'][0]['id'] === $ids[1]);
                check('delete removes files from disk', !is_file(Uploads::productDir($tenantId, $p1['id']) . '/' . $fileOf[$ids[0]]));
            } catch (Throwable $e) {
                check('delete cover promotes the next image', false, errDetail($e));
            }
        }

        // Audit entries for image operations.
        $imgAudit = (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'product_image.%' AND tenant_id = ?", [$tenantId]);
        check('image operations audited', $imgAudit >= 4, "count=$imgAudit");
    }

    // ----------------------------------------------------------------------
    // 6) Expiring-soon filter (SQL, works on MySQL 8 AND MariaDB).
    // ----------------------------------------------------------------------
    // p4: expiring within 30 days; p1: no expiry; p2 (archived, excluded);
    // create one expired + one invalid-date + one far-future product.
    $mk = function (string $name, ?string $expiry) use ($model): int {
        $extra = $expiry === null ? [] : ['expiry_date' => $expiry];
        return $model->insert([
            'name' => $name, 'sell_price' => 100000, 'stock' => 5,
            'extra_fields' => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
        ]);
    };
    $expiringId = $mk('نزدیک انقضا', date('Y-m-d', time() + 10 * 86400));
    $expiredId = $mk('منقضی', date('Y-m-d', time() - 5 * 86400));
    $farId = $mk('دور', date('Y-m-d', time() + 200 * 86400));
    $invalidId = $mk('تاریخ خراب', 'not-a-date');

    $expiringList = $model->list(['expiring' => true, 'days' => 30, 'archived' => 'all']);
    $expiringIds = array_map(static fn (array $r): int => (int) $r['id'], $expiringList['items']);
    check('expiring_soon filter matches only soon items',
        in_array($expiringId, $expiringIds, true)
        && !in_array($expiredId, $expiringIds, true)
        && !in_array($farId, $expiringIds, true)
        && !in_array($invalidId, $expiringIds, true),
        'ids=' . implode(',', $expiringIds));
    check('invalid date excluded by REGEXP guard (engine-safe)', !in_array($invalidId, $expiringIds, true));

    // PHP-side flags on the item.
    $flags = ProductModel::expiryFlags(['expiry_date' => date('Y-m-d', time() + 10 * 86400)], 30);
    check('expiryFlags: expiring soon', $flags['expiring'] === true && $flags['expired'] === false);
    $flags2 = ProductModel::expiryFlags(['expiry_date' => 'garbage'], 30);
    check('expiryFlags: invalid date -> no flags', $flags2['expired'] === false && $flags2['expiring'] === false && $flags2['expiry_date'] === null);

    // Capture shop A's remaining image ids BEFORE switching sessions (used in
    // section 7 to prove shop B cannot touch them).
    $aImageIds = array_map(
        static fn (array $r): int => (int) $r['id'],
        (new ProductImageModel())->listForProduct($p1['id'])
    );

    // ----------------------------------------------------------------------
    // 7) Cross-tenant isolation for products + images.
    // ----------------------------------------------------------------------
    loginAs($ownerB, $tenantB, 'owner', 1);
    Request::setTestInput(['id' => $p1['id']]);
    expectApi(fn () => $controller->product_get(), 'NOT_FOUND', 'shop B cannot read shop A product');
    if ($aImageIds !== []) {
        Request::setTestInput(['product_id' => $p1['id'], 'id' => $aImageIds[0]]);
        expectApi(fn () => $imageController->product_image_delete(), 'NOT_FOUND', 'shop B cannot delete shop A image');
        Request::setTestInput(['product_id' => $p1['id'], 'id' => $aImageIds[0]]);
        expectApi(fn () => $imageController->product_image_set_cover(), 'NOT_FOUND', 'shop B cannot reorder shop A images');
    } else {
        check('shop B cannot delete shop A image', true, 'skipped: shop A has no images (GD missing)');
        check('shop B cannot reorder shop A images', true, 'skipped: shop A has no images (GD missing)');
    }
    // Injected tenant_id is refused through the base model (RuntimeException).
    try {
        $model->insert(['name' => 'x', 'tenant_id' => $tenantB]);
        check('injected tenant_id refused', false, 'no exception thrown');
    } catch (RuntimeException $e) {
        check('injected tenant_id refused', str_contains($e->getMessage(), 'tenant_id'), $e->getMessage());
    } catch (Throwable $e) {
        check('injected tenant_id refused', false, errDetail($e));
    }

    // ----------------------------------------------------------------------
    // 8) Expired tenant: reads allowed, image upload rejected.
    // ----------------------------------------------------------------------
    $db->execute('UPDATE tenants SET subscription_status = ? WHERE id = ?', ['expired', $tenantId]);
    loginAs($ownerId, $tenantId, 'owner', 1); // refreshes TenantContext after the UPDATE
    Request::setTestMethod('POST');
    expectApi(fn () => RequireActiveSubscription::handle(), 'SUBSCRIPTION_EXPIRED', 'expired tenant: upload rejected');
    Request::setTestMethod('GET');
    try {
        RequireActiveSubscription::handle();
        check('expired tenant: reads allowed', true);
    } catch (Throwable $e) {
        check('expired tenant: reads allowed', false, errDetail($e));
    }
    $db->execute('UPDATE tenants SET subscription_status = ? WHERE id = ?', ['active', $tenantId]);
    Request::setTestMethod('POST');

    // Products list through the controller (owner): search by brand + images.
    Request::setTestMethod('GET');
    Request::setTestInput(['q' => 'لا روشه', 'per_page' => '5']);
    $listed = $controller->products_list();
    $first = $listed['list']['items'][0] ?? null;
    check('products_list: search by brand (JSON field) works',
        $first !== null && (int) $first['id'] === (int) $p1['id'],
        'total=' . $listed['list']['total']);
    check('products_list: schema returned for the dialog', is_array($listed['schema'] ?? null) && count($listed['schema']) >= 5);
    check('products_list: images embedded on item', is_array($first['images'] ?? null));
    Request::setTestMethod('POST');

    // ----------------------------------------------------------------------
    // 9) Numeric input hardening (Text::toInt) + the is_low_stock flag.
    // ----------------------------------------------------------------------
    // The shared strict parser itself.
    check('Text::toInt: ۱٬۲۳۴٬۵۶۷', Text::toInt('۱٬۲۳۴٬۵۶۷') === 1234567, var_export(Text::toInt('۱٬۲۳۴٬۵۶۷'), true));
    check('Text::toInt: 1,234,567 (ASCII commas)', Text::toInt('1,234,567') === 1234567);
    check('Text::toInt: ۱۲۳۴ (plain persian digits)', Text::toInt('۱۲۳۴') === 1234);
    check('Text::toInt: 1 234 (spaces)', Text::toInt('1 234') === 1234);
    check('Text::toInt: plain int', Text::toInt('1250000') === 1250000);
    check('Text::toInt: garbage -> null', Text::toInt('12abc') === null);
    check('Text::toInt: negative -> null', Text::toInt('-5') === null);
    check('Text::toInt: decimal -> null', Text::toInt('12.5') === null);
    check('Text::toInt: empty -> null', Text::toInt('') === null);

    // Controller accepts digit/separated STRINGS and stores exact integers.
    // product_update (not create): the tight plan is already at its limit.
    Request::setTestInput([
        'id' => $p1['id'],
        'sell_price' => '1,234,567',
        'buy_price' => '۱٬۲۳۴٬۵۶۷',
        'stock' => '۱۲',
        'low_stock_threshold' => '۲',
    ]);
    $upNum = $controller->product_update();
    check('update: comma/persian inputs stored as exact integers',
        (int) $upNum['product']['sell_price'] === 1234567
        && (int) $upNum['product']['buy_price'] === 1234567
        && (int) $upNum['product']['stock'] === 12
        && (int) $upNum['product']['low_stock_threshold'] === 2,
        var_export([$upNum['product']['sell_price'], $upNum['product']['stock']], true));

    // A real number works too; anything non-integer/garbage is refused with
    // a clear Persian message (INVALID_INPUT), never stored as 0.
    Request::setTestInput(['id' => $p1['id'], 'sell_price' => 990000]);
    check('update: real int accepted', (int) $controller->product_update()['product']['sell_price'] === 990000);

    $badInputs = [
        ['sell_price' => '12abc', 'msg' => 'garbage price refused'],
        ['sell_price' => '12.5', 'msg' => 'decimal price refused'],
        ['sell_price' => '-3', 'msg' => 'negative price refused'],
        ['stock' => '12abc', 'msg' => 'garbage stock refused'],
        ['stock' => '-1', 'msg' => 'negative stock refused'],
        ['low_stock_threshold' => '۱٫۵', 'msg' => 'decimal threshold refused'],
    ];
    foreach ($badInputs as $bad) {
        Request::setTestInput(['id' => $p1['id']] + $bad);
        expectApi(fn () => $controller->product_update(), 'INVALID_INPUT', $bad['msg']);
    }

    // Bulk "fixed" value as a Persian digit string with thousands separators.
    // $expiringId has sell_price 100000; +۱٬۰۰۰ with rounding_step 1000 is exact.
    $db->execute('UPDATE products SET sell_price = 100000 WHERE id = ?', [$expiringId]);
    Request::setTestInput(['mode' => 'fixed', 'value' => '۱٬۰۰۰', 'ids' => [$expiringId]]);
    $updatedBulk = $controller->products_bulk_price();
    check('bulk: persian-comma fixed value applied',
        (int) $updatedBulk['updated'] === 1
        && (int) $db->value('SELECT sell_price FROM products WHERE id = ?', [$expiringId]) === 101000,
        var_export($db->value('SELECT sell_price FROM products WHERE id = ?', [$expiringId]), true));

    // --- is_low_stock: ONE rule (product threshold if > 0, else shop default).
    // Shop default = 2 for this section (was the schema default 3 before).
    $db->execute('UPDATE shop_settings SET low_stock_threshold = 2 WHERE tenant_id = ?', [$tenantId]);
    $lowDefault = $model->insert(['name' => 'کم‌موجودی پیش‌فرض', 'sell_price' => 1000, 'stock' => 2]);
    $lowHealthy = $model->insert(['name' => 'موجودی سالم', 'sell_price' => 1000, 'stock' => 3]);
    $lowOverride = $model->insert([
        'name' => 'کم‌موجودی آستانه', 'sell_price' => 1000, 'stock' => 3, 'low_stock_threshold' => 5,
    ]);
    check('is_low_stock: threshold 0 uses shop default (stock 2 <= 2)',
        $model->findWithImages($lowDefault)['is_low_stock'] === true);
    check('is_low_stock: threshold 0 uses shop default (stock 3 > 2)',
        $model->findWithImages($lowHealthy)['is_low_stock'] === false);
    check('is_low_stock: product threshold 5 overrides shop default',
        $model->findWithImages($lowOverride)['is_low_stock'] === true);

    // The low_stock FILTER must agree with the flag on EVERY product.
    $all = $model->list(['archived' => 'all', 'per_page' => '50']);
    $flagged = array_map(static fn (array $r): int => (int) $r['id'],
        array_filter($all['items'], static fn (array $r): bool => $r['is_low_stock'] === true));
    $filtered = $model->list(['low_stock' => true, 'archived' => 'all', 'per_page' => '50']);
    $filterIds = array_map(static fn (array $r): int => (int) $r['id'], $filtered['items']);
    sort($flagged);
    sort($filterIds);
    check('low_stock filter matches is_low_stock flags exactly', $flagged === $filterIds,
        'flags=' . implode(',', $flagged) . ' filter=' . implode(',', $filterIds));

    // Restore the schema default so nothing else depends on our override.
    $db->execute('UPDATE shop_settings SET low_stock_threshold = 3 WHERE tenant_id = ?', [$tenantId]);

    // ----------------------------------------------------------------------
    // 10) buy_price through the WHOLE pipeline (controller + Response::ok
    //     serialization + central stripping). Regression for the bug where
    //     stripSensitive() removed buy_price from owners' responses too.
    // ----------------------------------------------------------------------
    // The plan-limit sequence in section 5 has already been tested; lift the
    // quota so this section's creates are not blocked by it.
    $db->execute('UPDATE plans SET max_products = 50 WHERE id = ?', [$planId]);

    // OWNER: cost visibility must be granted and the payload must survive.
    loginAs($ownerId, $tenantId, 'owner', 1);
    check('owner: can(see_cost) is true', Permissions::can('see_cost') === true);

    // Create with JSON-number inputs (the browser sends numbers).
    Request::setTestInput([
        'name' => 'خرید روشن', 'buy_price' => 750000, 'sell_price' => 840000, 'stock' => 10,
    ]);
    $created = $controller->product_create();
    $bpId = (int) $created['product']['id'];
    check('buy e2e: create stores buy_price in the DB',
        (int) $db->value('SELECT buy_price FROM products WHERE id = ?', [$bpId]) === 750000,
        var_export($db->value('SELECT buy_price FROM products WHERE id = ?', [$bpId]), true));
    $ownerCreate = Response::serializeOk($created);
    check('buy e2e: create response keeps buy_price for the owner',
        (int) $ownerCreate['data']['product']['buy_price'] === 750000
        && (int) $ownerCreate['data']['product']['sell_price'] === 840000);

    // Same product, prices sent as Persian/comma STRINGS.
    Request::setTestInput(['id' => $bpId, 'buy_price' => '۷۵۰٬۰۰۰', 'sell_price' => '۸۴۰,۰۰۰']);
    $stringUpdate = $controller->product_update();
    check('buy e2e: update with persian/comma strings stores exact integers',
        (int) $db->value('SELECT buy_price FROM products WHERE id = ?', [$bpId]) === 750000
        && (int) $db->value('SELECT sell_price FROM products WHERE id = ?', [$bpId]) === 840000);
    check('buy e2e: update response keeps buy_price for the owner',
        (int) Response::serializeOk($stringUpdate)['data']['product']['buy_price'] === 750000);

    // product_get and products_list keep it for the owner too.
    Request::setTestMethod('GET');
    Request::setTestInput(['id' => $bpId]);
    $got = Response::serializeOk($controller->product_get());
    check('buy e2e: product_get keeps buy_price for the owner',
        (int) $got['data']['product']['buy_price'] === 750000);
    Request::setTestInput(['q' => 'خرید روشن']);
    $listedOwner = Response::serializeOk($controller->products_list());
    $bpItem = $listedOwner['data']['list']['items'][0] ?? null;
    check('buy e2e: products_list keeps buy_price for the owner',
        $bpItem !== null && (int) $bpItem['buy_price'] === 750000
        && (int) $bpItem['sell_price'] === 840000);

    // PARTIAL update that does NOT send buy_price must not reset it to 0.
    Request::setTestInput(['id' => $bpId, 'name' => 'خرید روشن ۲']);
    $renamed = $controller->product_update();
    check('buy e2e: partial update keeps buy_price',
        (int) $db->value('SELECT buy_price FROM products WHERE id = ?', [$bpId]) === 750000
        && (int) Response::serializeOk($renamed)['data']['product']['buy_price'] === 750000);

    // STAFF without can_see_cost: cost stripped everywhere, writing refused.
    loginAs($staffId, $tenantId, 'staff', 0);
    check('staff: can(see_cost) is false', Permissions::can('see_cost') === false);
    $staffView = Response::serializeOk($controller->product_get());
    check('buy e2e: staff response strips buy_price',
        array_key_exists('buy_price', $staffView['data']['product']) === false);
    Request::setTestInput(['q' => 'خرید روشن']);
    $staffList = Response::serializeOk($controller->products_list());
    $staffItem = $staffList['data']['list']['items'][0] ?? null;
    check('buy e2e: staff list strips buy_price on every item',
        $staffItem === null || array_key_exists('buy_price', $staffItem) === false);
    Request::setTestMethod('POST');
    Request::setTestInput(['id' => $bpId, 'buy_price' => 1]);
    expectApi(fn () => $controller->product_update(), 'FORBIDDEN_FIELD', 'staff buy_price write refused');

    // STAFF WITH can_see_cost = 1: sees and writes cost like an owner.
    $db->execute('UPDATE users SET can_see_cost = 1 WHERE id = ?', [$staffId]);
    loginAs($staffId, $tenantId, 'staff', 1);
    check('staff with flag: can(see_cost) is true', Permissions::can('see_cost') === true);
    $staffFlagView = Response::serializeOk($controller->product_get());
    check('buy e2e: staff with flag sees buy_price',
        (int) $staffFlagView['data']['product']['buy_price'] === 750000);
    Request::setTestInput(['id' => $bpId, 'buy_price' => 900000]);
    $staffWrite = $controller->product_update();
    check('buy e2e: staff with flag can write buy_price',
        (int) $db->value('SELECT buy_price FROM products WHERE id = ?', [$bpId]) === 900000
        && (int) Response::serializeOk($staffWrite)['data']['product']['buy_price'] === 900000);

    // Restore the seed staff flag (tidiness; cleanup purges the tenant anyway).
    $db->execute('UPDATE users SET can_see_cost = 0 WHERE id = ?', [$staffId]);
    loginAs($ownerId, $tenantId, 'owner', 1);

    // ----------------------------------------------------------------------
    // Cleanup (the same steps also run as a finally/shutdown safety net).
    // ----------------------------------------------------------------------
    $runCleanup();
    check('uploads temp dir cleaned', !is_dir($uploadTmp));
} catch (Throwable $e) {
    check('UNCAUGHT exception (crash guard)', false,
        errDetail($e) . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    // Runs after the catch on ANY exit path of the main body (exception,
    // early return, normal end) so a crash never leaves the DB unusable.
    $runCleanup();
}

restore_error_handler();
foreach ($warnings as $i => $warning) {
    check('no PHP warning #' . ($i + 1), false, $warning);
}

out('');
out("Results: {$pass} passed, {$fail} failed.");
if ($fail === 0) {
    out('ALL PASSED');
    ob_end_flush();
    exit(0);
}
ob_end_flush();
exit(1);
