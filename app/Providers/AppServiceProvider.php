<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\WhatsAppSender;
use App\Services\LogWhatsAppSender;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\MoyasarGateway;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsAppSender::class, LogWhatsAppSender::class);
        $this->app->bind(PaymentGateway::class, config('payments.driver') === 'moyasar' ? MoyasarGateway::class : FakeGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The docs routes are registered in routes/web.php so the spec can carry our examples.
        Scramble::configure()->expose(false);

        // Posting projects, sending offers and saving the profile.
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(30)
            ->by('user:'.($request->user()?->id ?? $request->ip()))
            ->response(fn () => response()->json(['message' => __('auth_flow.too_many_requests')], 429)));

        // Moving money: deposits, withdrawals, funding and approving milestones.
        RateLimiter::for('money', fn (Request $request) => Limit::perMinute(20)
            ->by('user:'.($request->user()?->id ?? $request->ip()))
            ->response(fn () => response()->json(['message' => __('auth_flow.too_many_requests')], 429)));

        // The contact form is open to visitors: a few messages per hour per address.
        RateLimiter::for('contact', fn (Request $request) => Limit::perHour(5)->by('ip:'.$request->ip())
            ->response(fn () => response()->json(['message' => __('auth_flow.too_many_requests')], 429)));

        // Visits through campaign links, per visitor: generous for real people, a wall for scripts.
        RateLimiter::for('campaign-visits', fn (Request $request) => Limit::perMinute(30)->by('ip:'.$request->ip()));

        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(60)
            ->by('user:'.($request->user()?->id ?? $request->ip()))
            ->response(fn () => response()->json(['message' => __('auth_flow.too_many_requests')], 429)));

        RateLimiter::for('auth', function (Request $request) {
            $identifier = mb_strtolower((string) ($request->input('identifier') ?? $request->input('target')
                ?? $request->input('email') ?? $request->input('phone')));
            $tooMany = fn () => response()->json(['message' => __('auth_flow.too_many_requests')], 429);

            return [
                Limit::perMinute(30)->by('ip:'.$request->ip())->response($tooMany),
                Limit::perMinute(6)->by($request->path().'|'.$identifier)->response($tooMany),
            ];
        });
    }
}
