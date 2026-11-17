<?php

declare(strict_types=1);

namespace App\Helpers;

use InvalidArgumentException;

/**
 * Jalali (Solar Hijri) <-> Gregorian conversion, ported from the well-known
 * jalaali-js algorithm (Khayyam-based, accurate for years 1178..3177 Jalali).
 * Dates are STORED as Gregorian in the DB; the UI shows Jalali.
 * All conversions are pure functions so they can be unit tested.
 */
final class Jalali
{
    private const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181,
        1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

    /** Truncated division (matches the JS reference). */
    private static function div(int $a, int $b): int
    {
        return intdiv($a, $b);
    }

    /** Remainder with the sign of the dividend (matches the JS % operator). */
    private static function mod(int $a, int $b): int
    {
        return $a % $b;
    }

    /** @return array{leap: int, gy: int, march: int} */
    private static function jalCal(int $jy): array
    {
        $breaks = self::BREAKS;
        $bl = count($breaks);
        $gy = $jy + 621;
        $leapJ = -14;
        $jp = $breaks[0];
        $jump = 0;
        if ($jy < $jp || $jy >= $breaks[$bl - 1]) {
            throw new InvalidArgumentException("Invalid Jalaali year: $jy");
        }
        for ($i = 1; $i < $bl; $i++) {
            $jm = $breaks[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) {
                break;
            }
            $leapJ = $leapJ + self::div($jump, 33) * 8 + self::div(self::mod($jump, 33), 4);
            $jp = $jm;
        }
        $n = $jy - $jp;
        $leapJ = $leapJ + self::div($n, 33) * 8 + self::div(self::mod($n, 33) + 3, 4);
        if (self::mod($jump, 33) === 4 && $jump - $n === 4) {
            $leapJ += 1;
        }
        $leapG = self::div($gy, 4) - self::div((self::div($gy, 100) + 1) * 3, 4) - 150;
        $march = 20 + $leapJ - $leapG;
        if ($jump - $n < 6) {
            $n = $n - $jump + self::div($jump + 4, 33) * 33;
        }
        $leap = self::mod(self::mod($n + 1, 33) - 1, 4);
        if ($leap === -1) {
            $leap = 4;
        }
        return ['leap' => $leap, 'gy' => $gy, 'march' => $march];
    }

    /** Gregorian y/m/d -> Julian Day Number. */
    private static function g2d(int $gy, int $gm, int $gd): int
    {
        $d = self::div(($gy + self::div($gm - 8, 6) + 100100) * 1461, 4)
            + self::div(153 * self::mod($gm + 9, 12) + 2, 5)
            + $gd - 34840408;
        $d = $d - self::div(self::div($gy + 100100 + self::div($gm - 8, 6), 100) * 3, 4) + 752;
        return $d;
    }

    /** Julian Day Number -> Gregorian y/m/d. */
    private static function d2g(int $jd): array
    {
        $j = 4 * $jd + 139361631;
        $j = $j + self::div(self::div(4 * $jd + 183187720, 146097) * 3, 4) * 4 - 3908;
        $i = self::div(self::mod($j, 1461), 4) * 5 + 308;
        $gd = self::div(self::mod($i, 153), 5) + 1;
        $gm = self::mod(self::div($i, 153), 12) + 1;
        $gy = self::div($j, 1461) - 100100 + self::div(8 - $gm, 6);
        return [$gy, $gm, $gd];
    }

    /** Jalali y/m/d -> Julian Day Number. */
    private static function j2d(int $jy, int $jm, int $jd): int
    {
        $r = self::jalCal($jy);
        return self::g2d($r['gy'], 3, $r['march'])
            + ($jm - 1) * 31
            - self::div($jm, 7) * ($jm - 7)
            + $jd - 1;
    }

    /** Julian Day Number -> Jalali y/m/d. */
    private static function d2j(int $jd): array
    {
        $gy = self::d2g($jd)[0];
        $jy = $gy - 621;
        $r = self::jalCal($jy);
        $jdn1f = self::g2d($gy, 3, $r['march']);
        $k = $jd - $jdn1f;
        if ($k >= 0) {
            if ($k <= 185) {
                return [$jy, 1 + self::div($k, 31), self::mod($k, 31) + 1];
            }
            $k -= 186;
        } else {
            $jy -= 1;
            $k += 179;
            if ($r['leap'] === 1) {
                $k += 1;
            }
        }
        return [$jy, 7 + self::div($k, 30), self::mod($k, 30) + 1];
    }

    /** @return array{0: int, 1: int, 2: int} [jy, jm, jd] */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        return self::d2j(self::g2d($gy, $gm, $gd));
    }

    /** @return array{0: int, 1: int, 2: int} [gy, gm, gd] */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        return self::d2g(self::j2d($jy, $jm, $jd));
    }

    public static function isLeapJalali(int $jy): bool
    {
        return self::jalCal($jy)['leap'] === 0;
    }

    /** Days in a Jalali month (1-6 -> 31, 7-11 -> 30, 12 -> 29/30). */
    public static function daysInJalaliMonth(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }
        return self::isLeapJalali($jy) ? 30 : 29;
    }

    /** Validate a Jalali y/m/d triple against the real calendar. */
    public static function isValidJalali(int $jy, int $jm, int $jd): bool
    {
        if ($jy < 1 || $jm < 1 || $jm > 12 || $jd < 1) {
            return false;
        }
        return $jd <= self::daysInJalaliMonth($jy, $jm);
    }

    /**
     * "1403/05/12" (accepts '-' too, Persian digits, optional zero padding)
     * -> "2024-08-02" Gregorian SQL date. Null when invalid.
     */
    public static function jalaliToSqlDate(string $input): ?string
    {
        $s = Text::normalizeDigits(trim($input));
        if (!preg_match('/^(\d{3,4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $s, $m)) {
            return null;
        }
        $jy = (int) $m[1];
        $jm = (int) $m[2];
        $jd = (int) $m[3];
        if (!self::isValidJalali($jy, $jm, $jd)) {
            return null;
        }
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /** "2024-08-02" Gregorian -> "1403/05/12" Jalali display string. Null when invalid. */
    public static function sqlDateToJalali(string $sqlDate): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $sqlDate, $m)) {
            return null;
        }
        [$jy, $jm, $jd] = self::toJalali((int) $m[1], (int) $m[2], (int) $m[3]);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}
