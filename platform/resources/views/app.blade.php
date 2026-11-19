<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $branding['company_name'] ?? config('app.name') }}</title>
    @if (! empty($branding['has_favicon']))<link rel="icon" href="/branding/favicon">@endif
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
