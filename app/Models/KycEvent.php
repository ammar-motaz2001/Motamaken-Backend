<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $kyc_submission_id
 * @property int $user_id
 * @property string $status
 * @property string|null $note
 * @property int|null $actor_id
 * @property Carbon $created_at
 * @property-read User|null $actor
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent whereKycSubmissionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycEvent whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['kyc_submission_id', 'user_id', 'status', 'note', 'actor_id'])]
class KycEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * Who made the change: the user when submitting, an admin when reviewing.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
