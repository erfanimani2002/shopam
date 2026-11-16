<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Request input abstraction. Controllers/middleware read only through this,
 * so tests can inject input without a web server (Request::setTestInput).
 * Accepts query params, form fields and JSON bodies.
 */
final class Request
{
    private static ?array $testInput = null;
    private static ?string $testMethod = null;
    private static ?array $jsonBody = null;

    /** Tests only. */
    public static function setTestInput(?array $data): void
    {
        self::$testInput = $data;
        self::$jsonBody = null;
    }

    /** Tests only. */
    public static function setTestMethod(?string $method): void
    {
        self::$testMethod = $method;
    }

    public static function method(): string
    {
        return strtoupper(self::$testMethod ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        if (self::$testInput !== null) {
            return self::$testInput[$key] ?? $default;
        }
        $json = self::jsonBody();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }
        return $default;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** @return array<string, mixed> */
    private static function jsonBody(): array
    {
        if (self::$jsonBody !== null) {
            return self::$jsonBody;
        }
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($type, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            self::$jsonBody = is_array($decoded) ? $decoded : [];
        } else {
            self::$jsonBody = [];
        }
        return self::$jsonBody;
    }
}
