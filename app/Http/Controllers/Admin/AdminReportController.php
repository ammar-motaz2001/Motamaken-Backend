<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\PortfolioItem;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Review;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\ProposalService;
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
            'priority' => ['nullable', Rule::in(Report::PRIORITIES)],
            /** `user` (reported by people) or `ai` (flagged by the automatic screening). */
            'source' => ['nullable', Rule::in(['user', 'ai'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Report::with(['reporter', 'handler'])
            ->where('status', $request->input('status', Report::OPEN))
            ->when($request->input('targetType'), fn ($q, $type) => $q->where('target_type', $type))
            ->when($request->input('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->input('source'), fn ($q, $s) => $q->where('source', $s))
            // High priority first, then the newest.
            ->orderByRaw("case priority when 'high' then 0 when 'normal' then 1 else 2 end")
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
     * `actioned` when the content broke the rules, `dismissed` when it did not. With `action` the moderator also acts from
     * here: `hidden` (hides a project or review), `removed` (deletes a portfolio item, clears a message), `suspended`
     * (suspends the person responsible, the note is the reason), `closed` (closes a project), `warned` (tells them), or
     * `none`. The reporter sees the decision and the note under "My reports".
     *
     * @return array{data: array<string, mixed>}
     */
    public function resolve(Request $request, int $report): array
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([Report::ACTIONED, Report::DISMISSED])],
            /** Shown to the reporter. */
            'note' => ['nullable', 'required_if:action,suspended', 'string', 'max:1000'],
            'action' => ['nullable', Rule::in(Report::ACTIONS)],
        ]);

        $report = Report::with('reporter')->findOrFail($report);
        if ($report->status !== Report::OPEN) {
            throw new ApiException(__('admin.report_closed'), 'report_closed');
        }

        $action = $data['decision'] === Report::ACTIONED ? ($data['action'] ?? 'none') : null;
        if ($action && $action !== 'none') {
            $this->act($report, $action, $data['note'] ?? '', $request->user());
        }

        $report->update([
            'status' => $data['decision'], 'resolution_note' => $data['note'] ?? null, 'reviewed_at' => now(),
            'action_taken' => $action, 'handled_by' => $request->user()->id,
        ]);

        return ['data' => $this->present($report, $this->targets(collect([$report])))];
    }

    /**
     * Set the priority.
     *
     * @return array{data: array<string, mixed>}
     */
    public function priority(Request $request, int $report): array
    {
        $data = $request->validate(['priority' => ['required', Rule::in(Report::PRIORITIES)]]);
        $report = Report::with('reporter')->findOrFail($report);
        $report->update(['priority' => $data['priority']]);

        return ['data' => $this->present($report, $this->targets(collect([$report])))];
    }

    /**
     * Carry out the moderator's action on the reported item.
     */
    private function act(Report $report, string $action, string $note, User $admin): void
    {
        $id = $report->target_id;
        $reason = $note !== '' ? $note : __('admin.report_action_reason', ['reason' => __("admin.report_reasons.{$report->reason}")]);

        $owner = match ($report->target_type) {
            'user' => User::find($id),
            'project' => Project::find($id)?->user,
            'portfolio' => PortfolioItem::with('profile.user')->find($id)?->profile?->user,
            'review' => Review::withoutGlobalScope('visible')->find($id)?->reviewer,
            'proposal' => Proposal::find($id)?->user,
            'team' => Team::find($id)?->owner,
            'message' => Message::find($id)?->sender,
        };

        match (true) {
            $action === 'hidden' && $report->target_type === 'project' => Project::whereKey($id)->update(['hidden_at' => now(), 'hidden_reason' => $reason, 'is_featured' => false]),
            $action === 'hidden' && $report->target_type === 'review' => $this->hideReview($id, $reason, $admin),
            $action === 'closed' && $report->target_type === 'project' && ($project = Project::find($id))?->isOpen() => app(ProposalService::class)->close($project),
            $action === 'removed' && $report->target_type === 'portfolio' => PortfolioItem::whereKey($id)->delete(),
            $action === 'removed' && $report->target_type === 'message' => Message::whereKey($id)->update(['body' => null, 'attachment_path' => null, 'attachment_name' => null, 'type' => 'text', 'removed_at' => now()]),
            $action === 'removed' && $report->target_type === 'proposal' => Proposal::whereKey($id)->where('status', Proposal::STATUS_PENDING)->update(['status' => Proposal::STATUS_DECLINED]),
            $action === 'suspended' && $owner && ! $owner->is_admin => $this->suspend($owner, $reason),
            default => null,
        };

        if ($owner && in_array($action, ['warned', 'hidden', 'removed', 'closed'], true)) {
            $owner->notify(new ActivityNotification('report.action', null, ['action' => __("admin.report_actions.{$action}"), 'reason' => $reason]));
        }
    }

    private function hideReview(int $id, string $reason, User $admin): void
    {
        $review = Review::withoutGlobalScope('visible')->find($id);
        if ($review) {
            $review->forceFill(['hidden_at' => now(), 'hidden_reason' => $reason, 'hidden_by' => $admin->id])->save();
            Review::refreshStats($review->reviewee_id);
        }
    }

    private function suspend(User $user, string $reason): void
    {
        $user->forceFill(['suspended_at' => now(), 'suspended_until' => null, 'suspension_reason' => $reason])->save();
        $user->tokens()->delete();
    }

    /**
     * Summaries of the flagged items, keyed "type:id".
     *
     * @param  Collection<int, Report>  $reports
     * @return array<string, array{title: string, path: string|null, context?: ?string}>
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
        foreach (Proposal::with('project')->whereIn('id', $ids('proposal'))->get() as $proposal) {
            $out["proposal:{$proposal->id}"] = ['title' => str($proposal->message)->limit(80)->toString(), 'path' => "/admin/projects/{$proposal->project_id}", 'context' => $proposal->project?->title];
        }
        foreach (Team::whereIn('id', $ids('team'))->get() as $team) {
            $out["team:{$team->id}"] = ['title' => $team->name, 'path' => "/admin/teams/{$team->id}"];
        }
        // The one private message the staff may read: the reported one, with who sent it.
        foreach (Message::with('sender')->whereIn('id', $ids('message'))->get() as $message) {
            $out["message:{$message->id}"] = [
                'title' => $message->body ? str($message->body)->limit(300)->toString() : ($message->attachment_name ?? '—'),
                'path' => $message->sender ? "/admin/users/{$message->sender_id}" : null,
                'context' => $message->sender?->full_name,
            ];
        }
        foreach (Review::withoutGlobalScope('visible')->whereIn('id', $ids('review'))->get() as $review) {
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
            /** `low`, `normal` or `high`. */
            'priority' => $r->priority,
            /** `user` or `ai`. */
            'source' => $r->source,
            'actionTaken' => $r->action_taken,
            'handledBy' => $r->relationLoaded('handler') && $r->handler ? ['id' => $r->handler->id, 'fullName' => $r->handler->full_name] : null,
            'reporter' => $r->reporter ? ['id' => $r->reporter->id, 'fullName' => $r->reporter->full_name, 'avatarUrl' => $r->reporter->avatar_url] : null,
            'createdAt' => $r->created_at?->toIso8601String(),
            'reviewedAt' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
