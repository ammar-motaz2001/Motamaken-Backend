<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reporter_id', 'target_type', 'target_id', 'reason', 'details', 'status', 'resolution_note', 'reviewed_at'])]
class Report extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    public const TARGETS = ['user', 'project', 'portfolio', 'review'];

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
}
