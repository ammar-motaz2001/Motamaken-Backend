<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $support_ticket_id
 * @property int|null $user_id
 * @property bool $is_staff
 * @property string|null $body
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_mime
 * @property int|null $attachment_size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereAttachmentMime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereAttachmentName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereAttachmentPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereAttachmentSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereIsStaff($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereSupportTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupportMessage whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['support_ticket_id', 'user_id', 'is_staff', 'body', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size'])]
class SupportMessage extends Model
{
    /**
     * is_staff becomes a boolean: true for replies from the support team.
     */
    protected function casts(): array
    {
        return ['is_staff' => 'boolean'];
    }

    /**
     * The author: the ticket's user or a support agent.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
