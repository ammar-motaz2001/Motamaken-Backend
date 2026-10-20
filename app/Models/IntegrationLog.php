<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

#[Fillable(['service', 'level', 'message', 'context'])]
class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    /**
     * Record a problem with an outside service. Never throws: logging must not break the request that failed.
     *
     * @param  array<string, mixed>  $context  No secrets, card data or message bodies.
     */
    public static function record(string $service, string $message, array $context = [], string $level = 'error'): void
    {
        try {
            static::create(['service' => $service, 'level' => $level, 'message' => mb_substr($message, 0, 500), 'context' => $context ?: null]);
        } catch (Throwable) {
            // The database itself may be the problem.
        }
    }
}
