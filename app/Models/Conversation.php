<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['pair_key', 'project_id', 'type', 'title', 'created_by', 'last_message_id', 'last_message_at'])]
class Conversation extends Model
{
    use HasFactory;

    public const DIRECT = 'direct';

    public const GROUP = 'group';

    /** @var array<string, mixed> */
    protected $attributes = ['type' => 'direct'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function isGroup(): bool
    {
        return $this->type === self::GROUP;
    }

    public static function pairKey(int $first, int $second): string
    {
        return min($first, $second).':'.max($first, $second);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user')->withPivot('last_read_message_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /**
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
