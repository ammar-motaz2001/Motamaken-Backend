<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<TeamMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class)->orderByDesc('is_lead')->orderBy('id');
    }

    /**
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

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
