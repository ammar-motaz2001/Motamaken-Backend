<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property string $topic
 * @property string $subject
 * @property string $message
 * @property string $status
 * @property string|null $admin_note
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $reply
 * @property Carbon|null $replied_at
 * @property Carbon|null $archived_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereAdminNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereArchivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereHandledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereHandledBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereRepliedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereReply($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereTopic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContactMessage whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'name', 'email', 'topic', 'subject', 'message', 'status', 'admin_note', 'handled_by', 'handled_at', 'reply', 'replied_at', 'archived_at'])]
class ContactMessage extends Model
{
    public const TOPICS = ['general', 'account', 'payments', 'disputes', 'technical', 'partnership', 'other'];

    public const NEW = 'new';

    public const HANDLED = 'handled';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'new'];

    /**
     * The handled, replied and archived times become dates.
     */
    protected function casts(): array
    {
        return ['handled_at' => 'datetime', 'replied_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    /**
     * The signed-in user who sent the message, null for guests.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
