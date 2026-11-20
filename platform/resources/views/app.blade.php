<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $branding['company_name'] ?? config('app.name') }}</title>
    <meta name="description" content="{{ $branding['company_name'] ?? config('app.name') }}: your team's AI workspace.">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#111a2e" media="(prefers-color-scheme: dark)">
    <link rel="manifest" href="/manifest.webmanifest">
    @if (! empty($branding['has_favicon']))
        <link rel="icon" href="/branding/favicon?v={{ $branding['favicon_v'] ?? 0 }}">
        @if (! empty($branding['favicon_png']))
            <link rel="icon" type="image/png" sizes="32x32" href="/branding/icon/32?v={{ $branding['favicon_v'] ?? 0 }}">
            <link rel="apple-touch-icon" href="/branding/icon/180?v={{ $branding['favicon_v'] ?? 0 }}">
        @endif
    @endif
    <script>try{var t=localStorage.getItem('sl-theme');if(!t){t={{ Js::from($branding['default_theme'] ?? 'system') }};if(t!=='light'&&t!=='dark')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.dataset.theme=t}catch(e){document.documentElement.dataset.theme='light'}</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700;9..40,800&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
    {!! file_get_contents(resource_path('icons/sprite.svg')) !!}
    <script>window.__APP__ = {{ Js::from(['installed' => $installed, 'branding' => $branding]) }};</script>
    <div id="root"></div>
</body>
</html>
