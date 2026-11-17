<?php

/**
 * Shopam configuration — copy this file to config.php and fill in real values.
 * config.php is gitignored and must never be committed.
 *
 * Deployment: upload the project folder, import schema.sql, edit config.php.
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'shopam',
        'user'    => 'shopam_user',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // Public base URL of the app, with trailing slash.
    'base_url' => 'http://shopam.test/',

    // 'development' enables dev-only tooling (seed_demo.php). Missing key = 'production'.
    'env' => 'development',

    // Show detailed error messages (NEVER true in production).
    'debug' => false,

    'session' => [
        // Shop users and super-admins use SEPARATE session cookies/namespaces.
        'name'                 => 'shopam_sess',
        'admin_name'           => 'shopam_admin_sess',
        // Lifetime limits (checked in Auth::checkFreshness).
        'user_idle_minutes'    => 120,
        'user_absolute_hours'  => 720,   // 30 days
        'admin_idle_minutes'   => 30,
        'admin_absolute_hours' => 8,
    ],

    'auth' => [
        'min_password_length'      => 8,
        'max_attempts_per_account' => 5,   // failures per account (slug + identifier) ...
        'account_lock_minutes'     => 15,  // ... within this window -> locked
        'max_attempts_per_ip'      => 30,  // per IP (Iranian mobile users often share one IP)
        'attempt_history_days'     => 30,  // opportunistic cleanup of old rows
    ],

    'uploads' => [
        // Real upload limit per image, enforced server-side (see Helpers/Uploads.php in step 3).
        'max_bytes'              => 3145728, // 3 MB
        'max_images_per_product' => 5,
        // Generated images are stored per tenant/product:
        // {dir}/{tenant_id}/{product_id}/{random32}[.m|.t].webp
        // (full ~1600px, .m medium ~1000px, .t thumb ~400px).
        'dir'                    => __DIR__ . '/public/uploads',
    ],

    // All dates are stored as UTC/Gregorian. Display is Jalali on the client.
    'timezone' => 'UTC',
];
