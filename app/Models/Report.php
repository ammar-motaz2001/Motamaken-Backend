<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /**
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
