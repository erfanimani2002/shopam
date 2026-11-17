<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\ApiException;
use App\Core\Database;
use App\ShopTypes\ShopTypes;
use Throwable;

/**
 * Validates client-supplied product extra_fields against the tenant's
 * data-driven field schema (from tenants.field_schema, falling back to the
 * shop type preset). THE single place extra fields are validated.
 *
 * Schema entry shape (written by the shop type preset):
 *   key, label, type (text|number|date|choice), in_list, in_search,
 *   options (choice), unit, native (products column instead of extra_fields).
 */
final class FieldSchema
{
    /** Max stored length of any text extra field. */
    private const MAX_TEXT = 190;

    /**
     * The field schema for a tenant: tenants.field_schema when present,
     * otherwise the preset of the tenant's shop_type.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forTenant(int $tenantId): array
    {
        $db = Database::get();
        $raw = $db->value('SELECT field_schema FROM tenants WHERE id = ?', [$tenantId]);
        $schema = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($schema) && $schema !== []) {
            return $schema;
        }
        $type = (string) ($db->value('SELECT shop_type FROM tenants WHERE id = ?', [$tenantId]) ?? '');
        try {
            return ShopTypes::preset($type)->fieldSchema();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Validate + normalize client extra_fields against the schema.
     *
     * @param array<string, mixed> $extra  client extra_fields (assoc)
     * @param array<int, array<string, mixed>> $schema
     * @return array{extra: array<string, mixed>, native: array<string, mixed>}
     *         extra: cleaned values for products.extra_fields (JSON)
     *         native: values for native products columns (e.g. sku)
     * @throws ApiException INVALID_INPUT with a Persian message on any problem
     */
    public static function validate(array $extra, array $schema): array
    {
        $byKey = [];
        foreach ($schema as $field) {
            $byKey[(string) ($field['key'] ?? '')] = $field;
        }

        $problems = [];
        $clean = [];
        $native = [];

        foreach ($extra as $key => $value) {
            if (!isset($byKey[$key])) {
                $problems[] = 'فیلد ناشناخته: ' . $key;
                continue;
            }
            $field = $byKey[$key];
            $label = (string) ($field['label'] ?? $key);
            $type = (string) ($field['type'] ?? 'text');
            $required = !empty($field['required']);

            $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);
            if ($isEmpty) {
                if ($required) {
                    $problems[] = "«{$label}» الزامی است.";
                }
                continue; // empty optional fields are simply not stored
            }

            switch ($type) {
                case 'text':
                    $v = trim((string) $value);
                    if (mb_strlen($v) > self::MAX_TEXT) {
                        $problems[] = "«{$label}» حداکثر " . self::MAX_TEXT . " کاراکتر.";
                    } else {
                        self::store($clean, $native, $field, $v);
                    }
                    break;

                case 'number':
                    $v = Text::normalizeDigits(trim((string) $value));
                    if (!is_numeric($v)) {
                        $problems[] = "«{$label}» باید عدد باشد.";
                    } else {
                        $num = str_contains($v, '.') ? (float) $v : (int) $v;
                        self::store($clean, $native, $field, $num);
                    }
                    break;

                case 'date':
                    $v = is_string($value) ? trim($value) : '';
                    // Gregorian YYYY-MM-DD (the Jalali UI converts before sending)
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)
                        || strtotime($v) === false
                        || substr($v, 5, 2) === '00' || substr($v, 8, 2) === '00'
                        || (int) substr($v, 5, 2) > 12 || (int) substr($v, 8, 2) > 31) {
                        $problems[] = "«{$label}» تاریخ نامعتبر است.";
                    } else {
                        self::store($clean, $native, $field, $v);
                    }
                    break;

                case 'choice':
                    $options = array_map('strval', (array) ($field['options'] ?? []));
                    $v = trim((string) $value);
                    if (!in_array($v, $options, true)) {
                        $problems[] = "«{$label}» یکی از گزینه‌های مجاز نیست.";
                    } else {
                        self::store($clean, $native, $field, $v);
                    }
                    break;

                default:
                    $problems[] = "نوع فیلد «{$label}» پشتیبانی نمی‌شود.";
            }
        }

        // Required fields never sent at all.
        $extraKeys = array_map('strval', array_keys($extra));
        foreach ($schema as $field) {
            if (!empty($field['required']) && !in_array((string) $field['key'], $extraKeys, true)) {
                $problems[] = '«' . (string) $field['label'] . '» الزامی است.';
            }
        }

        if ($problems !== []) {
            throw new ApiException('INVALID_INPUT', 400, implode(' ', $problems));
        }
        return ['extra' => $clean, 'native' => $native];
    }

    /** Route a validated value to extra_fields or its native products column. */
    private static function store(array &$clean, array &$native, array $field, mixed $value): void
    {
        $nativeColumn = (string) ($field['native'] ?? '');
        if ($nativeColumn === 'sku') { // only native column used by v1 presets
            $native['sku'] = $value;
            return;
        }
        $clean[(string) $field['key']] = $value;
    }
}
