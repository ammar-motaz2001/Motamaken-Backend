<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use App\Models\MailLog;
use App\Models\Setting;
use App\Support\Paged;
use App\Support\RuntimeConfig;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminMailController extends Controller
{
    /**
     * Email settings.
     *
     * The mail server in use. The password is never returned, only whether one is set.
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(): array
    {
        return ['data' => [
            /** Which transport sends email: `smtp`, `log` (written to the server log only)... */
            'mailer' => config('mail.default'),
            /** The values below come from the panel (true) or from the server environment (false). */
            'overridden' => Setting::get('mail.enabled', false) === true,
            'host' => config('mail.mailers.smtp.host'),
            'port' => (int) config('mail.mailers.smtp.port'),
            'username' => config('mail.mailers.smtp.username'),
            'scheme' => config('mail.mailers.smtp.scheme'),
            'hasPassword' => filled(config('mail.mailers.smtp.password')),
            'fromAddress' => config('mail.from.address'),
            'fromName' => config('mail.from.name'),
            'last24h' => [
                'sent' => MailLog::where('status', 'sent')->where('created_at', '>=', now()->subDay())->count(),
                'failed' => MailLog::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            ],
        ]];
    }

    /**
     * Update email settings.
     *
     * Saves an SMTP server to use instead of the server's own settings (`enabled: false` goes back to them). The
     * password is stored encrypted; leave it out to keep the current one.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request): array
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:enabled,true', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'mailPassword' => ['nullable', 'string', 'max:255'],
            /** `smtp` or `smtps` (SSL on connect, usually port 465). */
            'scheme' => ['nullable', Rule::in(['smtp', 'smtps'])],
            'fromAddress' => ['required_if:enabled,true', 'nullable', 'email', 'max:255'],
            'fromName' => ['nullable', 'string', 'max:100'],
        ]);
        request()->attributes->set('audit.before', collect($this->show()['data'])->except(['last24h'])->all());

        if (! $data['enabled']) {
            foreach (['enabled', 'host', 'port', 'username', 'password', 'scheme', 'from_address', 'from_name'] as $key) {
                Setting::put("mail.{$key}", null);
            }
        } else {
            Setting::put('mail.enabled', true);
            foreach (['host' => 'host', 'port' => 'port', 'username' => 'username', 'scheme' => 'scheme', 'fromAddress' => 'from_address', 'fromName' => 'from_name'] as $input => $key) {
                Setting::put("mail.{$key}", $data[$input] ?? null);
            }
            if (filled($data['mailPassword'] ?? null)) {
                Setting::put('mail.password', Crypt::encryptString($data['mailPassword']));
            }
        }

        RuntimeConfig::apply();

        return $this->show();
    }

    /**
     * Send a test email.
     *
     * To the address given (yours by default), right away, so a wrong server shows its error here.
     *
     * @return array{success: bool, error: ?string}
     */
    public function test(Request $request): array
    {
        $data = $request->validate(['to' => ['nullable', 'email']]);
        $to = $data['to'] ?? $request->user()->email;
        abort_unless($to, 422, __('admin.no_email'));

        try {
            Mail::raw(__('admin.test_email_body'), fn ($message) => $message->to($to)->subject(__('admin.test_email_subject')));

            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            MailLog::create(['to' => $to, 'subject' => __('admin.test_email_subject'), 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            IntegrationLog::record('mail', $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Email log.
     *
     * Every email sent or failed: address, subject and error. Never the content.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function logs(Request $request): array
    {
        $request->validate(['status' => ['nullable', Rule::in(['sent', 'failed'])], 'q' => ['nullable', 'string', 'max:100']]);

        $page = MailLog::query()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('q'), fn ($q, $term) => $q->where('to', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('id')->paginate($request->integer('perPage', 30));

        return Paged::of($page, fn (MailLog $l) => [
            'id' => $l->id, 'to' => $l->to, 'subject' => $l->subject, 'notification' => $l->notification,
            'status' => $l->status, 'error' => $l->error, 'createdAt' => $l->created_at?->toIso8601String(),
        ]);
    }
}
