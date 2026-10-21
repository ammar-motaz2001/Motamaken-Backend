<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request by one side of a project to change the accepted offer (price and/or delivery time) or to cancel the
 * agreement; the other side accepts or declines it. See App\Services\AgreementService.
 *
 * @property int $id
 * @property int $project_id
 * @property int $proposal_id
 * @property int $requested_by
 * @property string $type
 * @property numeric|null $amount
 * @property int|null $delivery_days
 * @property string $reason
 * @property string $status
 * @property int|null $responded_by
 * @property string|null $response_note
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Proposal $proposal
 * @property-read User $requester
 * @property-read User|null $responder
 */
#[Fillable(['project_id', 'proposal_id', 'requested_by', 'type', 'amount', 'delivery_days', 'reason', 'status', 'responded_by', 'response_note', 'responded_at'])]
class AgreementChange extends Model
{
    public const CHANGE = 'change';

    public const CANCEL = 'cancel';

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const WITHDRAWN = 'withdrawn';

    protected $attributes = ['status' => self::PENDING];

    /**
     * amount stays a decimal string, delivery_days an integer, responded_at a date.
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'delivery_days' => 'integer', 'responded_at' => 'datetime'];
    }

    /**
     * The project whose agreement this is about.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The accepted offer it would change or cancel.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * Who asked for it.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Who accepted or declined it, null while pending or withdrawn.
     *
     * @return BelongsTo<User, $this>
     */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
