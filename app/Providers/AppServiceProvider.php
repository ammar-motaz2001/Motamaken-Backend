<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\WhatsAppSender;
use App\Models\IntegrationLog;
use App\Models\MailLog;
use App\Models\PortfolioItem;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Review;
use App\Observers\AiScreeningObserver;
use App\Services\LogWhatsAppSender;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\MoyasarGateway;
use App\Support\RuntimeConfig;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Address;
use Throwable;

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
        foreach ([Project::class, Proposal::class, Review::class, Profile::class, PortfolioItem::class] as $model) {
            $model::observe(AiScreeningObserver::class);
        }

        // Values the admins changed in the panel (prices, point rules, SMTP...), for requests and for every queued job.
        RuntimeConfig::apply();
        Queue::before(fn () => RuntimeConfig::apply());

        // Email log: addresses and subjects, never bodies.
        Event::listen(MessageSent::class, function (MessageSent $event) {
            MailLog::create([
                'to' => implode(', ', array_map(fn (Address $a) => $a->getAddress(), $event->message->getTo())),
                'subject' => mb_substr((string) $event->message->getSubject(), 0, 250),
                'notification' => isset($event->data['__laravel_notification']) ? class_basename($event->data['__laravel_notification']) : null,
                'status' => 'sent',
            ]);
        });
        Queue::failing(function (JobFailed $event) {
            try {
                $command = unserialize($event->job->payload()['data']['command'] ?? '');
            } catch (Throwable) {
                return;
            }
            if (! $command instanceof SendQueuedNotifications || ! in_array('mail', (array) $command->channels, true)) {
                return;
            }
            foreach ($command->notifiables as $notifiable) {
                MailLog::create([
                    'to' => (string) ($notifiable->email ?? $notifiable->routes['mail'] ?? '?'),
                    'notification' => class_basename($command->notification),
                    'status' => 'failed',
                    'error' => mb_substr($event->exception->getMessage(), 0, 1000),
                ]);
            }
            IntegrationLog::record('mail', $event->exception->getMessage(), ['notification' => class_basename($command->notification)]);
        });

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
