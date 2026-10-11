<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'kind', 'package', 'project_id', 'days', 'price_cents', 'currency', 'points_awarded', 'starts_at', 'ends_at'])]
class Promotion extends Model
{
    public const PROFILE = 'profile';

    public const PROJECT = 'project';

    protected function casts(): array
    {
        return ['days' => 'integer', 'price_cents' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
