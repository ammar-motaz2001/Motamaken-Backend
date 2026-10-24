<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\MoyasarMode;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Cache;

#[Group('Site pages', 'The information pages of the footer and the contact form.', weight: 14)]
class SiteSettingsController extends Controller
{
    /**
     * Site settings.
     *
     * What the website shows from the admin settings: name, logo, contact details, social and app links, the footer's
     * link columns, whether the site
     * is under maintenance, upload limits and the payment methods offered. Works during maintenance.
     *
     * @unauthenticated
     *
     * @return array{data: array<string, mixed>}
     */
    public function __invoke(): array
    {
        return ['data' => Cache::remember('site-settings', 60, fn () => [
            'nameEn' => Setting::get('site.name_en'),
            'nameAr' => Setting::get('site.name_ar'),
            'taglineEn' => Setting::get('site.tagline_en'),
            'taglineAr' => Setting::get('site.tagline_ar'),
            'logoUrl' => Setting::get('site.logo_url'),
            'email' => Setting::get('site.email'),
            'phone' => Setting::get('site.phone'),
            'whatsapp' => Setting::get('site.whatsapp'),
            'addressEn' => Setting::get('site.address_en'),
            'addressAr' => Setting::get('site.address_ar'),
            /** Only the networks with a link. */
            'social' => (object) array_filter([...config('site.defaults')['site.social'], ...(Setting::get('site.social') ?? [])]),
            'apps' => (object) array_filter([...config('site.defaults')['site.apps'], ...(Setting::get('site.apps') ?? [])]),
            /** The footer's link columns set by the admins, or null for the website's built-in ones. */
            'footer' => Setting::get('site.footer'),
            'maintenance' => [
                'enabled' => (bool) Setting::get('maintenance.enabled'),
                'messageEn' => Setting::get('maintenance.message_en'),
                'messageAr' => Setting::get('maintenance.message_ar'),
                'endsAt' => Setting::get('maintenance.ends_at'),
            ],
            /** Upload limits in MB, so forms can warn before sending. */
            'uploads' => [
                'avatarMb' => (int) Setting::get('uploads.avatar_mb'),
                'portfolioMb' => (int) Setting::get('uploads.portfolio_mb'),
                'documentMb' => (int) Setting::get('uploads.document_mb'),
                'chatMb' => (int) Setting::get('uploads.chat_mb'),
                'attachmentTypes' => Setting::get('uploads.attachment_types'),
            ],
            // Default search-engine description; null means the website's built-in text.
            'seo' => ['descriptionEn' => Setting::get('seo.description_en'), 'descriptionAr' => Setting::get('seo.description_ar')],
            // Card payments: the publishable key of the Moyasar mode in use (public by design), null when cards are off.
            'payments' => [
                'moyasarPublishableKey' => config('payments.driver') === 'moyasar' ? config('payments.moyasar.publishable_key') : null,
                'mode' => config('payments.driver') === 'moyasar' ? MoyasarMode::current() : null,
            ],
            'depositMethods' => config('payments.deposit_methods'),
            'withdrawalMethods' => config('payments.withdrawal_methods'),
        ])];
    }

    /**
     * Drops the cached public site settings, called after an admin changes them.
     */
    public static function forget(): void
    {
        Cache::forget('site-settings');
    }
}
