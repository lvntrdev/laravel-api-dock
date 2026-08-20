@php
    $locale = config('api-dock.ui.locale') ?? app()->getLocale();
    $theme = config('api-dock.ui.theme') ?? 'light';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ config('app.name', 'Laravel') }} · API Dock</title>
    <link rel="stylesheet" href="{{ \LvntR\ApiDock\ApiDockServiceProvider::assetUrl('api-dock.css') }}">
</head>
<body>
    <div
        data-api-dock-app
        data-spec-url="{{ $specUrl ?? url(trim(config('api-dock.route_prefix', 'api-dock'), '/').'/spec') }}"
        data-base-url="{{ url(trim(config('api-dock.route_prefix', 'api-dock'), '/')) }}"
        data-csrf-token="{{ csrf_token() }}"
        data-locale="{{ $locale }}"
        data-theme="{{ $theme }}"
    ></div>
    <script defer src="{{ \LvntR\ApiDock\ApiDockServiceProvider::assetUrl('api-dock.js') }}"></script>
</body>
</html>
