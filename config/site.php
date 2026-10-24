<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site settings
    |--------------------------------------------------------------------------
    |
    | Defaults of the values admins edit under Settings (stored in the settings table). Keys contain dots, so they are
    | read as a plain array by App\Models\Setting::get().
    |
    */

    'defaults' => [
        'site.name_en' => 'Mutamakin',
        'site.name_ar' => 'متمكن',
        'site.tagline_en' => 'Hire freelancers and influencers with confidence',
        'site.tagline_ar' => 'وظّف المستقلين والمؤثرين بثقة',
        'site.logo_url' => null,
        'site.email' => 'support@mutamakin.com',
        'site.phone' => null,
        'site.whatsapp' => null,
        'site.address_en' => null,
        'site.address_ar' => null,
        'site.social' => [
            'x' => null,
            'facebook' => null,
            'instagram' => null,
            'linkedin' => null,
            'youtube' => null,
            'tiktok' => null,
            'snapchat' => null,
        ],
        'site.apps' => ['android' => null, 'ios' => null],
        // The footer's link columns ([{titleEn, titleAr, links: [{labelEn, labelAr, href}]}]); null keeps the built-in ones.
        'site.footer' => null,

        // Turns the site off for everyone but the staff, without a deploy.
        'maintenance.enabled' => false,
        'maintenance.message_en' => 'We are improving Mutamakin. We will be back shortly.',
        'maintenance.message_ar' => 'نعمل على تحسين متمكن، وسنعود قريبًا.',
        'maintenance.ends_at' => null,

        // Upload size limits, in MB.
        'uploads.avatar_mb' => 5,
        'uploads.portfolio_mb' => 8,
        'uploads.document_mb' => 10,
        'uploads.chat_mb' => 20,
        // Chat, dispute and support attachments.
        'uploads.attachment_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar'],

        // Staff must sign in with two-factor to open the panel.
        'admin.require_2fa' => false,
    ],

    /*
    | File types that may be allowed for chat, dispute and support attachments. Admins choose from these; anything
    | executable is never on the list.
    */
    'attachment_choices' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar', 'mp4', 'mov', 'mp3'],

];
