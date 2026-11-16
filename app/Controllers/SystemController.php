<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

/**
 * Health-check actions (no auth).
 */
final class SystemController
{
    public static function ping(): array
    {
        return ['php' => PHP_VERSION, 'time' => gmdate('c')];
    }

    public static function db_check(): array
    {
        return ['mysql' => (string) Database::get()->value('SELECT VERSION()')];
    }
}
