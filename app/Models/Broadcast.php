<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title_en', 'title_ar', 'body_en', 'body_ar', 'audience', 'target_user_id', 'channels', 'recipients', 'created_by'])]
class Broadcast extends Model
{
    public const AUDIENCES = ['all', 'clients', 'freelancers', 'influencers', 'user'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'recipients' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
