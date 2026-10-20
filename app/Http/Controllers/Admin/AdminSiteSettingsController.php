<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SiteSettingsController;
use App\Models\Setting;
use App\Support\RuntimeConfig;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminSiteSettingsController extends Controller
{
    /** Input name => setting key, per section. */
    private const FIELDS = [
        'site' => ['nameEn' => 'site.name_en', 'nameAr' => 'site.name_ar', 'taglineEn' => 'site.tagline_en', 'taglineAr' => 'site.tagline_ar',
            'email' => 'site.email', 'phone' => 'site.phone', 'whatsapp' => 'site.whatsapp', 'addressEn' => 'site.address_en', 'addressAr' => 'site.address_ar'],
        'maintenance' => ['enabled' => 'maintenance.enabled', 'messageEn' => 'maintenance.message_en', 'messageAr' => 'maintenance.message_ar', 'endsAt' => 'maintenance.ends_at'],
        'uploads' => ['avatarMb' => 'uploads.avatar_mb', 'portfolioMb' => 'uploads.portfolio_mb', 'documentMb' => 'uploads.document_mb',
            'chatMb' => 'uploads.chat_mb', 'attachmentTypes' => 'uploads.attachment_types'],
        'security' => ['requireAdmin2fa' => 'admin.require_2fa'],
        'payments' => ['depositMethods' => 'payments.deposit_methods', 'withdrawalMethods' => 'payments.withdrawal_methods'],
    ];

    /**
     * Site settings.
     *
     * The platform's name, logo, contact details and social links, the mobile app links, maintenance mode, upload limits,
     * the staff sign-in policy and which payment methods are offered (in order).
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(): array
    {
        $out = [];
        foreach (self::FIELDS as $section => $fields) {
            foreach ($fields as $input => $key) {
                $out[$section][$input] = Setting::get($key);
            }
        }
        $out['site']['logoUrl'] = Setting::get('site.logo_url');
        $out['social'] = [...config('site.defaults')['site.social'], ...(Setting::get('site.social') ?? [])];
        $out['apps'] = [...config('site.defaults')['site.apps'], ...(Setting::get('site.apps') ?? [])];
        $out['payments']['depositMethods'] = config('payments.deposit_methods');
        $out['payments']['withdrawalMethods'] = config('payments.withdrawal_methods');

        return ['data' => [
            ...$out,
            /** What the admins can choose from. */
            'choices' => [
                'attachmentTypes' => config('site.attachment_choices'),
                'depositMethods' => config('payments.deposit_method_choices'),
                'withdrawalMethods' => config('payments.withdrawal_method_choices'),
                'social' => array_keys(config('site.defaults')['site.social']),
            ],
        ]];
    }

    /**
     * Update site settings.
     *
     * Send only the sections and fields that change. Takes effect at once, without a deploy. Turning maintenance on
     * closes the site to everyone but the staff.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request): array
    {
        $request->validate([
            'site' => ['sometimes', 'array'],
            'site.nameEn' => ['sometimes', 'string', 'max:60'],
            'site.nameAr' => ['sometimes', 'string', 'max:60'],
            'site.taglineEn' => ['sometimes', 'nullable', 'string', 'max:150'],
            'site.taglineAr' => ['sometimes', 'nullable', 'string', 'max:150'],
            'site.email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'site.phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'site.whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],
            'site.addressEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'site.addressAr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'social' => ['sometimes', 'array:'.implode(',', array_keys(config('site.defaults')['site.social']))],
            'social.*' => ['nullable', 'url:https', 'max:300'],
            'apps' => ['sometimes', 'array:android,ios'],
            'apps.*' => ['nullable', 'url:https', 'max:300'],
            'maintenance' => ['sometimes', 'array'],
            'maintenance.enabled' => ['sometimes', 'boolean'],
            'maintenance.messageEn' => ['sometimes', 'string', 'max:500'],
            'maintenance.messageAr' => ['sometimes', 'string', 'max:500'],
            'maintenance.endsAt' => ['sometimes', 'nullable', 'date'],
            'uploads' => ['sometimes', 'array'],
            'uploads.avatarMb' => ['sometimes', 'integer', 'between:1,20'],
            'uploads.portfolioMb' => ['sometimes', 'integer', 'between:1,50'],
            'uploads.documentMb' => ['sometimes', 'integer', 'between:1,50'],
            'uploads.chatMb' => ['sometimes', 'integer', 'between:1,100'],
            'uploads.attachmentTypes' => ['sometimes', 'array', 'min:1'],
            'uploads.attachmentTypes.*' => [Rule::in(config('site.attachment_choices'))],
            'security' => ['sometimes', 'array'],
            'security.requireAdmin2fa' => ['sometimes', 'boolean'],
            'payments' => ['sometimes', 'array'],
            'payments.depositMethods' => ['sometimes', 'array', 'min:1'],
            'payments.depositMethods.*' => ['distinct', Rule::in(config('payments.deposit_method_choices'))],
            'payments.withdrawalMethods' => ['sometimes', 'array', 'min:1'],
            'payments.withdrawalMethods.*' => ['distinct', Rule::in(config('payments.withdrawal_method_choices'))],
        ]);
        request()->attributes->set('audit.before', collect($this->show()['data'])->only(array_keys($request->only(['site', 'social', 'apps', 'maintenance', 'uploads', 'security', 'payments'])))->all());

        foreach (self::FIELDS as $section => $fields) {
            foreach ($fields as $input => $key) {
                if ($request->has("{$section}.{$input}")) {
                    Setting::put($key, $request->input("{$section}.{$input}"));
                }
            }
        }
        foreach (['social', 'apps'] as $section) {
            if ($request->has($section)) {
                Setting::put("site.{$section}", array_map(fn ($v) => $v ?: null, $request->input($section)));
            }
        }

        RuntimeConfig::apply();
        SiteSettingsController::forget();

        return $this->show();
    }

    /**
     * Upload the logo.
     *
     * PNG, WebP or SVG-free image up to 2 MB, used in emails and wherever the site shows the logo from settings. Send
     * `remove: true` instead to go back to the built-in logo.
     *
     * @return array{data: array{logoUrl: ?string}}
     */
    public function logo(Request $request): array
    {
        $request->validate([
            'logo' => ['required_without:remove', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove' => ['nullable', 'boolean'],
        ]);

        $old = Setting::get('site.logo_url');
        if ($old && str_contains($old, '/storage/branding/')) {
            Storage::disk('public')->delete('branding/'.basename($old));
        }

        $url = $request->boolean('remove') ? null : Storage::disk('public')->url($request->file('logo')->store('branding', 'public'));
        Setting::put('site.logo_url', $url);
        SiteSettingsController::forget();

        return ['data' => ['logoUrl' => $url]];
    }
}
