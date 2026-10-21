<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $document_type
 * @property string $document_number
 * @property string $full_name
 * @property Carbon $date_of_birth
 * @property string $front_path
 * @property string|null $back_path
 * @property string $selfie_path
 * @property string $status
 * @property string|null $rejection_reason
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereBackPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereDateOfBirth($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereDocumentNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereDocumentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereFrontPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereFullName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereReviewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereReviewedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereSelfiePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KycSubmission whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'document_type', 'document_number', 'full_name', 'date_of_birth', 'front_path', 'back_path', 'selfie_path', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at'])]
class KycSubmission extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Rejected with a request for a clearer or missing document; the user sends a new submission. */
    public const NEEDS_UPDATE = 'needs_update';

    public const DOCUMENT_TYPES = ['national_id', 'passport', 'driver_license'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /**
     * The document number is stored encrypted; birth and review dates become dates.
     */
    protected function casts(): array
    {
        return [
            'document_number' => 'encrypted',
            'date_of_birth' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * The user whose identity is being verified.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
