<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TeamMember> $members
 * @property-read int|null $members_count
 * @property-read User $owner
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Proposal> $proposals
 * @property-read int|null $proposals_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereOwnerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['owner_id', 'name', 'description', 'status', 'published_at'])]
class Team extends Model
{
    use HasFactory;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const DISBANDED = 'disbanded';

    /** The team is complete only when members' shares add up to this (100%). */
    public const FULL_SHARE_BP = 10000;

    public const MIN_MEMBERS = 2;

    /** Nobody, the lead included, can have less than 1%. */
    public const MIN_SHARE_BP = 100;

    public const MAX_MEMBERS = 10;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    /**
     * published_at becomes a date.
     */
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /**
     * The user who created the team and manages it.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The members and invitations, the lead first.
     *
     * @return HasMany<TeamMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class)->orderByDesc('is_lead')->orderBy('id');
    }

    /**
     * The proposals sent in the team's name.
     *
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * Whether the team is published and can send proposals.
     */
    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * What stops the team from being published, as codes the frontend can turn into a checklist.
     *
     * @return list<string>
     */
    public function blockers(?Collection $members = null): array
    {
        $members ??= $this->members()->get();
        $blockers = [];

        if ($members->where('status', TeamMember::ACCEPTED)->count() < self::MIN_MEMBERS) {
            $blockers[] = 'too_few_members';
        }

        if ($members->where('status', TeamMember::INVITED)->isNotEmpty()) {
            $blockers[] = 'awaiting_acceptances';
        }

        if ($members->whereIn('status', [TeamMember::DECLINED, TeamMember::EXPIRED])->isNotEmpty()) {
            $blockers[] = 'invitations_not_accepted';
        }

        if ($members->firstWhere('is_lead', true)?->share_bp < self::MIN_SHARE_BP) {
            $blockers[] = 'lead_share_too_low';
        }

        return $blockers;
    }
}
