<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectRequest;
use App\Http\Resources\KycResource;
use App\Http\Resources\UserBriefResource;
use App\Models\KycEvent;
use App\Models\KycSubmission;
use App\Services\KycService;
use App\Support\SignedUrl;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminKycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    /**
     * List identity checks.
     *
     * Oldest pending first by default.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([KycSubmission::PENDING, KycSubmission::APPROVED, KycSubmission::REJECTED, KycSubmission::NEEDS_UPDATE])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $request->input('status', KycSubmission::PENDING);

        return KycResource::collection(
            KycSubmission::with('user')->where('status', $status)->orderBy('id', $status === KycSubmission::PENDING ? 'asc' : 'desc')
                ->paginate($request->integer('perPage', 20)),
        );
    }

    /**
     * Review documents.
     *
     * The full details with short-lived signed links to the document photos and the selfie (10 minutes).
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(int $submission): array
    {
        $submission = KycSubmission::with('user')->findOrFail($submission);
        $link = fn (string $kind) => SignedUrl::temporary('kyc.file', now()->addMinutes(10), [$submission->id, $kind]);

        return ['data' => [
            ...(new KycResource($submission))->resolve(),
            'user' => (new UserBriefResource($submission->user))->resolve(),
            /** The full number, which users only see masked. */
            'documentNumberFull' => $submission->document_number,
            /** Every step of this user's identity checks, oldest first. */
            'history' => KycEvent::with('actor')->where('user_id', $submission->user_id)->orderBy('id')->get()->map(fn (KycEvent $e) => [
                'id' => $e->id,
                'submissionId' => $e->kyc_submission_id,
                /** `submitted`, `approved`, `rejected` or `needs_update`. */
                'status' => $e->status,
                'note' => $e->note,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'fullName' => $e->actor->full_name] : null,
                'createdAt' => $e->created_at?->toIso8601String(),
            ])->all(),
            'files' => [
                'front' => $link('front'),
                'back' => $submission->back_path ? $link('back') : null,
                'selfie' => $link('selfie'),
            ],
        ]];
    }

    /**
     * Approve.
     *
     * The user can withdraw money and is told.
     */
    public function approve(Request $request, int $submission): KycResource
    {
        return new KycResource($this->kyc->approve(KycSubmission::findOrFail($submission), $request->user()));
    }

    /**
     * Reject.
     *
     * The user sees the reason and may send new documents.
     */
    public function reject(RejectRequest $request, int $submission): KycResource
    {
        return new KycResource($this->kyc->reject(KycSubmission::findOrFail($submission), $request->user(), $request->input('reason')));
    }

    /**
     * Ask for an update.
     *
     * The documents are unclear or incomplete: the submission closes as `needs_update`, the user is told what to fix and can
     * send a new one.
     */
    public function requestUpdate(RejectRequest $request, int $submission): KycResource
    {
        return new KycResource($this->kyc->requestUpdate(KycSubmission::findOrFail($submission), $request->user(), $request->input('reason')));
    }

    /**
     * View a document.
     *
     * Reached through the signed links of the review page. Never cached.
     *
     * @unauthenticated
     */
    public function file(int $submission, string $kind): StreamedResponse
    {
        $submission = KycSubmission::findOrFail($submission);
        $path = match ($kind) {
            'front' => $submission->front_path,
            'back' => $submission->back_path,
            'selfie' => $submission->selfie_path,
            default => null,
        };

        abort_unless($path, 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }
}
