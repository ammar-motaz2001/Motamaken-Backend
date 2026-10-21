<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string|null $role_title
 * @property int $share_bp
 * @property string $status
 * @property bool $is_lead
 * @property Carbon|null $invited_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereInvitedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereIsLead($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereRespondedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereRoleTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereShareBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereUserId($value)
 *
 * @mixin \Eloquent
 */
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

    /**
     * The share is in basis points, is_lead a boolean, the invitation and answer times dates.
     */
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
     * The team the membership is in.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The invited or joined user.
     *
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
