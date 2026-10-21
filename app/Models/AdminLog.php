<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $admin_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<array-key, mixed>|null $changes
 * @property string|null $ip
 * @property Carbon $created_at
 * @property-read User|null $admin
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog whereChanges($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog whereIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog whereSubjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminLog whereSubjectType($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['admin_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip'])]
class AdminLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * The changes column holds the before/after values as an array.
     */
    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /**
     * The admin who performed the action.
     *
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
