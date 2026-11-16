<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\ApiException;

/**
 * Permission helper. Roles are about permissions only, never profit sharing.
 * Owners always pass; staff depend on the owner-set user flags.
 *
 * Cost/profit stripping lives here too: the SAME stripper is used by
 * Response::ok() (JSON) and later by the CSV export, so cost fields can never
 * leak to staff without can_see_cost. Any sort/filter on a cost field must go
 * through assertCanSortOrFilter().
 */
final class Permissions
{
    /** THE single list of sensitive keys. Keep it in one place. */
    public const SENSITIVE_FIELDS = [
        'buy_price', 'cost_total', 'line_cost', 'profit', 'gross_profit',
        'net_profit', 'margin', 'profit_margin', 'unit_cost',
    ];

    public static function can(string $permission): bool
    {
        if (Auth::role() === 'owner') {
            return true;
        }
        return match ($permission) {
            'see_cost' => (bool) ($_SESSION['can_see_cost'] ?? false),
            default    => false,
        };
    }

    /**
     * Recursively remove sensitive keys from a payload (nested arrays,
     * lists of rows, everything). Comparison is case-insensitive.
     */
    public static function stripSensitive(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_FIELDS, true)) {
                continue;
            }
            $out[$key] = is_array($value) ? self::stripSensitive($value) : $value;
        }
        return $out;
    }

    /**
     * Controllers must call this before sorting/filtering by a client-supplied
     * field name; cost fields are refused for users without can_see_cost.
     */
    public static function assertCanSortOrFilter(string $field): void
    {
        if (in_array(strtolower($field), self::SENSITIVE_FIELDS, true) && !self::can('see_cost')) {
            throw new ApiException('FORBIDDEN_FIELD', 403);
        }
    }
}
