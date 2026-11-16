<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON API response helpers. All API output is JSON, UTF-8, unescaped Persian.
 */
final class Response
{
    /** @param mixed $data */
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @param array<string, mixed> $extra */
    public static function error(string $error, int $status = 400, array $extra = []): never
    {
        self::json(['ok' => false, 'error' => $error] + $extra, $status);
    }

    public static function ok(array $data = []): never
    {
        self::json(['ok' => true] + $data);
    }
}
