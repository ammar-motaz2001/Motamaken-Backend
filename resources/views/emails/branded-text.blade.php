{{-- Plain-text part of every email (same data as emails/branded), for mail apps that do not show HTML. --}}
@isset($heading){{ $heading }}

@endisset
@isset($greeting){{ $greeting }}

@endisset
@foreach ($introLines ?? [] as $line){{ $line }}

@endforeach
@isset($code){{ $code }}

@endisset
@isset($actionText){{ $actionText }}: {{ $actionUrl }}

@endisset
@foreach ($outroLines ?? [] as $line){{ $line }}

@endforeach
@isset($quote)> {{ str_replace("\n", "\n> ", $quote) }}

@endisset
{{ __('mail.signoff') }}
{{ __('mail.team') }}

{{ config('auth_flow.frontend_url') }}
