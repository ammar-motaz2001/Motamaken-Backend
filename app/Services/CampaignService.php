<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignPromoter;
use App\Models\CampaignVisit;
use App\Models\Profile;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Payments\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pay-per-visit promotion. The budget leaves the owner's wallet when the campaign starts and waits in the campaigns
 * account; every valid visit moves the cost of one visit out of it, to the promoter and to the platform; whatever is left
 * when it closes goes back to the owner.
 */
class CampaignService
{
    /** User agents that announce themselves as robots or programs ("node" is what a server-side fetch sends). */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|curl|wget|python|httpclient|okhttp|headless|phantom|scrapy|facebookexternalhit|preview|^node$|undici|axios|go-http|java\//i';

    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @param  array{title: string, description?: ?string, targetType: string, targetId?: ?int, currency: string, budget: float|int|string, costPerVisit: float|int|string, startsAt?: ?string, endsAt?: ?string, filters?: array<string, mixed>}  $data
     */
    public function create(User $owner, array $data): Campaign
    {
        $targetId = $data['targetType'] === Campaign::TARGET_PROFILE
            ? (Profile::where('user_id', $owner->id)->exists() ? $owner->id : throw new ApiException(__('proposals.profile_required'), 'profile_required'))
            : $this->ownOpenProject($owner, $data['targetId'] ?? null)->id;

        $budget = Money::toCents($data['budget']);
        $cost = Money::toCents($data['costPerVisit']);
        $this->assertAmounts($data['currency'], $budget, $cost);

        return Campaign::create([
            'owner_id' => $owner->id, 'title' => $data['title'], 'description' => $data['description'] ?? null,
            'target_type' => $data['targetType'], 'target_id' => $targetId, 'currency' => $data['currency'],
            'budget_cents' => $budget, 'cost_per_visit_cents' => $cost,
            'fee_percent' => config('marketplace.campaigns.fee_percent'),
            'filters' => $this->filters([], $data['filters'] ?? []),
            'starts_at' => $data['startsAt'] ?? null, 'ends_at' => $data['endsAt'] ?? null,
        ]);
    }

