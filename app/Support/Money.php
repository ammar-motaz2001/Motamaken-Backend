<?php

namespace App\Support;

/**
 * Money is kept in whole cents so nothing is ever rounded away. The API speaks in decimal amounts (12.50).
 */
final class Money
{
    public static function toCents(int|float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromCents(int $cents): float
    {
        return $cents / 100;
    }
}
