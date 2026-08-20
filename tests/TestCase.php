<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Tests;

use Dedoc\Scramble\ScrambleServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use LvntR\ApiDock\ApiDockServiceProvider;
use LvntR\ApiDock\Tests\Fixtures\AiMetadataController;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ScrambleServiceProvider::class,
            ApiDockServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('api-dock.enabled', true);
        // StartSession only — the documented default is ['web'], but the rest of
        // that group (CSRF, cookie encryption) only adds noise here. The session
        // itself is NOT optional: try-it profile ownership is derived from it and
        // both controllers fail closed when no session was started.
        $app['config']->set('api-dock.middleware', [StartSession::class]);
    }

    protected function defineRoutes($router): void
    {
        assert($router instanceof Router);

        $router->get('/api/fixture/{id}', static fn (string $id): array => [
            'id' => $id,
        ])->name('fixture.show');

        $router->get('/api/metadata/{id}', [AiMetadataController::class, 'show'])
            ->middleware([
                'auth:sanctum',
                'throttle:60,1',
                'can:resources.view',
            ])
            ->name('metadata.show');
    }
}
