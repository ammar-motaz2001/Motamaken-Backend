<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginatedRequest;
use App\Models\Draw;
use App\Models\DrawEntry;
use App\Models\DrawWinner;
use App\Services\DrawService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Rewards', 'Points, medals, the prize draw and the referral programme.', weight: 12)]
class DrawController extends Controller
{
    public function __construct(private readonly DrawService $draws) {}

    /**
     * The current prize draw.
     *
     * The next open draw (or the last one drawn), its prizes, the number of tickets and players, and — when signed in —
     * my tickets, my chance and whether I won. `null` when there has never been a draw.
     *
     * @unauthenticated
     *
     * @return array{data: array<string, mixed>|null}
     */
    public function current(Request $request): array
    {
        $draw = $this->draws->current();

        return ['data' => $draw ? $this->present($draw, $request) : null];
    }

    /**
     * Buy tickets.
     *
     * Spends `tickets × ticketCost` points (`insufficient_points` otherwise). Refused once the draw closed (`draw_closed`)
     * or above the per-member limit (`too_many_tickets`).
     *
     * @return array{data: array<string, mixed>}
     */
    public function enter(Request $request, int $draw): array
    {
        $tickets = (int) $request->validate(['tickets' => ['required', 'integer', 'min:1', 'max:1000']])['tickets'];
        $draw = Draw::findOrFail($draw);

        $this->draws->enter($draw, $request->user(), $tickets);

        return ['data' => $this->present($draw->refresh(), $request)];
    }

    /**
     * Past draws and winners.
     *
     * @unauthenticated
     *
     * @return array<string, mixed>
     */
    public function history(PaginatedRequest $request): array
    {
        $page = Draw::with('winners.user')->where('status', Draw::DRAWN)->latest('drawn_at')->paginate($request->integer('perPage', 10));

        return [
            'data' => collect($page->items())->map(fn (Draw $draw) => [
                'id' => $draw->id,
                'title' => $draw->title(),
                'drawnAt' => $draw->drawn_at,
                'players' => DrawEntry::where('draw_id', $draw->id)->count(),
                'tickets' => (int) DrawEntry::where('draw_id', $draw->id)->sum('tickets'),
                'winners' => $draw->winners->map(fn (DrawWinner $winner) => [
                    'rank' => $winner->rank,
                    'prize' => $this->prize($winner->prize),
                    'user' => ['id' => $winner->user->id, 'fullName' => $winner->user->full_name, 'avatarUrl' => $winner->user->avatar_url],
                ])->values(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /**
     * Claim a prize.
     *
     * The winner only. `wallet` for cash prizes (credited at once); `delivery` with a shipping address for items.
     *
     * @return array{data: array<string, mixed>}
     */
    public function claim(Request $request, int $winner): array
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['wallet', 'delivery'])],
            'details' => ['required_if:method,delivery', 'nullable', 'array'],
            'details.name' => ['required_if:method,delivery', 'string', 'max:100'],
            'details.phone' => ['required_if:method,delivery', 'string', 'max:30'],
            'details.address' => ['required_if:method,delivery', 'string', 'max:300'],
            'details.city' => ['required_if:method,delivery', 'string', 'max:80'],
            'details.country' => ['required_if:method,delivery', 'string', 'max:80'],
        ]);
        $winner = DrawWinner::where('user_id', $request->user()->id)->findOrFail($winner);

        $winner = $this->draws->claim($winner, $data['method'], $data['details'] ?? null);

        return ['data' => ['id' => $winner->id, 'claimMethod' => $winner->claim_method, 'claimedAt' => $winner->claimed_at]];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Draw $draw, Request $request): array
    {
        $user = $request->user('sanctum');
        $tickets = (int) DrawEntry::where('draw_id', $draw->id)->sum('tickets');
        $mine = $user ? (int) DrawEntry::where(['draw_id' => $draw->id, 'user_id' => $user->id])->value('tickets') : 0;
        $won = $user ? $draw->winners()->where('user_id', $user->id)->first() : null;

        return [
            'id' => $draw->id,
            'title' => $draw->title(),
            /** `open` or `drawn`. */
            'status' => $draw->status,
            'isOpen' => $draw->isOpenForEntries(),
            'ticketCost' => $draw->ticket_cost,
            'maxTicketsPerUser' => $draw->max_tickets_per_user,
            'startsAt' => $draw->starts_at,
            'drawAt' => $draw->draw_at,
            'drawnAt' => $draw->drawn_at,
            'prizes' => collect($draw->prizes)->sortBy('rank')->map(fn ($prize) => $this->prize($prize))->values(),
            'totals' => ['tickets' => $tickets, 'players' => DrawEntry::where('draw_id', $draw->id)->count()],
            'me' => $user ? [
                'points' => $user->points,
                'tickets' => $mine,
                /** Percent chance of winning at least the first prize drawn, given the tickets so far. */
                'chance' => $tickets ? round($mine / $tickets * 100, 1) : 0,
                'won' => $won ? ['id' => $won->id, 'rank' => $won->rank, 'prize' => $this->prize($won->prize), 'claimedAt' => $won->claimed_at, 'claimMethod' => $won->claim_method] : null,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $prize
     * @return array<string, mixed>
     */
    private function prize(array $prize): array
    {
        return [
            'rank' => $prize['rank'],
            'name' => app()->getLocale() === 'ar' ? ($prize['name_ar'] ?? $prize['name_en']) : $prize['name_en'],
            /** `cash` (paid to the wallet) or `item` (shipped). */
            'kind' => $prize['kind'] ?? 'item',
            'value' => $prize['value'] ?? null,
            'currency' => $prize['currency'] ?? null,
        ];
    }
}
