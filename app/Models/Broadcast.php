<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title_en
 * @property string $title_ar
 * @property string $body_en
 * @property string $body_ar
 * @property string $audience
 * @property int|null $target_user_id
 * @property array<array-key, mixed> $channels
 * @property int $recipients
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read User|null $targetUser
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereAudience($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereBodyAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereBodyEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereChannels($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereRecipients($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereTargetUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereTitleAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereTitleEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Broadcast whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['title_en', 'title_ar', 'body_en', 'body_ar', 'audience', 'target_user_id', 'channels', 'recipients', 'created_by'])]
class Broadcast extends Model
{
    public const AUDIENCES = ['all', 'clients', 'freelancers', 'influencers', 'user'];

    /**
     * Channels are a list (e.g. database, email); recipients the number reached once sent.
     */
    protected function casts(): array
    {
        return ['channels' => 'array', 'recipients' => 'integer'];
    }

    /**
     * The admin who sent the announcement.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The single recipient when the audience is one user, otherwise null.
     *
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
