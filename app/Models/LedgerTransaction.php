<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string|null $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property array<array-key, mixed>|null $meta
 * @property string|null $idempotency_key
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read User|null $creator
 * @property-read Collection<int, LedgerEntry> $entries
 * @property-read int|null $entries_count
 * @property-read Model|null $reference
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereIdempotencyKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereMeta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereReferenceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereReferenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereType($value)
 *
 * @mixin \Eloquent
 */
class LedgerTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * The meta column holds an array of extra details.
     */
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /**
     * The debit and credit entries, which always sum to zero.
     *
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }

    /**
     * The record that caused the money movement, e.g. a milestone, deposit or withdrawal.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The admin who made it by hand (adjustments, settlements), if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
