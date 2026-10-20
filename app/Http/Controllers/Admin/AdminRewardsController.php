<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PointTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\RewardsService;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminRewardsController extends Controller
{
    public function __construct(private readonly RewardsService $rewards) {}

    /**
     * Point rules.
     *
     * What each action is worth and whether it is rewarded only once per account.
     *
     * @return array{data: list<array{reason: string, label: string, amount: int, once: bool}>}
     */
    public function rules(): array
    {
        return ['data' => collect(config('engagement.points'))->map(fn ($rule, $reason) => [
            'reason' => $reason,
            'label' => __("rewards.reasons.{$reason}"),
            'amount' => (int) $rule['amount'],
            'once' => (bool) $rule['once'],
        ])->values()->all()];
    }

    /**
     * Update point rules.
     *
     * Send the amounts to change, keyed by reason. Applies to future actions; past points stay.
     *
     * @return array{data: list<array{reason: string, label: string, amount: int, once: bool}>}
     */
    public function updateRules(Request $request): array
    {
        $reasons = array_keys(config('engagement.points'));
        $data = $request->validate([
            'rules' => ['required', 'array:'.implode(',', $reasons)],
            'rules.*.amount' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        request()->attributes->set('audit.before', collect(config('engagement.points'))->map(fn ($r) => $r['amount'])->all());

        $rules = config('engagement.points');
        foreach ($data['rules'] as $reason => $rule) {
            $rules[$reason]['amount'] = (int) $rule['amount'];
        }

        Setting::put('points.rules', $rules);
        config(['engagement.points' => $rules]);

        return $this->rules();
    }

    /**
     * Point movements.
     *
     * Every point earned or spent, newest first.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function transactions(Request $request): array
    {
        $request->validate([
            'userId' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:40'],
            /** `earned` or `spent`. */
            'direction' => ['nullable', 'in:earned,spent'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($request)->with('user')->latest('id')->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (PointTransaction $t) => [
            'id' => $t->id, 'user' => Paged::user($t->user), 'amount' => $t->amount, 'reason' => $t->reason,
            'label' => $t->reason === 'admin_adjustment' ? ($t->meta['note'] ?? '') : __("rewards.reasons.{$t->reason}"),
            'manual' => $t->created_by !== null, 'createdAt' => $t->created_at?->toIso8601String(),
        ]);
    }

    /**
     * @return Builder<PointTransaction>
     */
    public function filtered(Request $request): Builder
    {
        return PointTransaction::query()
            ->when($request->input('userId'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->input('reason'), fn ($q, $reason) => $q->where('reason', $reason))
            ->when($request->input('direction') === 'earned', fn ($q) => $q->where('amount', '>', 0))
            ->when($request->input('direction') === 'spent', fn ($q) => $q->where('amount', '<', 0));
    }

    /**
     * Points report.
     *
     * Points given and spent per reason over a period, the balance held by all users and the top earners.
     *
     * @return array{data: array{days: int, outstanding: int, byReason: list<array{reason: string, label: string, earned: int, spent: int, count: int}>, topUsers: list<array<string, mixed>>}}
     */
    public function report(Request $request): array
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $days = $request->integer('days', 30);
        $since = now()->subDays($days);

        return ['data' => [
            'days' => $days,
            /** Points sitting in user balances right now. */
            'outstanding' => (int) User::sum('points'),
            'byReason' => PointTransaction::where('created_at', '>=', $since)
                ->selectRaw('reason, sum(case when amount > 0 then amount else 0 end) as earned, sum(case when amount < 0 then -amount else 0 end) as spent, count(*) as total')
                ->groupBy('reason')->orderByDesc('earned')->get()
                ->map(fn ($row) => ['reason' => $row->reason, 'label' => __("rewards.reasons.{$row->reason}"), 'earned' => (int) $row->earned, 'spent' => (int) $row->spent, 'count' => (int) $row->total])->all(),
            'topUsers' => User::orderByDesc('points')->limit(10)->get()->map(fn (User $u) => [...Paged::user($u), 'points' => $u->points])->all(),
        ]];
    }

    /**
     * Grant or take points.
     *
     * Positive `amount` gives points, negative takes them (never below zero). The note shows in the user's history.
     *
     * @return array{data: array{balance: int}}
     */
    public function adjust(Request $request, int $user): array
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'note' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        $user = User::findOrFail($user);
        $this->rewards->adjust($user, (int) $data['amount'], $data['note'], $request->user());

        return ['data' => ['balance' => (int) $user->refresh()->points]];
    }
}
