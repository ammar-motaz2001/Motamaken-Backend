<?php

namespace App\Support;

use App\Enums\AuthMethod;

/**
 * Hides most of an email or phone number for showing back, e.g. `am•••@gmail.com` or `•••••••255`.
 */
class Mask
{
    /**
     * Hide most of an email or phone number for display, e.g. ab•••@mail.com or •••••567.
     */
    public static function identifier(AuthMethod $method, ?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($method === AuthMethod::Email) {
            $at = (int) strpos($value, '@');

            return substr($value, 0, min(2, $at)).'•••'.substr($value, $at);
        }

        return str_repeat('•', max(0, strlen($value) - 3)).substr($value, -3);
    }
}
