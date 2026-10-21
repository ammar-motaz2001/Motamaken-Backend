<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string|null $pair_key
 * @property int|null $project_id
 * @property int|null $last_message_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $type
 * @property string|null $title
 * @property int|null $created_by
 * @property-read Message|null $lastMessage
 * @property-read Collection<int, Message> $messages
 * @property-read int|null $messages_count
 * @property-read Collection<int, User> $participants
 * @property-read int|null $participants_count
 * @property-read Project|null $project
 *
 * @method static Builder<static>|Conversation forUser(\App\Models\User $user)
 * @method static Builder<static>|Conversation newModelQuery()
 * @method static Builder<static>|Conversation newQuery()
 * @method static Builder<static>|Conversation query()
 * @method static Builder<static>|Conversation whereCreatedAt($value)
 * @method static Builder<static>|Conversation whereCreatedBy($value)
 * @method static Builder<static>|Conversation whereId($value)
 * @method static Builder<static>|Conversation whereLastMessageAt($value)
 * @method static Builder<static>|Conversation whereLastMessageId($value)
 * @method static Builder<static>|Conversation wherePairKey($value)
 * @method static Builder<static>|Conversation whereProjectId($value)
 * @method static Builder<static>|Conversation whereTitle($value)
 * @method static Builder<static>|Conversation whereType($value)
 * @method static Builder<static>|Conversation whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['pair_key', 'project_id', 'type', 'title', 'created_by', 'last_message_id', 'last_message_at'])]
class Conversation extends Model
{
    use HasFactory;

    public const DIRECT = 'direct';

    public const GROUP = 'group';

    /** @var array<string, mixed> */
    protected $attributes = ['type' => 'direct'];

    /**
     * last_message_at becomes a date, used to sort the inbox.
     */
    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /**
     * Whether it is a group conversation rather than a one-to-one chat.
     */
    public function isGroup(): bool
    {
        return $this->type === self::GROUP;
    }

    /**
     * The key that identifies a direct conversation between two users, the same whichever of them starts it.
     */
    public static function pairKey(int $first, int $second): string
    {
        return min($first, $second).':'.max($first, $second);
    }

    /**
     * The users in the conversation, with the last message each has read.
     *
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user')->withPivot('last_read_message_id');
    }

    /**
     * Every message in the conversation.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The newest message, for the inbox preview.
     *
     * @return BelongsTo<Message, $this>
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /**
     * The project the conversation was started about, if any.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The other person in a direct conversation.
     */
    public function counterpart(User $me): ?User
    {
        return $this->participants->firstWhere('id', '!=', $me->id);
    }

    /**
     * The user's conversations, each with `unread_count` (messages from the other side not read yet).
     * Conversations of other users are simply not found, so lookups through this scope double as authorization.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query
            ->select('conversations.*')
            ->whereIn('conversations.id', DB::table('conversation_user')->select('conversation_id')->where('user_id', $user->id))
            ->selectSub(
                Message::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->whereRaw(Message::UNREAD_SQL, [$user->id, $user->id]),
                'unread_count',
            );
    }
}
