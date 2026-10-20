<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use App\Models\MailLog;
use App\Services\Ai\Ollama;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminIntegrationController extends Controller
{
    /**
     * Integrations status.
     *
     * Which outside services are set up and working: payments, email, reCAPTCHA, the job queue, file storage and the AI
     * services. Keys are never shown, only whether they are present and which mode they run in.
     *
     * @return array{data: list<array{service: string, status: string, details: array<string, mixed>}>, environment: string}
     */
    public function status(): array
    {
        $secret = (string) config('payments.moyasar.secret_key');
        $lastError = fn (string $service) => IntegrationLog::where('service', $service)->where('level', 'error')->latest('id')->first()?->created_at?->toIso8601String();

        return [
            'environment' => app()->environment(),
            'data' => [
                [
                    'service' => 'payments',
                    'status' => config('payments.driver') === 'moyasar' ? ($secret ? 'ok' : 'error') : 'warning',
                    'details' => [
                        'driver' => config('payments.driver'),
                        /** `test` or `live`, from the key type. */
                        'mode' => str_starts_with($secret, 'sk_live') ? 'live' : ($secret ? 'test' : null),
                        'secretKey' => filled($secret),
                        'webhookSecret' => filled(config('payments.moyasar.webhook_secret')),
                        'webhookUrl' => url('/api/webhooks/moyasar'),
                        'lastError' => $lastError('payments'),
                    ],
                ],
                [
                    'service' => 'mail',
                    'status' => in_array(config('mail.default'), ['log', 'array'], true) ? 'warning'
                        : (MailLog::where('status', 'failed')->where('created_at', '>=', now()->subDay())->exists() ? 'error' : 'ok'),
                    'details' => [
                        'mailer' => config('mail.default'),
                        'host' => config('mail.mailers.smtp.host'),
                        'from' => config('mail.from.address'),
                        'lastSent' => MailLog::where('status', 'sent')->latest('id')->value('created_at'),
                        'lastError' => $lastError('mail'),
                    ],
                ],
                [
                    'service' => 'recaptcha',
                    'status' => filled(config('services.recaptcha.secret_key')) ? 'ok' : 'warning',
                    'details' => ['secretKey' => filled(config('services.recaptcha.secret_key')), 'lastError' => $lastError('recaptcha')],
                ],
                $this->queue(),
                $this->storage(),
                $this->ai(),
            ],
        ];
    }

    /**
     * @return array{service: string, status: string, details: array<string, mixed>}
     */
    private function queue(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        try {
            $pending = config('queue.default') === 'redis' ? (int) Redis::connection(config('queue.connections.redis.connection', 'default'))->llen('queues:default')
                : (Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0);
        } catch (Throwable) {
            $pending = null;
        }

        return ['service' => 'queue', 'status' => $pending === null ? 'error' : ($failed > 0 ? 'warning' : 'ok'),
            'details' => ['driver' => config('queue.default'), 'pending' => $pending, 'failed' => $failed]];
    }

    /**
     * @return array{service: string, status: string, details: array<string, mixed>}
     */
    private function storage(): array
    {
        try {
            Storage::disk('local')->put('.health', (string) now());
            $ok = Storage::disk('local')->get('.health') !== null;
        } catch (Throwable) {
            $ok = false;
        }

        return ['service' => 'storage', 'status' => $ok ? 'ok' : 'error', 'details' => ['disk' => config('filesystems.default'), 'writable' => $ok]];
    }

    /**
     * @return array{service: string, status: string, details: array<string, mixed>}
     */
    private function ai(): array
    {
        $enabled = (bool) config('ai.enabled');
        $reachable = $enabled ? app(Ollama::class)->ping() : null;
        $vector = Schema::hasTable('embeddings');

        return ['service' => 'ai', 'status' => ! $enabled ? 'off' : ($reachable ? 'ok' : 'error'), 'details' => [
            'enabled' => $enabled, 'url' => $enabled ? config('ai.url') : null, 'model' => config('ai.model'), 'embeddingModel' => config('ai.embedding_model'),
            'reachable' => $reachable, 'pgvector' => $vector, 'embeddings' => $vector ? DB::table('embeddings')->count() : 0, 'lastError' => IntegrationLog::where('service', 'ai')->latest('id')->value('created_at'),
        ]];
    }

    /**
     * Integration errors.
     *
     * Problems talking to outside services (payments, email, reCAPTCHA, AI), newest first. No card data or message
     * content is ever logged.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function logs(Request $request): array
    {
        $request->validate(['service' => ['nullable', 'string', 'max:30'], 'level' => ['nullable', 'in:error,warning,info']]);

        $page = IntegrationLog::query()
            ->when($request->input('service'), fn ($q, $s) => $q->where('service', $s))
            ->when($request->input('level'), fn ($q, $l) => $q->where('level', $l))
            ->latest('id')->paginate($request->integer('perPage', 30));

        return Paged::of($page, fn (IntegrationLog $l) => [
            'id' => $l->id, 'service' => $l->service, 'level' => $l->level, 'message' => $l->message, 'context' => $l->context, 'createdAt' => $l->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Failed background jobs.
     *
     * Emails, announcements and other work that failed after its retries.
     *
     * @return array{data: list<array{id: int, uuid: string, job: string, queue: string, error: string, failedAt: string}>}
     */
    public function failedJobs(): array
    {
        return ['data' => DB::table('failed_jobs')->latest('id')->limit(50)->get()->map(fn ($job) => [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'job' => class_basename(json_decode($job->payload, true)['displayName'] ?? '?'),
            'queue' => $job->queue,
            'error' => mb_substr(strtok((string) $job->exception, "\n"), 0, 300),
            'failedAt' => (string) $job->failed_at,
        ])->all()];
    }

    /**
     * Retry a failed job.
     *
     * Puts it back on the queue; `all` as the id retries every failed job.
     *
     * @return array{success: bool}
     */
    public function retry(string $uuid): array
    {
        Artisan::call('queue:retry', ['id' => [$uuid === 'all' ? 'all' : $uuid]]);

        return ['success' => true];
    }
}
