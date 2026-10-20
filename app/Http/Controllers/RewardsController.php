<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginatedRequest;
use App\Models\PointTransaction;
use App\Services\RewardsService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Rewards', 'Points, medals, the prize draw and the referral programme.', weight: 12)]
class RewardsController extends Controller
{
    public function __construct(private readonly RewardsService $rewards) {}

    /**
     * My points.
     *
     * The balance, how to earn more (`done` for one-time actions already rewarded) and the history, newest first.
     *
     * @return array<string, mixed>
     */
    public function points(PaginatedRequest $request): array
    {
        $user = $request->user();
        $page = PointTransaction::where('user_id', $user->id)->latest('id')->paginate($request->integer('perPage', 15));
        $done = PointTransaction::where('user_id', $user->id)->whereNotNull('key')->pluck('key')->all();

        return [
            'balance' => $user->points,
            'earn' => collect(config('engagement.points'))->map(fn ($rule, $reason) => [
                'reason' => $reason,
                'label' => __("rewards.reasons.{$reason}"),
                'points' => $reason === 'welcome' ? (int) config('auth_flow.welcome_points') : $rule['amount'],
                'once' => $rule['once'],
                'done' => $rule['once'] && in_array($reason, $done, true),
            ])->values(),
            'data' => collect($page->items())->map(fn (PointTransaction $row) => [
                'id' => $row->id,
                'amount' => $row->amount,
                'reason' => $row->reason,
                'label' => $row->reason === 'admin_adjustment' ? ($row->meta['note'] ?? __('rewards.reasons.admin_adjustment')) : __("rewards.reasons.{$row->reason}"),
                'createdAt' => $row->created_at,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /**
     * My medals.
     *
     * Every medal with progress (`current` of `target`) and when it was earned.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function medals(Request $request): array
    {
        $this->rewards->syncMedals($request->user());

        return ['data' => $this->rewards->medals($request->user())];
    }
}
