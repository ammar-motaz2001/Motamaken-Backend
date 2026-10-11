<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Draw;
use App\Services\DrawService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Admins only.', weight: 20)]
class AdminDrawController extends Controller
{
    public function __construct(private readonly DrawService $draws) {}

    /**
     * List draws.
     */
    public function index(): array
    {
        return ['data' => Draw::withCount('entries')->withSum('entries', 'tickets')->latest('draw_at')->limit(50)->get()];
    }

    /**
     * Create a draw.
     *
     * `prizes`: `[{rank, nameEn, nameAr, kind: cash|item, value, currency}]`.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'titleEn' => ['required', 'string', 'max:120'],
            'titleAr' => ['required', 'string', 'max:120'],
            'ticketCost' => ['required', 'integer', 'min:1'],
            'maxTicketsPerUser' => ['nullable', 'integer', 'min:1'],
            'startsAt' => ['required', 'date'],
            'drawAt' => ['required', 'date', 'after:startsAt', 'after:now'],
            'prizes' => ['required', 'array', 'min:1', 'max:10'],
            'prizes.*.rank' => ['required', 'integer', 'min:1', 'distinct'],
            'prizes.*.nameEn' => ['required', 'string', 'max:120'],
            'prizes.*.nameAr' => ['required', 'string', 'max:120'],
            'prizes.*.kind' => ['required', Rule::in(['cash', 'item'])],
            'prizes.*.value' => ['required', 'numeric', 'min:0'],
            'prizes.*.currency' => ['required', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
        ]);

        $draw = Draw::create([
            'title_en' => $data['titleEn'], 'title_ar' => $data['titleAr'], 'ticket_cost' => $data['ticketCost'],
            'max_tickets_per_user' => $data['maxTicketsPerUser'] ?? null, 'starts_at' => $data['startsAt'], 'draw_at' => $data['drawAt'],
            'prizes' => collect($data['prizes'])->map(fn ($prize) => [
                'rank' => $prize['rank'], 'name_en' => $prize['nameEn'], 'name_ar' => $prize['nameAr'],
                'kind' => $prize['kind'], 'value' => (float) $prize['value'], 'currency' => $prize['currency'],
            ])->sortBy('rank')->values()->all(),
        ]);

        return response()->json(['data' => $draw], 201);
    }

    /**
     * Run a draw now.
     *
     * Picks the winners even before `drawAt` (normally the `draws:run` schedule does it when the time comes).
     */
    public function run(int $draw): array
    {
        $winners = $this->draws->run(Draw::findOrFail($draw));

        return ['winners' => collect($winners)->map(fn ($winner) => ['rank' => $winner->rank, 'userId' => $winner->user_id])];
    }
}
