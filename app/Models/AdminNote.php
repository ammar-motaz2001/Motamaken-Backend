<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $author_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminNote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminNote whereAuthorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminNote whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminNote whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminNote whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'author_id', 'body'])]
class AdminNote extends Model
{
    /**
     * The admin who wrote the note, null if their account was deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
