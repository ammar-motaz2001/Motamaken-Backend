<?php

namespace App\Http\Controllers;

use App\Http\Requests\KycRequest;
use App\Http\Resources\KycResource;
use App\Models\KycSubmission;
use App\Services\KycService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Identity verification', 'Prove who you are with an ID document and a selfie. Needed to withdraw money.', weight: 8)]
class KycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    /**
     * My verification status.
     *
     * `status` is `none` (nothing sent), `pending`, `approved` or `rejected`. `submission` holds the latest attempt,
     * including why it was rejected.
     *
     * @return array{status: string, submission: ?KycResource}
     */
    public function show(Request $request): array
    {
        $latest = KycSubmission::where('user_id', $request->user()->id)->latest('id')->first();

        return [
            'status' => $request->user()->kyc_status,
            'submission' => $latest ? new KycResource($latest) : null,
        ];
    }

    /**
     * Submit documents.
     *
     * Send as `multipart/form-data`: the document number and details, a photo of the front (and back) of the document,
     * and a selfie holding it. An admin reviews it; you are notified. Not possible while one is pending or after approval
     * (`kyc_pending`, `kyc_approved`); after a rejection you may send new documents.
     */
    public function store(KycRequest $request): JsonResponse
    {
        $submission = $this->kyc->submit(
            $request->user(), $request->safe()->only(['documentType', 'documentNumber', 'fullName', 'dateOfBirth']),
            $request->file('front'), $request->file('back'), $request->file('selfie'),
        );

        return (new KycResource($submission))->response()->setStatusCode(201);
    }
}
