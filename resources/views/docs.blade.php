@php
    $locale = config('api-dock.ui.locale') ?? app()->getLocale();
    $theme = config('api-dock.ui.theme') ?? 'light';
    // Which store this request's try-it credentials actually land in. The panel's
    // credential copy promises a lifetime, and the only honest promise is the one
    // this request's storage will keep: with persistence on, a credential outlives
    // logout and is shared across the user's sessions.
    $profileStorage = \LvntR\ApiDock\Support\AuthProfileStore::storageModeForCurrentRequest();
    // Which account the browser's stored try-it state belongs to. An opaque stamp, not
    // the id: the browser only has to tell one account apart from another, and the id
    // itself would be a gratuitous disclosure. Guests get '', so they share as before.
    // Guard-namespaced for the same reason AuthProfileStore::userCacheKey() is — two
    // guards can resolve two different people to the same id.
    $identity = \Illuminate\Support\Facades\Auth::id();
    $identity = is_string($identity) || is_int($identity) ? (string) $identity : '';
    $identity = $identity === '' ? '' : substr(hash_hmac(
        'sha256',
        (string) config('auth.defaults.guard', 'web').':'.$identity,
        (string) config('app.key'),
    ), 0, 16);
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
        data-version="{{ \LvntR\ApiDock\ApiDockServiceProvider::version() }}"
        data-profile-storage="{{ $profileStorage }}"
        data-identity="{{ $identity }}"
        @if ($profileStorage === \LvntR\ApiDock\Support\AuthProfileStore::MODE_PERSISTENT)
        data-profile-lifetime-minutes="{{ \LvntR\ApiDock\Support\AuthProfileStore::persistenceTtl() }}"
        @endif
    ></div>
    <script defer src="{{ \LvntR\ApiDock\ApiDockServiceProvider::assetUrl('api-dock.js') }}"></script>
</body>
</html>
