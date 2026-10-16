<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'name', 'email', 'topic', 'subject', 'message', 'status', 'admin_note', 'handled_by', 'handled_at'])]
class ContactMessage extends Model
{
    public const TOPICS = ['general', 'account', 'payments', 'disputes', 'technical', 'partnership', 'other'];

    public const NEW = 'new';

    public const HANDLED = 'handled';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
