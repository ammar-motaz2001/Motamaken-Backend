<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginatedRequest;
use App\Http\Resources\PromotionResource;
use App\Models\Promotion;
use App\Services\PromotionService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Subscriptions & promotions', 'Monthly plans, and paid placement on the homepage ("Premium Services").', weight: 11)]
class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $promotions) {}

    /**
     * Promotion prices.
     *
     * The price per day of each placement in each currency, after the user's plan discount.
     *
     * @return array{maxDays: int, data: list<array{kind: string, currency: string, perDay: float}>}
     */
    public function prices(Request $request): array
    {
        $rows = [];

        foreach (array_keys(config('marketplace.promotions.prices')) as $kind) {
            foreach (array_keys(config("marketplace.promotions.prices.{$kind}")) as $currency) {
                $rows[] = ['kind' => $kind, 'currency' => $currency, 'perDay' => $this->promotions->priceCents($request->user(), $kind, $currency, 1) / 100];
            }
        }

        return ['maxDays' => config('marketplace.promotions.max_days'), 'data' => $rows];
    }

    /**
     * Buy a promotion.
     *
     * Pays from the wallet and puts the user's profile (`kind` `profile`) or one of their open projects (`kind` `project`)
     * in the matching homepage slider for `days`. Buying again while it is live adds days to the end.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([Promotion::PROFILE, Promotion::PROJECT])],
            /** Required for `project`: which of your projects. */
            'projectId' => ['required_if:kind,project', 'nullable', 'integer'],
            'days' => ['required', 'integer', 'min:1', 'max:'.config('marketplace.promotions.max_days')],
            'currency' => ['required', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
        ]);

        $promotion = $this->promotions->buy($request->user(), $data['kind'], $data['projectId'] ?? null, $data['days'], $data['currency']);

        return (new PromotionResource($promotion->load('project')))->response()->setStatusCode(201);
    }

    /**
     * My promotions.
     */
    public function index(PaginatedRequest $request): AnonymousResourceCollection
    {
        return PromotionResource::collection(
            Promotion::with('project')->where('user_id', $request->user()->id)->latest()->latest('id')->paginate($request->integer('perPage', 15)),
        );
    }
}
