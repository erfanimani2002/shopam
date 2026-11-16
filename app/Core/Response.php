<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON API responses with ONE consistent shape:
 *   success: { ok: true,  data:  {...} }
 *   failure: { ok: false, error: { code: ENGLISH_CODE, message: "پیام فارسی" } }
 *
 * Response::ok() strips cost/profit fields centrally (Permissions::stripSensitive)
 * whenever the session user lacks can_see_cost, so controllers cannot forget it.
 */
final class Response
{
    /** @param mixed $data raw JSON-encodable payload */
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = []): never
    {
        self::json(['ok' => true, 'data' => Permissions::stripSensitive($data)]);
    }

    /** English code + Persian message (central map when message is null). */
    public static function error(string $code, int $status = 400, ?string $message = null): never
    {
        self::json([
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message ?? ErrorMessages::get($code)],
        ], $status);
    }
}
