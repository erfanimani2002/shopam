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

    /**
     * TEST-ONLY override for a single key (used to redirect uploads.dir to a
     * temp directory so tests never write into public/uploads).
     */
    public static function setForTests(string $key, mixed $value): void
    {
        if (!str_ends_with((string) (self::$data['db']['name'] ?? ''), '_test')) {
            throw new \RuntimeException('Config::setForTests is only allowed on *_test databases.');
        }
        $parts = explode('.', $key);
        $node = &self::$data;
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }
}
