<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'user_id', 'role_title', 'share_bp', 'status', 'is_lead', 'invited_at', 'expires_at', 'responded_at'])]
class TeamMember extends Model
{
    /** Waiting for the person to answer. */
    public const INVITED = 'invited';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    /** Nobody answered before the invitation ran out. */
    public const EXPIRED = 'expired';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'invited', 'is_lead' => false];

    protected function casts(): array
    {
        return [
            'share_bp' => 'integer',
            'is_lead' => 'boolean',
            'invited_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the invitation can still be answered.
     */
    public function isAnswerable(): bool
    {
        return $this->status === self::INVITED && $this->expires_at?->isFuture();
    }
}
