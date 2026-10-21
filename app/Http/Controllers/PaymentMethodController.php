<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Http\Requests\PaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\RewardsService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

#[Group('Wallet & payments', 'The wallet: balances, history, deposits and withdrawals.', weight: 6)]
class PaymentMethodController extends Controller
{
    private const BRANDS = [
        'mada' => '/^(440647|440795|446404|457865|968208|588845|636120|968201|968205|968206|968207|968209|968211)/',
        'amex' => '/^3[47]/',
        'visa' => '/^4/',
        'mastercard' => '/^(5[1-5]|2[2-7])/',
    ];

    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Saved payment methods.
     *
     * The preferred one first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaymentMethodResource::collection(
            PaymentMethod::where('user_id', $request->user()->id)->orderByDesc('is_default')->latest('id')->get(),
        );
    }

    /**
     * Save a payment method.
     *
     * Cards are handed to the payment provider, which keeps them; only the brand, the last 4 digits and the expiry
     * are stored and shown back.
     */
    public function store(PaymentMethodRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $attributes = match (true) {
            $data['type'] === PaymentMethod::CARD && filled($data['token'] ?? null) => $this->fromToken($user, $data['token']),
            $data['type'] === PaymentMethod::CARD => [
                'brand' => $this->brand($request->digits()),
                'last4' => substr($request->digits(), -4),
                'exp_month' => $data['expMonth'],
                'exp_year' => $data['expYear'],
                'holder_name' => trim($data['holderName']),
                'gateway_token' => $this->gateway->saveCard($user, [
                    'number' => $request->digits(), 'expMonth' => $data['expMonth'], 'expYear' => $data['expYear'],
                    'cvc' => $data['cvc'], 'holderName' => $data['holderName'],
                ]),
            ],
            default => ['email' => strtolower($data['email']), 'gateway_token' => 'paypal:'.strtolower($data['email'])],
        };

        $method = DB::transaction(function () use ($user, $data, $attributes) {
            $first = ! PaymentMethod::where('user_id', $user->id)->exists();
            if (! $first && ($data['makeDefault'] ?? false)) {
                PaymentMethod::where('user_id', $user->id)->update(['is_default' => false]);
            }

            return PaymentMethod::create([
                ...$attributes, 'user_id' => $user->id, 'type' => $data['type'], 'is_default' => $first || ($data['makeDefault'] ?? false),
            ]);
        });

        app(RewardsService::class)->award($user, 'payment_method_added');
        app(RewardsService::class)->syncMedals($user);

        return (new PaymentMethodResource($method))->response()->setStatusCode(201);
    }

    /**
     * Set the preferred method.
     */
    public function makeDefault(Request $request, int $method): PaymentMethodResource
    {
        $method = PaymentMethod::where('user_id', $request->user()->id)->findOrFail($method);

        DB::transaction(function () use ($method) {
            PaymentMethod::where('user_id', $method->user_id)->update(['is_default' => false]);
            $method->update(['is_default' => true]);
        });

        return new PaymentMethodResource($method);
    }

    /**
     * Remove a payment method.
     *
     * When the preferred method is removed, the newest remaining one becomes preferred.
     *
     * @return array{success: bool}
     */
    public function destroy(Request $request, int $method): array
    {
        $method = PaymentMethod::where('user_id', $request->user()->id)->findOrFail($method);

        DB::transaction(function () use ($method) {
            $method->delete();
            if ($method->is_default) {
                PaymentMethod::where('user_id', $method->user_id)->latest('id')->first()?->update(['is_default' => true]);
            }
        });

        return ['success' => true];
    }

    /**
     * Asks the gateway for the card behind a browser token and maps it to our columns.
     *
     * @return array<string, mixed>
     */
    private function fromToken(User $user, string $token): array
    {
        $card = $this->gateway->cardFromToken($user, $token);

        return [
            'brand' => $card['brand'], 'last4' => $card['last4'], 'exp_month' => $card['expMonth'], 'exp_year' => $card['expYear'],
            'holder_name' => $card['holderName'], 'gateway_token' => $card['token'],
        ];
    }

    /**
     * Guesses the card brand from the number's prefix; `other` when nothing matches.
     */
    private function brand(string $digits): string
    {
        foreach (self::BRANDS as $brand => $pattern) {
            if (preg_match($pattern, $digits)) {
                return $brand;
            }
        }

        return 'other';
    }
}
