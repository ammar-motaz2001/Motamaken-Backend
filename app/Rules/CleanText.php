<?php

namespace App\Rules;

use App\Models\BlacklistEntry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses text containing a word the admins put on the blacklist.
 */
class CleanText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $word = BlacklistEntry::blockedWord($value)) {
            $fail(__('admin.blocked_word', ['word' => $word]));
        }
    }
}
