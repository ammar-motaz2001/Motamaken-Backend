<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\KycEvent;
use App\Models\KycSubmission;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class KycService
{
    /**
     * Send identity documents for review. Refused while one is pending or after approval; the files are stored privately,
     * the user's status becomes pending and the admins are told.
     *
     * @param  array{documentType: string, documentNumber: string, fullName: string, dateOfBirth: string}  $data
     */
    public function submit(User $user, array $data, UploadedFile $front, ?UploadedFile $back, UploadedFile $selfie): KycSubmission
    {
        if ($user->kyc_status === 'approved') {
            throw new ApiException(__('kyc.already_approved'), 'kyc_approved');
        }

        if ($user->kyc_status === 'pending') {
            throw new ApiException(__('kyc.already_pending'), 'kyc_pending');
        }

        $submission = DB::transaction(function () use ($user, $data, $front, $back, $selfie) {
            $submission = KycSubmission::create([
                'user_id' => $user->id,
                'document_type' => $data['documentType'],
                'document_number' => $data['documentNumber'],
                'full_name' => $data['fullName'],
                'date_of_birth' => $data['dateOfBirth'],
                'front_path' => $front->store("kyc/{$user->id}", 'local'),
                'back_path' => $back?->store("kyc/{$user->id}", 'local'),
                'selfie_path' => $selfie->store("kyc/{$user->id}", 'local'),
            ]);

            $user->forceFill(['kyc_status' => 'pending'])->save();
            KycEvent::create(['kyc_submission_id' => $submission->id, 'user_id' => $user->id, 'status' => 'submitted', 'actor_id' => $user->id]);

            return $submission;
        });

        foreach (User::where('is_admin', true)->get() as $admin) {
            $admin->notify(new ActivityNotification('admin.kyc_submitted', $user, ['kycId' => $submission->id]));
        }

        return $submission;
    }

    /**
     * Accept a pending submission: the user becomes verified.
     */
    public function approve(KycSubmission $submission, User $admin): KycSubmission
    {
        return $this->review($submission, $admin, KycSubmission::APPROVED, null);
    }

    /**
     * Turn down a pending submission with a reason the user is shown.
     */
    public function reject(KycSubmission $submission, User $admin, string $reason): KycSubmission
    {
        return $this->review($submission, $admin, KycSubmission::REJECTED, $reason);
    }

    /**
     * Ask for a clearer or missing document: like a rejection, but the user is invited to send it again.
     */
    public function requestUpdate(KycSubmission $submission, User $admin, string $reason): KycSubmission
    {
        return $this->review($submission, $admin, KycSubmission::NEEDS_UPDATE, $reason);
    }

    /**
     * Record an admin's decision on a pending submission, copy the status to the user, log the event and tell the user.
     * The row is locked so two admins cannot decide the same submission.
     */
    private function review(KycSubmission $submission, User $admin, string $status, ?string $reason): KycSubmission
    {
        DB::transaction(function () use ($submission, $admin, $status, $reason) {
            $locked = KycSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== KycSubmission::PENDING) {
                throw new ApiException(__('kyc.not_pending'), 'kyc_not_pending');
            }

            $locked->update(['status' => $status, 'rejection_reason' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $locked->user->forceFill(['kyc_status' => $status])->save();
            KycEvent::create(['kyc_submission_id' => $locked->id, 'user_id' => $locked->user_id, 'status' => $status, 'note' => $reason, 'actor_id' => $admin->id]);

            $locked->user->notify(new ActivityNotification("kyc.{$status}", null, ['kycId' => $locked->id, 'reason' => $reason]));
        });

        return $submission->refresh();
    }
}
