<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $dispute_id
 * @property int|null $user_id
 * @property string $kind
 * @property string|null $body
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_mime
 * @property int|null $attachment_size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Dispute $dispute
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereAttachmentMime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereAttachmentName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereAttachmentPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereAttachmentSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereDisputeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeMessage whereUserId($value)
 *
 * @mixin \Eloquent
 */
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

    /**
     * The attachment size, in bytes, becomes an integer.
     */
    protected function casts(): array
    {
        return ['attachment_size' => 'integer'];
    }

    /**
     * The dispute the message belongs to.
     *
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * The author: a party or an admin, null for system notes.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
