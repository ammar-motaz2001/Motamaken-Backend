<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginatedRequest;
use App\Http\Resources\CampaignPromoterResource;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignPromoter;
use App\Models\CampaignVisit;
use App\Services\CampaignService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Promotion campaigns', 'Pay promoters per valid visit to your project or profile. Visits are filtered for robots, repeats and bought traffic.', weight: 12)]
class CampaignPromotingController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    /**
     * Campaigns to promote.
     *
     * Running campaigns from other people that still have budget, best paid first.
     */
    public function available(PaginatedRequest $request): AnonymousResourceCollection
    {
        return CampaignResource::collection(
            Campaign::with('owner')->where('status', Campaign::ACTIVE)->where('owner_id', '!=', $request->user()->id)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->whereColumn('spent_cents', '<', 'budget_cents')
                ->orderByDesc('cost_per_visit_cents')->latest('id')
                ->paginate($request->integer('perPage', 15)),
        );
    }

    /**
     * Get my link.
     *
     * Join a running campaign and get the link to share. Asking again returns the same link.
     */
    public function join(Request $request, int $campaign): JsonResponse
    {
        $promoter = $this->campaigns->join(Campaign::findOrFail($campaign), $request->user());

        return (new CampaignPromoterResource($promoter->load('campaign')))->response()->setStatusCode($promoter->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Promoter dashboard.
     *
     * Totals across every campaign you promote, your links, and the last 14 days of paid visits and earnings.
     *
     * @return array{totals: array{validVisits: int, rejectedVisits: int, earned: list<array{currency: string, amount: float}>}, links: list<array<string, mixed>>, daily: list<array{date: string, visits: int}>}
     */
    public function dashboard(Request $request): array
    {
        $links = CampaignPromoter::with('campaign')->where('user_id', $request->user()->id)->latest('id')->get();
        $since = now()->subDays(13)->startOfDay();

        $daily = CampaignVisit::query()
            ->whereIn('promoter_id', $links->pluck('id'))->where('status', CampaignVisit::VALID)->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) as day, count(*) as total')->groupBy('day')->get()
            ->mapWithKeys(fn ($row) => [substr((string) $row->day, 0, 10) => (int) $row->total]);

        return [
            'totals' => [
                'validVisits' => (int) $links->sum('valid_visits'),
                'rejectedVisits' => (int) $links->sum('rejected_visits'),
                /** Earnings per currency. */
                'earned' => $links->groupBy(fn ($link) => $link->campaign->currency)
                    ->map(fn ($group, $currency) => ['currency' => $currency, 'amount' => $group->sum('earned_cents') / 100])->values()->all(),
            ],
            'links' => CampaignPromoterResource::collection($links)->resolve(),
            'daily' => collect(range(0, 13))->map(fn (int $offset) => [
                'date' => $day = $since->copy()->addDays($offset)->toDateString(),
                'visits' => $daily[$day] ?? 0,
            ])->all(),
        ];
    }

    /**
     * Report a visit.
     *
     * Called by the website's link page (`/r/{code}`) as soon as it opens, from the visitor's browser. The visit is checked
     * against the campaign's filters and, when valid, the promoter is paid. Answers where to send the visitor
     * (`path` on the website) either way; an unknown code gives `path` null.
     *
     * @unauthenticated
     *
     * @return array{recorded: bool, valid: bool, path: ?string}
     */
    public function visit(Request $request): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            /** `document.referrer` of the page, if any. */
            'referrer' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->campaigns->recordVisit($data['code'], [
            'ip' => $request->ip(),
            'userAgent' => $request->userAgent(),
            // Sent by Cloudflare and most CDNs; absent in local development.
            'country' => $request->header('CF-IPCountry'),
            'referrer' => $data['referrer'] ?? null,
            'visitorId' => $request->user('sanctum')?->id,
        ]);

        return [
            'recorded' => $result['visit'] !== null,
            'valid' => $result['visit']?->status === CampaignVisit::VALID,
            'path' => $result['path'],
        ];
    }
}
