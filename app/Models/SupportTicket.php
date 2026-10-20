<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'subject', 'category', 'status', 'assigned_to', 'last_message_at'])]
class SupportTicket extends Model
{
    public const CATEGORIES = ['account', 'payments', 'projects', 'technical', 'other'];

    public const OPEN = 'open';

    public const ANSWERED = 'answered';

    public const CLOSED = 'closed';

    public const STATUSES = [self::OPEN, self::ANSWERED, self::CLOSED];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::OPEN];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }
}
