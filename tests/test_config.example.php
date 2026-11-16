<?php

/**
 * Test configuration — copy to test_config.php (gitignored) and fill in.
 *
 * SAFETY: tests/isolation_test.php refuses to run unless the database name
 * below ends with "_test". Create a dedicated database for tests, e.g.:
 *   CREATE DATABASE shopam_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * then import schema.sql into it once:
 *   mysql -u USER -p shopam_test < schema.sql
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'shopam_test',   // MUST end with _test
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'debug' => true,
];
