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
     * Promotion packages.
     *
     * The packages offered in the "Feature your project / profile" popups, in one currency, after the plan discount.
     *
     * @return array{points: int, data: list<array{code: string, days: int, reach: array{min: int, max: int}, price: float, currency: string}>}
     */
    public function packages(Request $request): array
    {
        $currency = $request->validate([
            'currency' => ['nullable', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
        ])['currency'] ?? $request->user()->currency;

        return ['points' => (int) config('marketplace.promotions.points'), 'data' => $this->promotions->packages($request->user(), $currency)];
    }

    /**
     * Buy a promotion.
     *
     * Pays from the wallet and puts the user's profile (`kind` `profile`) or one of their open projects (`kind` `project`)
     * in the matching homepage slider for `days`, or for a `package` from `GET /promotions/packages`. Buying again
     * while it is live adds days to the end. Every purchase grants points (`pointsAwarded`).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([Promotion::PROFILE, Promotion::PROJECT])],
            /** Required for `project`: which of your projects. */
            'projectId' => ['required_if:kind,project', 'nullable', 'integer'],
            /** A package code (`starter`, `boost`, `pro`); replaces `days`. */
            'package' => ['required_without:days', 'nullable', Rule::in(array_column(config('marketplace.promotions.packages'), 'code'))],
            'days' => ['required_without:package', 'nullable', 'integer', 'min:1', 'max:'.config('marketplace.promotions.max_days')],
            'currency' => ['required', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
        ]);

        $promotion = $this->promotions->buy(
            $request->user(), $data['kind'], $data['projectId'] ?? null, (int) ($data['days'] ?? 0), $data['currency'], $data['package'] ?? null,
        );

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
