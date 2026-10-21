{{--
    Every email Mutamakin sends: logo, brand colours, the reader's light or dark theme, and right-to-left for Arabic.
    Data (all optional): $heading, $greeting, $introLines, $code, $actionText, $actionUrl, $outroLines, $quote.
    Light colours are inline (Gmail and Outlook keep them); the dark palette overrides them where the mail app
    supports prefers-color-scheme (Apple Mail, iOS, Outlook apps). Mirrors the website's theme tokens.
--}}
@php
    $rtl = app()->getLocale() === 'ar';
    $dir = $rtl ? 'rtl' : 'ltr';
    $align = $rtl ? 'right' : 'left';
    $site = config('auth_flow.frontend_url');
    $font = $rtl ? "'Tajawal', Tahoma, Arial, sans-serif" : "'Tajawal', -apple-system, 'Segoe UI', Roboto, Arial, sans-serif";
    $introLines ??= [];
    $outroLines ??= [];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $heading ?? config('app.name') }}</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; }
    a { text-decoration: none; }
    @media (max-width: 620px) {
        .container { width: 100% !important; }
        .px { padding-left: 22px !important; padding-right: 22px !important; }
        .code { font-size: 30px !important; letter-spacing: 8px !important; }
    }
    @media (prefers-color-scheme: dark) {
        .bg { background: #121212 !important; }
        .card { background: #1c1c1c !important; border-color: #343434 !important; }
        .heading { color: #e8eef3 !important; }
        .text { color: #ededed !important; }
        .muted { color: #b3b3b3 !important; }
        .divider { border-color: #343434 !important; }
        .codebox { background: #13301f !important; border-color: #1f5c38 !important; }
        .code { color: #7fdca5 !important; }
        .quote { background: #181818 !important; border-color: #2fa860 !important; color: #b3b3b3 !important; }
        .link { color: #7fdca5 !important; }
    }
    /* Outlook.com dark mode */
    [data-ogsc] .card { background: #1c1c1c !important; }
    [data-ogsc] .heading, [data-ogsc] .text { color: #ededed !important; }
    [data-ogsc] .muted { color: #b3b3b3 !important; }
    [data-ogsc] .code { color: #7fdca5 !important; }
</style>
</head>
<body class="bg" style="margin:0;padding:0;background:#f3f5f4;">
@isset($preheader)
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader }}</div>
@endisset
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg" style="background:#f3f5f4;">
<tr><td align="center" style="padding:32px 12px;">

    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="container" style="width:600px;max-width:600px;">
        {{-- Logo --}}
        <tr><td align="center" style="padding:0 0 20px;">
            <a href="{{ $site }}" target="_blank"><img src="{{ $site }}/images/logo.png" width="170" height="45" alt="Mutamakin · متمكن" style="display:block;border:0;width:170px;height:auto;"></a>
        </td></tr>

        {{-- Card --}}
        <tr><td class="card" style="background:#ffffff;border:1px solid #e7e4e4;border-radius:18px;overflow:hidden;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                {{-- Brand accent: green into teal with an orange tip, as on the site's buttons and logo --}}
                <tr><td style="height:6px;line-height:6px;font-size:0;background:#188d4a;background-image:linear-gradient(90deg,#188d4a 0%,#0bbb89 75%,#dc7a04 100%);">&nbsp;</td></tr>

                <tr><td class="px" dir="{{ $dir }}" style="padding:36px 44px 8px;text-align:{{ $align }};font-family:{{ $font }};">
                    @isset($heading)
                        <h1 class="heading" style="margin:0 0 14px;font-size:22px;line-height:1.35;font-weight:800;color:#0e2738;">{{ $heading }}</h1>
                    @endisset
                    @isset($greeting)
                        <p class="text" style="margin:0 0 12px;font-size:16px;line-height:1.7;font-weight:700;color:#303030;">{{ $greeting }}</p>
                    @endisset
                    @foreach ($introLines as $line)
                        <p class="text" dir="auto" style="margin:0 0 12px;font-size:15px;line-height:1.75;color:#303030;text-align:{{ $align }};">{!! nl2br(e($line)) !!}</p>
                    @endforeach
                </td></tr>

                @isset($code)
                <tr><td class="px" align="center" style="padding:10px 44px 6px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
                        <tr><td class="codebox" align="center" style="background:#eafff3;border:1px dashed #9fdcb8;border-radius:14px;padding:18px 22px 18px 34px;">
                            <span class="code" dir="ltr" style="font-family:'SFMono-Regular',Menlo,Consolas,'Courier New',monospace;font-size:36px;line-height:1;font-weight:800;letter-spacing:12px;color:#165d31;">{{ $code }}</span>
                        </td></tr>
                    </table>
                </td></tr>
                @endisset

                @isset($actionText)
                <tr><td class="px" align="center" style="padding:16px 44px 8px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
                        <tr><td align="center" style="border-radius:999px;background:#188d4a;background-image:linear-gradient(90deg,#188d4a,#0bbb89);">
                            <a href="{{ $actionUrl }}" target="_blank" style="display:inline-block;padding:14px 38px;font-family:{{ $font }};font-size:15px;font-weight:700;color:#ffffff;border-radius:999px;">{{ $actionText }}</a>
                        </td></tr>
                    </table>
                </td></tr>
                @endisset

                @if (count($outroLines) || isset($quote))
                <tr><td class="px" dir="{{ $dir }}" style="padding:16px 44px 0;text-align:{{ $align }};font-family:{{ $font }};">
                    @foreach ($outroLines as $line)
                        <p class="muted" dir="auto" style="margin:0 0 10px;font-size:14px;line-height:1.7;color:#686868;text-align:{{ $align }};">{!! nl2br(e($line)) !!}</p>
                    @endforeach
                    @isset($quote)
                        <div class="quote" dir="auto" style="text-align:{{ $align }};margin:6px 0 4px;padding:12px 16px;background:#fafafa;border-{{ $align }}:3px solid #188d4a;border-radius:8px;font-size:14px;line-height:1.7;color:#686868;">{!! nl2br(e($quote)) !!}</div>
                    @endisset
                </td></tr>
                @endif

                <tr><td class="px" dir="{{ $dir }}" style="padding:22px 44px 34px;text-align:{{ $align }};font-family:{{ $font }};">
                    <p class="muted" style="margin:0;font-size:14px;line-height:1.7;color:#686868;">{{ __('mail.signoff') }}<br><strong class="text" style="color:#303030;">{{ __('mail.team') }}</strong></p>
                    @isset($actionText)
                        <p class="muted divider" style="margin:22px 0 0;padding-top:16px;border-top:1px solid #e7e4e4;font-size:12px;line-height:1.6;color:#a09f99;word-break:break-all;">
                            {{ __('mail.button_fallback') }}<br><a class="link" href="{{ $actionUrl }}" style="color:#188d4a;">{{ $actionUrl }}</a>
                        </p>
                    @endisset
                </td></tr>
            </table>
        </td></tr>

        {{-- Footer --}}
        <tr><td align="center" dir="{{ $dir }}" style="padding:22px 16px 0;font-family:{{ $font }};">
            <p class="muted" style="margin:0 0 6px;font-size:12px;line-height:1.7;color:#8a8a8a;">{{ __('mail.tagline') }}</p>
            <p class="muted" style="margin:0 0 6px;font-size:12px;line-height:1.7;color:#8a8a8a;">
                <a class="link" href="{{ $site }}" style="color:#188d4a;font-weight:700;">mutamakin.com</a>
                &nbsp;·&nbsp;
                <a class="link" href="{{ $site }}/support" style="color:#188d4a;">{{ __('mail.help') }}</a>
            </p>
            <p class="muted" style="margin:0;font-size:11px;line-height:1.7;color:#a09f99;">{{ __('mail.automated') }}<br>© {{ date('Y') }} Mutamakin · متمكن</p>
        </td></tr>
    </table>

</td></tr>
</table>
</body>
</html>
