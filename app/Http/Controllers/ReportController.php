<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\PaginatedRequest;
use App\Models\Message;
use App\Models\PortfolioItem;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Review;
use App\Models\Team;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Profiles & reviews', 'Public profiles, portfolio, reviews and content reports.', weight: 5)]
class ReportController extends Controller
{
    /**
     * Report content.
     *
     * Flags a profile (`user`), `project`, `portfolio` item, `review`, offer (`proposal`), `team` or chat `message` for
     * the moderators. Once per item (`already_reported`); you cannot report yourself. A reported message is the only
     * private message the staff can read.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'targetType' => ['required', Rule::in(Report::TARGETS)],
            'targetId' => ['required', 'integer'],
            /** `spam`, `fake`, `inappropriate`, `copyright`, `scam` or `other`. */
            'reason' => ['required', Rule::in(Report::REASONS)],
            'details' => [Rule::requiredIf($request->input('reason') === 'other'), 'nullable', 'string', 'min:10', 'max:2000'],
        ]);

        $owner = match ($data['targetType']) {
            'user' => User::find($data['targetId'])?->id,
            'project' => Project::find($data['targetId'])?->user_id,
            'portfolio' => PortfolioItem::with('profile')->find($data['targetId'])?->profile?->user_id,
            'review' => Review::find($data['targetId'])?->reviewer_id,
            'proposal' => Proposal::find($data['targetId'])?->user_id,
            'team' => Team::find($data['targetId'])?->owner_id,
            // Only someone in the conversation can report a message: the staff never browse private chats.
            'message' => Message::whereKey($data['targetId'])
                ->whereHas('conversation.participants', fn ($q) => $q->where('users.id', $request->user()->id))->value('sender_id'),
        };
        abort_if($owner === null, 404);

        if ($owner === $request->user()->id) {
            throw new ApiException(__('profiles.report_self'), 'report_self');
        }

        $report = Report::firstOrNew(['reporter_id' => $request->user()->id, 'target_type' => $data['targetType'], 'target_id' => $data['targetId']]);
        if ($report->exists) {
            throw new ApiException(__('profiles.already_reported'), 'already_reported');
        }
        $report->fill([
            'reason' => $data['reason'], 'details' => $data['details'] ?? null,
            // Fraud jumps the queue; the staff can change it.
            'priority' => in_array($data['reason'], ['scam', 'copyright'], true) ? 'high' : 'normal',
        ])->save();

        return response()->json(['data' => $this->present($report)], 201);
    }

    /**
     * My reports.
     *
     * What I reported and what the moderators decided.
     */
    public function mine(PaginatedRequest $request): array
    {
        $page = Report::where('reporter_id', $request->user()->id)->latest()->latest('id')->paginate($request->integer('perPage', 15));

        return [
            'data' => collect($page->items())->map(fn (Report $report) => $this->present($report))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            /** Counts per status for the tabs. */
            'counts' => Report::where('reporter_id', $request->user()->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ];
    }

    /**
     * A report as its reporter sees it.
     *
     * @return array<string, mixed>
     */
    private function present(Report $report): array
    {
        return [
            'id' => $report->id,
            'targetType' => $report->target_type,
            'targetId' => $report->target_id,
            'reason' => $report->reason,
            'details' => $report->details,
            /** `open`, `actioned` or `dismissed`. */
            'status' => $report->status,
            'resolutionNote' => $report->resolution_note,
            'createdAt' => $report->created_at,
            'reviewedAt' => $report->reviewed_at,
        ];
    }
}