    /**
     * Change a draft. Once it started only the filters and the end date can change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Campaign $campaign, array $data): Campaign
    {
        if ($campaign->isClosed()) {
            throw new ApiException(__('campaigns.closed'), 'campaign_closed');
        }

        if (isset($data['filters'])) {
            $campaign->filters = $this->filters($campaign->filters, $data['filters']);
        }

        if (array_key_exists('endsAt', $data)) {
            $campaign->ends_at = $data['endsAt'];
        }

        if ($campaign->status === Campaign::DRAFT) {
            $campaign->fill(array_filter([
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'budget_cents' => isset($data['budget']) ? Money::toCents($data['budget']) : null,
                'cost_per_visit_cents' => isset($data['costPerVisit']) ? Money::toCents($data['costPerVisit']) : null,
                'starts_at' => $data['startsAt'] ?? null,
            ], fn ($value) => $value !== null));
            $this->assertAmounts($campaign->currency, $campaign->budget_cents, $campaign->cost_per_visit_cents);
        } elseif (array_intersect(array_keys($data), ['title', 'budget', 'costPerVisit', 'startsAt'])) {
            throw new ApiException(__('campaigns.locked'), 'campaign_locked');
        }

        $campaign->save();

        return $campaign;
    }

    /**
     * Start a draft: the whole budget moves from the owner's wallet into the campaigns account.
     */
    public function launch(Campaign $campaign): Campaign
    {
        DB::transaction(function () use ($campaign) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Campaign::DRAFT) {
                throw new ApiException(__('campaigns.not_draft'), 'campaign_not_draft');
            }

            $this->ledger->post('campaign_funding', [
                [$this->ledger->wallet($locked->owner, $locked->currency), -$locked->budget_cents],
                [$this->ledger->system(Account::CAMPAIGNS, $locked->currency), $locked->budget_cents],
            ], ['reference' => $locked, 'key' => "campaign:{$locked->id}:fund", 'meta' => ['campaign' => $locked->title]]);

            $locked->update(['status' => Campaign::ACTIVE, 'starts_at' => $locked->starts_at ?? now()]);
        });

        return $campaign->refresh();
    }

    public function pause(Campaign $campaign): Campaign
    {
        if ($campaign->status !== Campaign::ACTIVE) {
            throw new ApiException(__('campaigns.not_active'), 'campaign_not_active');
        }

        $campaign->update(['status' => Campaign::PAUSED]);

        return $campaign;
    }

    public function resume(Campaign $campaign): Campaign
    {
        if ($campaign->status !== Campaign::PAUSED) {
            throw new ApiException(__('campaigns.not_paused'), 'campaign_not_paused');
        }

        if ($campaign->ends_at?->isPast()) {
            throw new ApiException(__('campaigns.ended'), 'campaign_ended');
        }

        $campaign->update(['status' => Campaign::ACTIVE]);

        return $campaign;
    }

    /**
     * Stop for good. The unspent budget goes back to the owner. `$status` is `cancelled` (the owner or an admin stopped it)
     * or `completed` (the budget ran out or the end date passed).
     */
    public function close(Campaign $campaign, string $status = Campaign::CANCELLED): Campaign
    {
        DB::transaction(function () use ($campaign, $status) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($locked->isClosed()) {
                throw new ApiException(__('campaigns.closed'), 'campaign_closed');
            }

            $wasDraft = $locked->status === Campaign::DRAFT;
            $refund = $wasDraft ? 0 : $locked->remainingCents();

            if ($refund > 0) {
                $this->ledger->post('campaign_refund', [
                    [$this->ledger->system(Account::CAMPAIGNS, $locked->currency), -$refund],
                    [$this->ledger->wallet($locked->owner, $locked->currency), $refund],
                ], ['reference' => $locked, 'key' => "campaign:{$locked->id}:refund", 'meta' => ['campaign' => $locked->title]]);
            }

            $locked->update(['status' => $status, 'closed_at' => now()]);

            if (! $wasDraft) {
                $locked->owner->notify(new ActivityNotification("campaign.{$status}", null, [
                    'campaignId' => $locked->id, 'campaign' => $locked->title,
                    'refund' => Money::fromCents($refund), 'currency' => $locked->currency,
                ]));
            }
        });

        return $campaign->refresh();
    }

    /**
     * A user signs up to promote a running campaign and gets their link. Joining twice returns the same link.
     */
    public function join(Campaign $campaign, User $user): CampaignPromoter
    {
        if ($user->id === $campaign->owner_id) {
            throw new ApiException(__('campaigns.own_campaign'), 'own_campaign');
        }

        // Someone who already joined always gets their link back, even while the campaign is paused.
        if ($existing = CampaignPromoter::where('campaign_id', $campaign->id)->where('user_id', $user->id)->first()) {
            return $existing;
        }

        if ($campaign->status !== Campaign::ACTIVE) {
            throw new ApiException(__('campaigns.not_active'), 'campaign_not_active');
        }

        return CampaignPromoter::firstOrCreate(
            ['campaign_id' => $campaign->id, 'user_id' => $user->id],
            ['code' => $this->newCode()],
        );
    }

    /**
     * A visit through a promoter's link. Every visit is recorded; only those that pass the filters are paid.
     *
     * @param  array{ip: ?string, userAgent: ?string, country: ?string, referrer: ?string, visitorId: ?int}  $visitor
     * @return array{visit: ?CampaignVisit, path: ?string}
     */
    public function recordVisit(string $code, array $visitor): array
    {
        $promoter = CampaignPromoter::with('campaign.owner')->where('code', strtoupper($code))->first();

        if (! $promoter) {
            return ['visit' => null, 'path' => null];
        }

        $visit = DB::transaction(function () use ($promoter, $visitor) {
            // One visit at a time per campaign, so the budget cannot be overspent by visits arriving together.
            $campaign = Campaign::whereKey($promoter->campaign_id)->lockForUpdate()->firstOrFail();
            $ipHash = hash('sha256', ($visitor['ip'] ?? '').'|'.config('app.key'));
            $reason = $this->rejectionFor($campaign, $promoter, $visitor, $ipHash);
            $reward = $reason ? 0 : $campaign->rewardCents();

            $visit = CampaignVisit::create([
                'campaign_id' => $campaign->id, 'promoter_id' => $promoter->id, 'ip_hash' => $ipHash,
                'country' => $visitor['country'] ? strtoupper(substr($visitor['country'], 0, 2)) : null,
                'referrer_host' => $visitor['referrer'] ? parse_url($visitor['referrer'], PHP_URL_HOST) : null,
                'status' => $reason ? CampaignVisit::REJECTED : CampaignVisit::VALID,
                'reject_reason' => $reason, 'reward_cents' => $reward,
            ]);

            if ($reason) {
                $promoter->increment('rejected_visits');

                return $visit;
            }

            $legs = [
                [$this->ledger->system(Account::CAMPAIGNS, $campaign->currency), -$campaign->cost_per_visit_cents],
                [$this->ledger->wallet($promoter->user, $campaign->currency), $reward],
            ];

            if ($campaign->feeCents() > 0) {
                $legs[] = [$this->ledger->system(Account::REVENUE, $campaign->currency), $campaign->feeCents()];
            }

            $this->ledger->post('campaign_earning', $legs, [
                'reference' => $visit, 'key' => "campaign-visit:{$visit->id}", 'meta' => ['campaign' => $campaign->title],
            ]);

            $promoter->increment('valid_visits');
            $promoter->increment('earned_cents', $reward);
            $campaign->increment('spent_cents', $campaign->cost_per_visit_cents);

            // Not enough left for another visit: the campaign is done and the rest goes back.
            if ($campaign->remainingCents() < $campaign->cost_per_visit_cents) {
                $this->close($campaign, Campaign::COMPLETED);
            }

            return $visit;
        });

        return ['visit' => $visit, 'path' => $promoter->campaign->targetPath()];
    }

    /**
     * Close the campaigns whose end date passed. Run by the scheduler.
     */
    public function closeEnded(): int
    {
        $ended = Campaign::whereIn('status', [Campaign::ACTIVE, Campaign::PAUSED])->whereNotNull('ends_at')->where('ends_at', '<=', now())->get();

        foreach ($ended as $campaign) {
            $this->close($campaign, Campaign::COMPLETED);
        }

        return $ended->count();
    }

    /**
     * Why a visit is not paid, or null when it is.
     *
     * @param  array{ip: ?string, userAgent: ?string, country: ?string, referrer: ?string, visitorId: ?int}  $visitor
     */
    private function rejectionFor(Campaign $campaign, CampaignPromoter $promoter, array $visitor, string $ipHash): ?string
    {
        $filters = $campaign->filters;

        return match (true) {
            ! $campaign->isRunning() => 'not_running',
            $campaign->remainingCents() < $campaign->cost_per_visit_cents => 'budget_exhausted',
            in_array($visitor['visitorId'], [$promoter->user_id, $campaign->owner_id], true) => 'own_link',
            $filters['block_bots'] && ($visitor['userAgent'] === null || $visitor['userAgent'] === '' || preg_match(self::BOT_PATTERN, $visitor['userAgent'])) => 'bot',
            $filters['allowed_countries'] !== [] && $visitor['country'] && ! in_array(strtoupper($visitor['country']), $filters['allowed_countries'], true) => 'country',
            CampaignVisit::where('campaign_id', $campaign->id)->where('ip_hash', $ipHash)->where('status', CampaignVisit::VALID)
                ->where('created_at', '>=', now()->subHours($filters['unique_window_hours']))->exists() => 'duplicate',
            CampaignVisit::where('promoter_id', $promoter->id)->where('status', CampaignVisit::VALID)
                ->where('created_at', '>=', now()->subHour())->count() >= $filters['max_visits_per_promoter_per_hour'] => 'rate_limited',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function filters(array $current, array $changes): array
    {
        $filters = [...Campaign::DEFAULT_FILTERS, ...$current, ...array_intersect_key($changes, Campaign::DEFAULT_FILTERS)];
        $filters['allowed_countries'] = array_values(array_unique(array_map('strtoupper', $filters['allowed_countries'])));
        $filters['block_bots'] = (bool) $filters['block_bots'];

        return $filters;
    }

    private function assertAmounts(string $currency, int $budget, int $cost): void
    {
        $minCost = Money::toCents(config("marketplace.campaigns.min_cost_per_visit.{$currency}") ?? throw new ApiException(__('subscriptions.currency_unavailable'), 'currency_unavailable'));
        $minBudget = Money::toCents(config("marketplace.campaigns.min_budget.{$currency}"));

        if ($cost < $minCost) {
            throw new ApiException(__('campaigns.cost_too_small', ['min' => Money::fromCents($minCost), 'currency' => $currency]), 'cost_too_small');
        }

        if ($budget < $minBudget) {
            throw new ApiException(__('payments.amount_too_small', ['min' => Money::fromCents($minBudget), 'currency' => $currency]), 'budget_too_small');
        }

        if ($budget < $cost) {
            throw new ApiException(__('campaigns.budget_below_cost'), 'budget_below_cost');
        }
    }

    private function ownOpenProject(User $owner, ?int $projectId): Project
    {
        $project = Project::where('user_id', $owner->id)->find($projectId) ?? throw new ApiException(__('subscriptions.project_not_yours'), 'project_not_yours');

        if (! $project->isOpen()) {
            throw new ApiException(__('proposals.project_not_open'), 'project_not_open');
        }

        return $project;
    }

    private function newCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
        } while (CampaignPromoter::where('code', $code)->exists());

        return $code;
    }
}
