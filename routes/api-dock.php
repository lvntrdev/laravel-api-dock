<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LvntR\ApiDock\Http\Controllers\AuthProfileController;
use LvntR\ApiDock\Http\Controllers\ProxyController;
use LvntR\ApiDock\Http\Controllers\SpecController;
use LvntR\ApiDock\Http\Middleware\ApiDockAccess;

$middleware = array_values(array_merge(
    (array) config('api-dock.middleware', ['web']),
    [ApiDockAccess::class],
));

Route::middleware($middleware)
    ->prefix((string) config('api-dock.route_prefix', 'api-dock'))
    ->group(function (): void {
        Route::get('/spec', SpecController::class)->name('api-dock.spec');

        // Outbound try-it calls are rate limited on top of ApiDockAccess: every
        // request here leaves the server, so the route is a standing SSRF target.
        Route::post('/try-it', ProxyController::class)
            ->middleware('throttle:'.(string) config('api-dock.try_it.throttle', '30,1'))
            ->name('api-dock.try-it');

        // Credential ingestion shares the proxy's throttle: both are try-it
        // surfaces and both are pointless to leave open at a higher rate.
        Route::middleware('throttle:'.(string) config('api-dock.try_it.throttle', '30,1'))
            ->group(function (): void {
                Route::get('/try-it/profiles', [AuthProfileController::class, 'index'])
                    ->name('api-dock.try-it.profiles.index');
                Route::post('/try-it/profiles', [AuthProfileController::class, 'store'])
                    ->name('api-dock.try-it.profiles.store');
                Route::delete('/try-it/profiles/{profile}', [AuthProfileController::class, 'destroy'])
                    ->name('api-dock.try-it.profiles.destroy');
            });

        Route::view('/', 'api-dock::docs')->name('api-dock.docs');
    });
