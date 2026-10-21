<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\Review;
use App\Support\LikeSearch;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminReviewController extends Controller
{
    /**
     * Reviews.
     *
     * Every rating left on completed projects, hidden ones included, with the number of reports on each. Also the
     * overall average and the spread of stars.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, summary: array{average: float, total: int, hidden: int, stars: array<string, int>}}
     */
    public function index(Request $request): array
    {
        $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5'],
            /** `visible` or `hidden`. */
            'state' => ['nullable', Rule::in(['visible', 'hidden'])],
            /** `client` (reviews of project owners), `freelancer` or `influencer` (by the reviewee's profile). */
            'about' => ['nullable', Rule::in(['client', 'freelancer', 'influencer'])],
            'userId' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($request)->with(['reviewer', 'reviewee.profile', 'project'])->latest('id')->paginate($request->integer('perPage', 20));
        $reports = Report::where('target_type', 'review')->whereIn('target_id', collect($page->items())->pluck('id'))
            ->selectRaw('target_id, count(*) as total')->groupBy('target_id')->pluck('total', 'target_id');
        $all = Review::withoutGlobalScope('visible');

        return Paged::of($page, fn (Review $r) => self::present($r, (int) ($reports[$r->id] ?? 0)), [
            'summary' => [
                'average' => round((float) Review::avg('rating'), 2),
                'total' => (clone $all)->count(),
                'hidden' => (clone $all)->whereNotNull('hidden_at')->count(),
                'stars' => collect(range(1, 5))->mapWithKeys(fn ($n) => [(string) $n => 0])
                    ->merge(Review::selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating')->mapWithKeys(fn ($n, $k) => [(string) $k => (int) $n]))->all(),
            ],
        ]);
    }

    /**
     * The reviews query behind the list, hidden ones included, shared with the CSV export.
     *
     * @return Builder<Review>
     */
    public function filtered(Request $request): Builder
    {
        return Review::withoutGlobalScope('visible')
            ->when($request->input('rating'), fn ($q, $rating) => $q->where('rating', $rating))
            ->when($request->input('state') === 'hidden', fn ($q) => $q->whereNotNull('hidden_at'))
            ->when($request->input('state') === 'visible', fn ($q) => $q->whereNull('hidden_at'))
            ->when($request->input('about') === 'client', fn ($q) => $q->whereDoesntHave('reviewee.profile'))
            ->when(in_array($request->input('about'), ['freelancer', 'influencer'], true), fn ($q) => $q->whereHas('reviewee.profile', fn ($p) => $p->where('type', $request->input('about'))))
            ->when($request->input('userId'), fn ($q, $id) => $q->where(fn ($w) => $w->where('reviewer_id', $id)->orWhere('reviewee_id', $id)))
            ->when($request->input('q'), fn ($q, $term) => LikeSearch::apply($q, ['comment'], $term));
    }

    /**
     * Hide a review.
     *
     * It leaves the profile, the average and the medals count; the reason stays in the record.
     *
     * @return array{data: array<string, mixed>}
     */
    public function hide(Request $request, int $review): array
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $review = Review::withoutGlobalScope('visible')->findOrFail($review);
        $review->forceFill(['hidden_at' => now(), 'hidden_reason' => $data['reason'], 'hidden_by' => $request->user()->id])->save();
        Review::refreshStats($review->reviewee_id);

        return ['data' => self::present($review->load(['reviewer', 'reviewee.profile', 'project']))];
    }

    /**
     * Show a hidden review again.
     *
     * @return array{data: array<string, mixed>}
     */
    public function unhide(int $review): array
    {
        $review = Review::withoutGlobalScope('visible')->findOrFail($review);
        $review->forceFill(['hidden_at' => null, 'hidden_reason' => null, 'hidden_by' => null])->save();
        Review::refreshStats($review->reviewee_id);

        return ['data' => self::present($review->load(['reviewer', 'reviewee.profile', 'project']))];
    }

    /**
     * A review for the admin list, with how many times it was reported.
     *
     * @return array<string, mixed>
     */
    public static function present(Review $r, int $reports = 0): array
    {
        return [
            'id' => $r->id,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'project' => $r->project ? ['id' => $r->project->id, 'title' => $r->project->title] : null,
            'reviewer' => Paged::user($r->reviewer),
            'reviewee' => Paged::user($r->reviewee),
            /** The reviewee's account type. */
            'about' => $r->reviewee?->profile?->type ?? 'client',
            'hidden' => $r->hidden_at !== null,
            'hiddenReason' => $r->hidden_reason,
            'reports' => $reports,
            'createdAt' => $r->created_at?->toIso8601String(),
        ];
    }
}
