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

    // Show detailed error messages (NEVER true in production).
    'debug' => false,

    'session' => [
        // Cookie name. Session cookies are HttpOnly, SameSite=Lax,
        // and Secure automatically when the request is HTTPS.
        'name' => 'shopam_sess',
    ],

    'uploads' => [
        // Real upload limit per image, enforced server-side (see Helpers/Uploads.php in step 3).
        'max_bytes'              => 3145728, // 3 MB
        'max_images_per_product' => 5,
        // Where generated thumbnails/medium images are stored, per tenant:
        // {dir}/{tenant_id}/{size}/xxxx.randomname
        'dir'                    => __DIR__ . '/public/uploads',
    ],

    // All dates are stored as UTC/Gregorian. Display is Jalali on the client.
    'timezone' => 'UTC',
];
