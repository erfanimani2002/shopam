<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application bootstrap: registers the autoloader, loads the config file and
 * sets up baseline runtime settings. Used by api/index.php and by tests
 * (tests pass their own config file, e.g. tests/test_config.php).
 */
final class Bootstrap
{
    private static string $root = '';

    public static function start(string $rootDir, ?string $configFile = null): void
    {
        self::$root = rtrim($rootDir, '/\\');

        // PSR-4-style autoloader for the App\ namespace, no Composer needed.
        spl_autoload_register([self::class, 'autoload']);

        $file = $configFile ?? self::$root . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            echo "Configuration missing. Copy config.example.php to config.php and fill it in.\n";
            exit(1);
        }
        Config::load($file);

        date_default_timezone_set((string) Config::get('timezone', 'UTC'));
        mb_internal_encoding('UTF-8');

        if ((bool) Config::get('debug', false)) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
        }
    }

    public static function root(): string
    {
        return self::$root;
    }

    /** @internal autoloader */
    public static function autoload(string $class): void
    {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = self::$root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
}
