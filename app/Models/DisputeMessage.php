<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dispute_id', 'user_id', 'kind', 'body', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size'])]
class DisputeMessage extends Model
{
    public const MESSAGE = 'message';

    /** Written by the system: "opened", "taken by an admin", "resolved". */
    public const SYSTEM = 'system';

    /** Written by an admin for other admins only. */
    public const ADMIN_NOTE = 'admin_note';

    /** The reason and evidence of an appeal, filed after the dispute was settled. */
    public const APPEAL = 'appeal';

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'message'];

    protected function casts(): array
    {
        return ['attachment_size' => 'integer'];
    }

    /**
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
