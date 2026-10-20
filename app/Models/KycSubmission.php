<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return [
            'document_number' => 'encrypted',
            'date_of_birth' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
