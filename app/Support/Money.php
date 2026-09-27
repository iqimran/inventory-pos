<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for money, on numeric strings with 2 decimal places (bcmath).
 * Never use float for monetary values.
 */
final class Money
{
    public const SCALE = 2;

    public static function of(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        $value = is_int($value) ? (string) $value : trim($value);

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Invalid money amount [{$value}].");
        }

        return self::round($value);
    }

    public static function add(string ...$values): string
    {
        return array_reduce($values, fn (string $carry, string $value) => bcadd($carry, $value, self::SCALE), '0.00');
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    /**
     * Multiply and round half-up to 2 decimals.
     */
    public static function mul(string $a, string|int $b): string
    {
        return self::round(bcmul($a, (string) $b, self::SCALE + 4));
    }

    /**
     * $amount × $numerator ÷ $denominator, rounded half-up (used for proportional shares).
     */
    public static function proportion(string $amount, string|int $numerator, string|int $denominator): string
    {
        if (bccomp((string) $denominator, '0', self::SCALE) === 0) {
            return '0.00';
        }

        return self::round(bcdiv(bcmul($amount, (string) $numerator, 8), (string) $denominator, 8));
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::cmp($value, '0') > 0;
    }

    public static function isNegative(string $value): bool
    {
        return self::cmp($value, '0') < 0;
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? $a : $b;
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? $a : $b;
    }

    public static function negate(string $value): string
    {
        return bcmul($value, '-1', self::SCALE);
    }

    /**
     * Round half away from zero to 2 decimals.
     */
    public static function round(string $value): string
    {
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $offset, self::SCALE + 4), '0', self::SCALE);
    }
}
