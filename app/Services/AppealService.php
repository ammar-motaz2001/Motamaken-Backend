<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Dispute;
use App\Models\DisputeAppeal;
use App\Models\DisputeMessage;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Payments\LedgerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Appeals ("استئناف"): after a dispute is settled, either side may ask once for a second look. The staff either uphold the
 * first decision or overturn it with a money correction between the two wallets, written to the ledger.
 */
class AppealService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Whether this user may appeal this dispute now.
     */
    public function canAppeal(Dispute $dispute, ?User $user): bool
    {
        return $user !== null
            && $dispute->status === Dispute::RESOLVED
            && $dispute->resolved_at?->gt(now()->subDays((int) config('admin.appeal_days')))
            && $this->isParty($dispute, $user)
            && ! DisputeAppeal::where('dispute_id', $dispute->id)->where('user_id', $user->id)->exists();
    }

    public function file(Dispute $dispute, User $user, string $reason, ?UploadedFile $file): DisputeAppeal
    {
        if (! $this->canAppeal($dispute, $user)) {
            throw new ApiException(__('disputes.cannot_appeal', ['days' => config('admin.appeal_days')]), 'cannot_appeal');
        }

        $appeal = DB::transaction(function () use ($dispute, $user, $reason, $file) {
            $appeal = DisputeAppeal::create(['dispute_id' => $dispute->id, 'user_id' => $user->id, 'reason' => $reason]);

            $dispute->messages()->create([
                'user_id' => $user->id,
                'kind' => DisputeMessage::APPEAL,
                'body' => $reason,
                'attachment_path' => $file?->store("disputes/{$dispute->id}", 'local'),
                'attachment_name' => $file?->getClientOriginalName(),
                'attachment_mime' => $file?->getMimeType(),
                'attachment_size' => $file?->getSize(),
            ]);

            return $appeal;
        });

        foreach (User::where('is_admin', true)->get() as $admin) {
            $admin->notify(new ActivityNotification('admin.appeal_filed', $user, ['disputeId' => $dispute->id, 'case' => $dispute->caseNumber()]));
        }

        return $appeal;
    }

    /**
     * Decide an appeal. `overturned` moves `$cents` to the side named in `$to` (`client` or `freelancer`) from the other
     * side's wallet; it cannot take a wallet below zero.
     */
    public function decide(DisputeAppeal $appeal, User $admin, string $decision, string $note, int $cents = 0, ?string $to = null): DisputeAppeal
    {
        DB::transaction(function () use ($appeal, $admin, $decision, $note, $cents, $to) {
            $locked = DisputeAppeal::whereKey($appeal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DisputeAppeal::PENDING) {
                throw new ApiException(__('disputes.appeal_decided'), 'appeal_decided');
            }

            $dispute = $locked->dispute()->with(['escrow.client', 'escrow.payees.user', 'milestone'])->first();

            if ($decision === DisputeAppeal::OVERTURNED && $cents > 0) {
                if ($cents > $dispute->milestone->amount_cents) {
                    throw new ApiException(__('disputes.appeal_too_much'), 'appeal_too_much');
                }

                $client = $dispute->escrow->client;
                $freelancer = $dispute->escrow->payees->firstWhere('is_lead', true)?->user;
                [$from, $receiver] = $to === 'client' ? [$freelancer, $client] : [$client, $freelancer];

                $this->ledger->post('appeal_adjustment', [
                    [$this->ledger->wallet($from, $dispute->escrow->currency), -$cents],
                    [$this->ledger->wallet($receiver, $dispute->escrow->currency), $cents],
                ], ['reference' => $locked, 'key' => "appeal:{$locked->id}", 'by' => $admin, 'description' => $note,
                    'meta' => ['disputeId' => $dispute->id, 'case' => $dispute->caseNumber()]]);
            }

            $locked->update([
                'status' => $decision, 'decision_note' => $note, 'decided_by' => $admin->id, 'decided_at' => now(),
                'adjustment_cents' => $decision === DisputeAppeal::OVERTURNED ? $cents : null,
                'adjustment_to' => $decision === DisputeAppeal::OVERTURNED && $cents > 0 ? $to : null,
            ]);
            $dispute->messages()->create(['user_id' => $admin->id, 'kind' => DisputeMessage::SYSTEM, 'body' => "appeal.{$decision}"]);
        });

        $dispute = $appeal->dispute()->with(['escrow.client', 'escrow.payees.user', 'escrow.project'])->first();
        foreach ([$dispute->escrow->client, ...$dispute->escrow->payees->pluck('user')] as $party) {
            $party?->notify(new ActivityNotification("appeal.{$decision}", null, [
                'disputeId' => $dispute->id, 'case' => $dispute->caseNumber(), 'projectId' => $dispute->escrow->project_id, 'project' => $dispute->escrow->project?->title,
            ]));
        }

        return $appeal->refresh();
    }

    private function isParty(Dispute $dispute, User $user): bool
    {
        $dispute->loadMissing('escrow.payees');

        return $user->id === $dispute->escrow->client_id || $dispute->escrow->payees->contains('user_id', $user->id);
    }
}
