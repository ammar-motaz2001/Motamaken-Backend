<?php

namespace App\Support;

/**
 * Money is kept in whole cents so nothing is ever rounded away. The API speaks in decimal amounts (12.50).
 */
final class Money
{
    /**
     * Convert an amount in currency units (as typed by users) to whole cents, rounded.
     */
    public static function toCents(int|float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Convert cents back to currency units for API responses.
     */
    public static function fromCents(int $cents): float
    {
        return $cents / 100;
    }
}
