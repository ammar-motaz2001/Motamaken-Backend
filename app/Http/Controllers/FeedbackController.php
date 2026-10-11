<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Header & account', 'The logged-in header: balance, badges, notifications, language/currency/theme, own profile.', weight: 4)]
class FeedbackController extends Controller
{
    /**
     * Send quick feedback.
     *
     * The thumbs up / down asked after a payment ("How satisfied were you with your payment experience?").
     *
     * @return array{success: bool}
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** What it is about: `deposit`, `withdrawal`, `promotion`, `subscription`, `payment_method` or `escrow`. */
            'context' => ['required', Rule::in(Feedback::CONTEXTS)],
            /** The id of the deposit, withdrawal... it is about. */
            'referenceId' => ['nullable', 'integer'],
            /** `up` or `down`. */
            'rating' => ['required', Rule::in(['up', 'down'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        Feedback::create([
            'user_id' => $request->user()->id,
            'context' => $data['context'],
            'reference_id' => $data['referenceId'] ?? null,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        return response()->json(['success' => true], 201);
    }
}
