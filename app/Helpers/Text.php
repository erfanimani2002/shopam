<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Input normalization helpers. Persian/Arabic digits are normalized before
 * any lookup or storage (phone numbers especially).
 */
final class Text
{
    private const DIGIT_MAP = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        // Persian/Arabic thousands separators and decimal marks
        '٬' => '', '،' => '', '٫' => '.',
    ];

    /** Persian/Arabic digits (and separators) -> ASCII. */
    public static function normalizeDigits(string $s): string
    {
        return strtr($s, self::DIGIT_MAP);
    }

    /** Digits + strip spaces/dashes/parentheses; keeps a leading + and leading 0. */
    public static function normalizePhone(string $s): string
    {
        $s = self::normalizeDigits($s);
        $s = str_replace([' ', '-', '(', ')', "\t", "\u{200c}"], '', $s);
        return trim($s);
    }

    /** Slug shape for shop identifiers: lowercase [a-z0-9-], no doubles/edges. */
    public static function normalizeSlug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9-]+/', '-', $s) ?? '';
        $s = preg_replace('/-+/', '-', $s) ?? '';
        return trim($s, '-');
    }
}
