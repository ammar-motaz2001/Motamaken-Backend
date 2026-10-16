<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminFeedbackController extends Controller
{
    /**
     * Payment feedback.
     *
     * The thumbs up / down users leave after a payment (deposit, withdrawal, promotion, subscription, card, escrow),
     * newest first, with the satisfaction rate per context.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, summary: list<array{context: string, up: int, down: int, satisfaction: int|null}>}
     */
    public function __invoke(Request $request): array
    {
        $request->validate([
            'context' => ['nullable', Rule::in(Feedback::CONTEXTS)],
            /** `up` or `down`. */
            'rating' => ['nullable', Rule::in(['up', 'down'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Feedback::query()
            ->when($request->input('context'), fn ($q, $c) => $q->where('context', $c))
            ->when($request->input('rating'), fn ($q, $r) => $q->where('rating', $r))
            ->latest('id')->paginate($request->integer('perPage', 20));
        $users = User::whereIn('id', $page->getCollection()->pluck('user_id'))->get()->keyBy('id');
        $counts = Feedback::selectRaw('context, rating, count(*) as total')->groupBy('context', 'rating')->get()->groupBy('context');

        return [
            'data' => $page->getCollection()->map(fn (Feedback $f) => [
                'id' => $f->id,
                'context' => $f->context,
                'referenceId' => $f->reference_id,
                'rating' => $f->rating,
                'comment' => $f->comment,
                'user' => ($u = $users[$f->user_id] ?? null) ? ['id' => $u->id, 'fullName' => $u->full_name, 'avatarUrl' => $u->avatar_url] : null,
                'createdAt' => $f->created_at?->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'summary' => collect(Feedback::CONTEXTS)->map(function (string $context) use ($counts) {
                $rows = $counts[$context] ?? collect();
                $up = (int) $rows->firstWhere('rating', 'up')?->total;
                $down = (int) $rows->firstWhere('rating', 'down')?->total;

                return ['context' => $context, 'up' => $up, 'down' => $down, 'satisfaction' => $up + $down ? (int) round($up * 100 / ($up + $down)) : null];
            })->all(),
        ];
    }
}
