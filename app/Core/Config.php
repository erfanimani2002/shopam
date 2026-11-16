<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Static access to the loaded configuration array (config.php or a test config).
 * Supports dot notation: Config::get('db.name').
 */
final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException('Config file must return an array: ' . $file);
        }
        self::$data = $data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
