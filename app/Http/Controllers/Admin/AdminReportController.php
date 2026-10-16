<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PortfolioItem;
use App\Models\Project;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminReportController extends Controller
{
    /**
     * Content reports.
     *
     * What users flagged (profiles, projects, portfolio items, reviews), newest first. Open ones by default. Each report
     * carries a short summary of the flagged item and a link to it.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, counts: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            /** `open`, `actioned` or `dismissed`. */
            'status' => ['nullable', Rule::in(Report::STATUSES)],
            'targetType' => ['nullable', Rule::in(Report::TARGETS)],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Report::with('reporter')
            ->where('status', $request->input('status', Report::OPEN))
            ->when($request->input('targetType'), fn ($q, $type) => $q->where('target_type', $type))
            ->latest('id')->paginate($request->integer('perPage', 20));
        $targets = $this->targets($page->getCollection());

        return [
            'data' => $page->getCollection()->map(fn (Report $r) => $this->present($r, $targets))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            /** Reports per status, for the tabs. */
            'counts' => collect(Report::STATUSES)->mapWithKeys(fn ($s) => [$s => 0])
                ->merge(Report::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'))
                ->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /**
     * Decide a report.
     *
     * `actioned` when the content broke the rules (act on it from its own admin screen, e.g. suspend the user or close the
     * project), `dismissed` when it did not. The reporter sees the decision and the note under "My reports".
     *
     * @return array{data: array<string, mixed>}
     */
    public function resolve(Request $request, int $report): array
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([Report::ACTIONED, Report::DISMISSED])],
            /** Shown to the reporter. */
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = Report::with('reporter')->findOrFail($report);
        if ($report->status !== Report::OPEN) {
            throw new ApiException(__('admin.report_closed'), 'report_closed');
        }

        $report->update(['status' => $data['decision'], 'resolution_note' => $data['note'] ?? null, 'reviewed_at' => now()]);

        return ['data' => $this->present($report, $this->targets(collect([$report])))];
    }

    /**
     * Summaries of the flagged items, keyed "type:id".
     *
     * @param  Collection<int, Report>  $reports
     * @return array<string, array{title: string, path: string|null}>
     */
    private function targets(Collection $reports): array
    {
        $ids = fn (string $type) => $reports->where('target_type', $type)->pluck('target_id')->unique()->all();
        $out = [];

        foreach (User::whereIn('id', $ids('user'))->get() as $user) {
            $out["user:{$user->id}"] = ['title' => $user->full_name, 'path' => "/users/{$user->id}"];
        }
        foreach (Project::whereIn('id', $ids('project'))->get() as $project) {
            $out["project:{$project->id}"] = ['title' => $project->title, 'path' => "/projects/{$project->id}"];
        }
        foreach (PortfolioItem::with('profile')->whereIn('id', $ids('portfolio'))->get() as $item) {
            $out["portfolio:{$item->id}"] = ['title' => $item->title, 'path' => $item->profile ? "/users/{$item->profile->user_id}" : null];
        }
        foreach (Review::whereIn('id', $ids('review'))->get() as $review) {
            $out["review:{$review->id}"] = ['title' => str($review->comment ?? '')->limit(80)->toString(), 'path' => "/users/{$review->reviewee_id}?tab=reviews"];
        }

        return $out;
    }

    /**
     * @param  array<string, array{title: string, path: string|null}>  $targets
     * @return array<string, mixed>
     */
    private function present(Report $r, array $targets): array
    {
        return [
            'id' => $r->id,
            'targetType' => $r->target_type,
            'targetId' => $r->target_id,
            /** Null when the item was deleted since. */
            'target' => $targets["{$r->target_type}:{$r->target_id}"] ?? null,
            'reason' => $r->reason,
            'details' => $r->details,
            'status' => $r->status,
            'resolutionNote' => $r->resolution_note,
            'reporter' => $r->reporter ? ['id' => $r->reporter->id, 'fullName' => $r->reporter->full_name, 'avatarUrl' => $r->reporter->avatar_url] : null,
            'createdAt' => $r->created_at?->toIso8601String(),
            'reviewedAt' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
