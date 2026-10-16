<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignVisit;
use App\Services\CampaignService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminCampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    /**
     * List campaigns.
     *
     * Every campaign with its owner and visit totals, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([Campaign::DRAFT, Campaign::ACTIVE, Campaign::PAUSED, Campaign::COMPLETED, Campaign::CANCELLED])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Campaign::with('owner')
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()->latest('id')
            ->paginate($request->integer('perPage', 20));

        $counts = CampaignVisit::whereIn('campaign_id', $page->pluck('id'))
            ->selectRaw('campaign_id, status, count(*) as total')->groupBy('campaign_id', 'status')->get()->groupBy('campaign_id');

        foreach ($page as $campaign) {
            $rows = $counts[$campaign->id] ?? collect();
            $valid = (int) $rows->firstWhere('status', CampaignVisit::VALID)?->total;
            $rejected = (int) $rows->firstWhere('status', CampaignVisit::REJECTED)?->total;
            $campaign->visit_stats = ['visits' => $valid + $rejected, 'validVisits' => $valid, 'rejectedVisits' => $rejected];
        }

        return CampaignResource::collection($page);
    }

    /**
     * Stop a campaign.
     *
     * For campaigns that break the rules. The unspent budget goes back to the owner.
     */
    public function cancel(int $campaign): CampaignResource
    {
        return new CampaignResource($this->campaigns->close(Campaign::findOrFail($campaign))->load('owner'));
    }
}
