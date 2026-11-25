<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money is integer minor units (kobo). Never use floats for storage or maths.
 */
final class Money
{
    public const KOBO_PER_NAIRA = 100;

    /** Parse user input such as "80,000" or "₦8,000.50" into kobo. */
    public static function parse(string|int|float|null $input): ?int
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (is_int($input)) {
            return $input * self::KOBO_PER_NAIRA;
        }

        if (is_float($input)) {
            return (int) round($input * self::KOBO_PER_NAIRA);
        }

        $cleaned = preg_replace('/[^0-9.]/', '', $input);
        if ($cleaned === null || $cleaned === '' || ! is_numeric($cleaned)) {
            return null;
        }

        return (int) round((float) $cleaned * self::KOBO_PER_NAIRA);
    }

    public static function nairaToKobo(int|float $naira): int
    {
        return (int) round($naira * self::KOBO_PER_NAIRA);
    }

    public static function toNaira(int $kobo): float
    {
        return $kobo / self::KOBO_PER_NAIRA;
    }

    public static function format(int $kobo, bool $withSymbol = true): string
    {
        $formatted = number_format($kobo / self::KOBO_PER_NAIRA, 0, '.', ',');
        $formatted = '₦'.$formatted;

        return $withSymbol ? $formatted : $formatted;
    }

    /** Integer percentage (0-100), rounded to the nearest whole number. */
    public static function percent(int $part, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(($part / $total) * 100));
    }

    /** Basis points of an amount, rounded half up. 350 bps = 3.5%. */
    public static function bps(int $amount, int $bps): int
    {
        if ($amount < 0 || $bps < 0) {
            throw new InvalidArgumentException('Money and basis points must be non-negative.');
        }

        return intdiv($amount * $bps + 5000, 10000);
    }
}
