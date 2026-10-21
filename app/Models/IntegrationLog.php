<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @property int $id
 * @property string $service
 * @property string $level
 * @property string $message
 * @property array<array-key, mixed>|null $context
 * @property Carbon $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationLog whereService($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['service', 'level', 'message', 'context'])]
class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * The context column holds an array of details about the failure.
     */
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
