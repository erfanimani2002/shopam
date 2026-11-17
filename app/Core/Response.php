<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON API responses with ONE consistent shape:
 *   success: { ok: true,  data:  {...} }
 *   failure: { ok: false, error: { code: ENGLISH_CODE, message: "پیام فارسی" } }
 *
 * Response::ok() strips cost/profit fields centrally (Permissions::stripSensitive)
 * whenever the session user lacks can_see_cost (owners always keep them), so
 * controllers cannot forget it.
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

    /**
     * The full success payload WITHOUT sending it (unit-testable).
     * Cost/profit stripping is gated on Permissions::can('see_cost'):
     * owners (and staff with the owner-set flag) keep buy_price/profit,
     * everyone else gets the stripped payload. Stripping must NEVER run
     * unconditionally — that once hid buy_price from owners too.
     */
    public static function serializeOk(array $data): array
    {
        return [
            'ok'   => true,
            'data' => Permissions::can('see_cost') ? $data : Permissions::stripSensitive($data),
        ];
    }

    public static function ok(array $data = []): never
    {
        self::json(self::serializeOk($data));
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
