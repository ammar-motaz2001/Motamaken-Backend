<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignRequest;
use App\Http\Requests\PaginatedRequest;
use App\Http\Resources\CampaignPromoterResource;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\CampaignVisitResource;
use App\Models\Campaign;
use App\Models\CampaignVisit;
use App\Services\CampaignService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

#[Group('Promotion campaigns', 'Pay promoters per valid visit to your project or profile. Visits are filtered for robots, repeats and bought traffic.', weight: 12)]
class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    /**
     * My campaigns.
     *
     * The campaigns list: everything you created, newest first, with visit totals.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([Campaign::DRAFT, Campaign::ACTIVE, Campaign::PAUSED, Campaign::COMPLETED, Campaign::CANCELLED])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $page = Campaign::where('owner_id', $request->user()->id)
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()->latest('id')
            ->paginate($request->integer('perPage', 15));

        $page->getCollection()->each(fn (Campaign $campaign) => $this->withStats($campaign));

        return CampaignResource::collection($page);
    }

    /**
     * Create a campaign.
     *
     * Saved as a draft: nothing is charged until you launch it. Links can lead to one of your open projects or to your profile.
     */
    public function store(CampaignRequest $request): JsonResponse
    {
        $campaign = $this->campaigns->create($request->user(), $request->validated());

        return (new CampaignResource($this->withStats($campaign)))->response()->setStatusCode(201);
    }

    /**
     * Show a campaign.
     *
     * Owner only. Includes `stats`: visit totals, the reasons visits were turned away (the pie chart) and the top promoters.
     */
    public function show(Request $request, int $campaign): CampaignResource
    {
        return new CampaignResource($this->withStats($this->viewable($campaign), detailed: true));
    }

    /**
     * Change a campaign.
     *
     * A draft can change everything; a running campaign only its `filters` and `endsAt` (`campaign_locked` otherwise).
     */
    public function update(CampaignRequest $request, int $campaign): CampaignResource
    {
        return new CampaignResource($this->withStats($this->campaigns->update($this->owned($campaign), $request->validated())));
    }

    /**
     * Launch.
     *
     * Takes the whole budget from your wallet (`insufficient_funds` otherwise) and starts taking visits.
     */
    public function launch(Request $request, int $campaign): CampaignResource
    {
        return new CampaignResource($this->withStats($this->campaigns->launch($this->owned($campaign))));
    }

    /**
     * Pause.
     *
     * Visits are still recorded but not paid while it is paused.
     */
    public function pause(Request $request, int $campaign): CampaignResource
    {
        return new CampaignResource($this->withStats($this->campaigns->pause($this->owned($campaign))));
    }

    /**
     * Resume.
     */
    public function resume(Request $request, int $campaign): CampaignResource
    {
        return new CampaignResource($this->withStats($this->campaigns->resume($this->owned($campaign))));
    }

    /**
     * Stop for good.
     *
     * What is left of the budget goes back to your wallet.
     */
    public function cancel(Request $request, int $campaign): CampaignResource
    {
        return new CampaignResource($this->withStats($this->campaigns->close($this->owned($campaign))));
    }

    /**
     * Promoters.
     *
     * Who promotes the campaign and what each brought, best first.
     */
    public function promoters(PaginatedRequest $request, int $campaign): AnonymousResourceCollection
    {
        return CampaignPromoterResource::collection(
            $this->viewable($campaign)->promoters()->with('user')->orderByDesc('valid_visits')->orderBy('id')
                ->paginate($request->integer('perPage', 20)),
        );
    }

    /**
     * Visits.
     *
     * The filtering screen: every visit with why it was or was not paid, newest first.
     */
    public function visits(Request $request, int $campaign): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([CampaignVisit::VALID, CampaignVisit::REJECTED])],
            'reason' => ['nullable', Rule::in(CampaignVisit::REASONS)],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return CampaignVisitResource::collection(
            $this->viewable($campaign)->visits()->with('promoter.user')
                ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
                ->when($request->input('reason'), fn ($query, $reason) => $query->where('reject_reason', $reason))
                ->latest('id')
                ->paginate($request->integer('perPage', 30)),
        );
    }

    /**
     * Daily chart.
     *
     * Valid and rejected visits and the money spent per day, oldest first.
     *
     * @return array{currency: string, data: list<array{date: string, valid: int, rejected: int, spent: float}>}
     */
    public function daily(Request $request, int $campaign): array
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:90']]);

        $campaign = $this->viewable($campaign);
        $days = $request->integer('days', 14);
        $since = now()->subDays($days - 1)->startOfDay();

        $rows = $campaign->visits()->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) as day, status, count(*) as total')
            ->groupBy('day', 'status')->get()
            ->groupBy(fn ($row) => substr((string) $row->day, 0, 10));

        return ['currency' => $campaign->currency, 'data' => collect(range(0, $days - 1))->map(function (int $offset) use ($since, $rows, $campaign) {
            $day = $since->copy()->addDays($offset)->toDateString();
            $valid = (int) ($rows[$day] ?? collect())->firstWhere('status', CampaignVisit::VALID)?->total;

            return [
                'date' => $day,
                'valid' => $valid,
                'rejected' => (int) ($rows[$day] ?? collect())->firstWhere('status', CampaignVisit::REJECTED)?->total,
                'spent' => $valid * $campaign->cost_per_visit_cents / 100,
            ];
        })->all()];
    }

    /**
     * Finds a campaign the current user may see (its owner, or marketplace staff), or fails with 404 / 403.
     */
    private function viewable(int $id): Campaign
    {
        $campaign = Campaign::findOrFail($id);
        Gate::authorize('view', $campaign);

        return $campaign;
    }

    /**
     * Finds a campaign the current user may manage, or fails with 404 / 403.
     */
    private function owned(int $id): Campaign
    {
        $campaign = Campaign::findOrFail($id);
        Gate::authorize('manage', $campaign);

        return $campaign;
    }

    /**
     * Attaches visit and promoter counts to a campaign; the detailed view adds reject reasons and the top 5 promoters.
     */
    private function withStats(Campaign $campaign, bool $detailed = false): Campaign
    {
        $counts = $campaign->visits()->selectRaw('status, reject_reason, count(*) as total')->groupBy('status', 'reject_reason')->get();
        $valid = (int) $counts->where('status', CampaignVisit::VALID)->sum('total');
        $rejected = (int) $counts->where('status', CampaignVisit::REJECTED)->sum('total');

        $stats = [
            'visits' => $valid + $rejected,
            'validVisits' => $valid,
            'rejectedVisits' => $rejected,
            /** Share of visits that were paid, in percent. */
            'validRate' => $valid + $rejected > 0 ? round($valid * 100 / ($valid + $rejected), 1) : 0,
            'promoters' => $campaign->promoters()->count(),
        ];

        if ($detailed) {
            $stats['rejections'] = $counts->where('status', CampaignVisit::REJECTED)->map(fn ($row) => [
                'reason' => $row->reject_reason, 'label' => __("campaigns.reasons.{$row->reject_reason}"), 'count' => (int) $row->total,
            ])->sortByDesc('count')->values()->all();
            $stats['topPromoters'] = CampaignPromoterResource::collection(
                $campaign->promoters()->with('user')->orderByDesc('valid_visits')->limit(5)->get(),
            )->resolve();
        }

        $campaign->visit_stats = $stats;

        return $campaign;
    }
}
