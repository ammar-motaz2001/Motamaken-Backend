<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $sender_id
 * @property string $type
 * @property string|null $body
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_mime
 * @property int|null $attachment_size
 * @property int|null $duration
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $removed_at
 * @property-read Conversation $conversation
 * @property-read User $sender
 *
 * @method static Builder<static>|Message newModelQuery()
 * @method static Builder<static>|Message newQuery()
 * @method static Builder<static>|Message query()
 * @method static Builder<static>|Message unreadFor(\App\Models\User $user)
 * @method static Builder<static>|Message whereAttachmentMime($value)
 * @method static Builder<static>|Message whereAttachmentName($value)
 * @method static Builder<static>|Message whereAttachmentPath($value)
 * @method static Builder<static>|Message whereAttachmentSize($value)
 * @method static Builder<static>|Message whereBody($value)
 * @method static Builder<static>|Message whereConversationId($value)
 * @method static Builder<static>|Message whereCreatedAt($value)
 * @method static Builder<static>|Message whereDuration($value)
 * @method static Builder<static>|Message whereId($value)
 * @method static Builder<static>|Message whereReadAt($value)
 * @method static Builder<static>|Message whereRemovedAt($value)
 * @method static Builder<static>|Message whereSenderId($value)
 * @method static Builder<static>|Message whereType($value)
 * @method static Builder<static>|Message whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['conversation_id', 'sender_id', 'type', 'body', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'duration'])]
class Message extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_VOICE = 'voice';

    public const TYPE_ATTACHMENT = 'attachment';

    /**
     * The removal and read times become dates; attachment size (bytes) and voice note duration (seconds) integers.
     */
    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
            'attachment_size' => 'integer',
            'duration' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Whether the other side has read it: the read time in a direct chat, every other member in a group
     * (the controller fills `seen_by` and `others_count` for groups).
     */
    public function isRead(): bool
    {
        return isset($this->others_count) ? $this->seen_by >= $this->others_count : $this->read_at !== null;
    }

    /**
     * The conversation the message was posted in.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The user who sent the message.
     *
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * The SQL for "this message is unread for the user", to use in a query that has `messages` and `conversations`.
     * Direct chats use the message's own read time; groups use what the user has read up to. Needs the user id twice.
     */
    public const UNREAD_SQL = "messages.sender_id != ? and (
        (conversations.type = 'direct' and messages.read_at is null)
        or (conversations.type = 'group' and messages.id > coalesce(
            (select cu.last_read_message_id from conversation_user cu where cu.conversation_id = conversations.id and cu.user_id = ?), 0))
    )";

    /**
     * Messages the user received and has not opened yet, across all their conversations.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        return $query
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereRaw(self::UNREAD_SQL, [$user->id, $user->id])
            ->whereIn('messages.conversation_id', DB::table('conversation_user')->select('conversation_id')->where('user_id', $user->id));
    }
}
