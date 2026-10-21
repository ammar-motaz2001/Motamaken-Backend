<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $reporter_id
 * @property string $target_type
 * @property int $target_id
 * @property string $reason
 * @property string|null $details
 * @property string $status
 * @property string|null $resolution_note
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $source
 * @property string $priority
 * @property string|null $action_taken
 * @property int|null $handled_by
 * @property-read User|null $handler
 * @property-read User|null $reporter
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereActionTaken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereDetails($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereHandledBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereReporterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereResolutionNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereReviewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereTargetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereTargetType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Report whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['reporter_id', 'target_type', 'target_id', 'reason', 'details', 'status', 'resolution_note', 'reviewed_at', 'source', 'priority', 'action_taken', 'handled_by'])]
class Report extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    public const TARGETS = ['user', 'project', 'portfolio', 'review', 'message', 'proposal', 'team'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    /** What a moderator can do from the report itself. */
    public const ACTIONS = ['none', 'warned', 'hidden', 'removed', 'suspended', 'closed'];

    public const OPEN = 'open';

    public const ACTIONED = 'actioned';

    public const DISMISSED = 'dismissed';

    public const STATUSES = [self::OPEN, self::ACTIONED, self::DISMISSED];

    public const REASONS = ['spam', 'fake', 'inappropriate', 'copyright', 'scam', 'other'];

    /**
     * reviewed_at becomes a date.
     */
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /**
     * The user who filed the report.
     *
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * The moderator who decided it.
     *
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
