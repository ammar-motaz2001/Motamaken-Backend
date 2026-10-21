<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Draw;
use App\Models\DrawEntry;
use App\Models\DrawWinner;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Payments\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * "Gifts — prize draw": members spend points on tickets; when the draw closes, each prize goes to a different member,
 * picked at random with chances proportional to their tickets.
 */
class DrawService
{
    public function __construct(
        private readonly RewardsService $rewards,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * The draw to show on the gifts page: the next open one, or else the last one drawn.
     */
    public function current(): ?Draw
    {
        return Draw::where('status', Draw::OPEN)->where('draw_at', '>', now())->orderBy('draw_at')->first()
            ?? Draw::where('status', Draw::DRAWN)->latest('drawn_at')->first();
    }

    /**
     * Buy tickets for an open draw with points. Tickets add up on one entry per member, capped by the draw's per-member
     * limit; the points are spent in the same transaction.
     */
    public function enter(Draw $draw, User $user, int $tickets): DrawEntry
    {
        if (! $draw->isOpenForEntries()) {
            throw new ApiException(__('rewards.draw_closed'), 'draw_closed');
        }

        return DB::transaction(function () use ($draw, $user, $tickets) {
            $entry = DrawEntry::where(['draw_id' => $draw->id, 'user_id' => $user->id])->lockForUpdate()->first()
                ?? new DrawEntry(['draw_id' => $draw->id, 'user_id' => $user->id, 'tickets' => 0]);

            if ($draw->max_tickets_per_user && $entry->tickets + $tickets > $draw->max_tickets_per_user) {
                throw new ApiException(__('rewards.too_many_tickets', ['max' => $draw->max_tickets_per_user]), 'too_many_tickets');
            }

            $this->rewards->spend($user, $tickets * $draw->ticket_cost, 'draw_tickets', ['drawId' => $draw->id, 'tickets' => $tickets]);
            $entry->tickets += $tickets;
            $entry->save();

            return $entry;
        });
    }

    /**
     * Pick the winners. Runs once; a draw without entries is simply closed.
     *
     * @return list<DrawWinner>
     */
    public function run(Draw $draw): array
    {
        return DB::transaction(function () use ($draw) {
            $draw = Draw::whereKey($draw->id)->lockForUpdate()->firstOrFail();
            if ($draw->status !== Draw::OPEN) {
                throw new ApiException(__('rewards.draw_not_open'), 'draw_not_open');
            }

            $pool = DrawEntry::where('draw_id', $draw->id)->where('tickets', '>', 0)->pluck('tickets', 'user_id')->all();
            $winners = [];

            foreach (collect($draw->prizes)->sortBy('rank') as $prize) {
                if (! $pool) {
                    break;
                }
                $userId = $this->pick($pool);
                unset($pool[$userId]);

                $winners[] = $winner = DrawWinner::create(['draw_id' => $draw->id, 'user_id' => $userId, 'rank' => $prize['rank'], 'prize' => $prize]);
                $winner->user->notify(new ActivityNotification('draw.won', null, [
                    'drawId' => $draw->id, 'winnerId' => $winner->id, 'rank' => $prize['rank'],
                    'prize' => $prize[app()->getLocale() === 'ar' ? 'name_ar' : 'name_en'] ?? $prize['name_en'],
                ]));
            }

            $draw->update(['status' => Draw::DRAWN, 'drawn_at' => now()]);

            return $winners;
        });
    }

    /**
     * The winner chooses how to receive the prize: cash prizes go to the wallet, items are shipped to an address.
     *
     * @param  array<string, string>|null  $details  For `delivery`: name, phone, address, city, country.
     */
    public function claim(DrawWinner $winner, string $method, ?array $details): DrawWinner
    {
        return DB::transaction(function () use ($winner, $method, $details) {
            $winner = DrawWinner::with('user')->whereKey($winner->id)->lockForUpdate()->firstOrFail();
            if ($winner->claimed_at) {
                throw new ApiException(__('rewards.already_claimed'), 'already_claimed');
            }

            $prize = $winner->prize;
            if ($method === 'wallet') {
                if (($prize['kind'] ?? 'item') !== 'cash') {
                    throw new ApiException(__('rewards.not_cash'), 'not_cash');
                }
                $cents = Money::toCents($prize['value']);
                $this->ledger->post('draw_prize', [
                    [$this->ledger->system(Account::MARKETING, $prize['currency']), -$cents],
                    [$this->ledger->wallet($winner->user, $prize['currency']), $cents],
                ], ['reference' => $winner, 'key' => "draw_winner:{$winner->id}"]);
            }

            $winner->update(['claim_method' => $method, 'claim_details' => $details ? json_encode($details) : null, 'claimed_at' => now()]);

            return $winner;
        });
    }

    /**
     * Pick one user at random, each ticket being one chance.
     *
     * @param  array<int, int>  $pool  user id => tickets
     */
    private function pick(array $pool): int
    {
        $roll = random_int(1, array_sum($pool));
        foreach ($pool as $userId => $tickets) {
            $roll -= $tickets;
            if ($roll <= 0) {
                return $userId;
            }
        }

        return array_key_last($pool);
    }
}
