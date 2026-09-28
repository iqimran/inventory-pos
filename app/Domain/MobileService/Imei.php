<?php

namespace App\Domain\MobileService;

/**
 * IMEI helpers: an IMEI is 15 digits whose last digit is a Luhn check digit.
 */
final class Imei
{
    /**
     * Strip spaces, dashes and other separators people type or scanners add.
     */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    public static function isValid(string $imei): bool
    {
        if (preg_match('/^\d{15}$/', $imei) !== 1) {
            return false;
        }

        $sum = 0;

        foreach (str_split(strrev($imei)) as $position => $digit) {
            $value = (int) $digit;

            if ($position % 2 === 1) {
                $value *= 2;
                $value = $value > 9 ? $value - 9 : $value;
            }

            $sum += $value;
        }

        return $sum % 10 === 0;
    }
}
