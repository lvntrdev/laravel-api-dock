<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Lvntr\ApiDock\Http\Controllers\AuthProfileController;
use Lvntr\ApiDock\Http\Controllers\ProxyController;
use Lvntr\ApiDock\Http\Controllers\SpecController;
use Lvntr\ApiDock\Http\Middleware\ApiDockAccess;

$middleware = array_values(array_merge(
    (array) config('api-dock.middleware', ['web']),
    [ApiDockAccess::class],
));

// Session blocking. In session storage mode the try-it credential profiles live in
// the session, and Laravel writes the whole session back when a request ends: a
// try-it that started before a profile was deleted would save its stale copy
// afterwards and bring the deleted credential back. Every route below starts the
// session, so every one takes the session lock (Laravel has no group-level block).
//
// The lock has to outlive the slowest holder — an outbound try-it, bounded by the
// same timeouts OutboundRequestGuard applies, plus a margin for DNS resolution and
// response handling. The wait is as long, so a delete queues behind an in-flight
// try-it instead of failing.
//
// Requirements, per StartSession::handleRequestWhileBlocking(): the lock comes from
// `session.block_store` (the default cache store when unset), which must support
// atomic locks. A store without them (apc, a custom driver) would throw on every
// request, so blocking stays off there and this race is not closed; `array` locks
// hold within one process only. Blocking is inert when no session driver is set
// or `api-dock.middleware` does not run StartSession. Host-app routes sharing the
// session are not serialized against these unless the host blocks them too.
$seconds = static function (string $key, int $fallback): int {
    $value = config($key, $fallback);
    $value = is_numeric($value) ? (int) $value : $fallback;

    return $value > 0 ? $value : $fallback;
};

$sessionLockMargin = 10;
$sessionLockSeconds = $seconds('api-dock.try_it.timeout', 10)
    + $seconds('api-dock.try_it.connect_timeout', 5)
    + $sessionLockMargin;

$sessionCanLock = rescue(
    static fn (): bool => Cache::store(config('session.block_store'))->getStore() instanceof LockProvider,
    false,
    false,
);

[$lockSeconds, $waitSeconds] = $sessionCanLock
    ? [$sessionLockSeconds, $sessionLockSeconds]
    : [null, null];

Route::middleware($middleware)
    ->prefix((string) config('api-dock.route_prefix', 'api-dock'))
    ->group(function () use ($lockSeconds, $waitSeconds): void {
        Route::get('/spec', SpecController::class)
            ->block($lockSeconds, $waitSeconds)
            ->name('api-dock.spec');

        // Outbound try-it calls are rate limited on top of ApiDockAccess: every
        // request here leaves the server, so the route is a standing SSRF target.
        Route::post('/try-it', ProxyController::class)
            ->middleware('throttle:'.(string) config('api-dock.try_it.throttle', '30,1'))
            ->block($lockSeconds, $waitSeconds)
            ->name('api-dock.try-it');

        // Credential ingestion shares the proxy's throttle: both are try-it
        // surfaces and both are pointless to leave open at a higher rate.
        Route::middleware('throttle:'.(string) config('api-dock.try_it.throttle', '30,1'))
            ->group(function () use ($lockSeconds, $waitSeconds): void {
                Route::get('/try-it/profiles', [AuthProfileController::class, 'index'])
                    ->block($lockSeconds, $waitSeconds)
                    ->name('api-dock.try-it.profiles.index');
                Route::post('/try-it/profiles', [AuthProfileController::class, 'store'])
                    ->block($lockSeconds, $waitSeconds)
                    ->name('api-dock.try-it.profiles.store');
                Route::delete('/try-it/profiles/{profile}', [AuthProfileController::class, 'destroy'])
                    ->block($lockSeconds, $waitSeconds)
                    ->name('api-dock.try-it.profiles.destroy');
            });

        Route::view('/', 'api-dock::docs')
            ->block($lockSeconds, $waitSeconds)
            ->name('api-dock.docs');
    });
