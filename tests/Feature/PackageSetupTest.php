<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use LvntR\ApiDock\ApiDockServiceProvider;

it('boots the package provider and exposes its configuration', function (): void {
    expect(app()->getProvider(ApiDockServiceProvider::class))
        ->toBeInstanceOf(ApiDockServiceProvider::class)
        ->and(config('api-dock.route_prefix'))->toBe('api-dock')
        ->and(config('api-dock.try_it.enabled'))->toBeFalse()
        ->and(config('api-dock.try_it.allowed_hosts'))->toBe([]);

    $publishPaths = ServiceProvider::pathsToPublish(
        ApiDockServiceProvider::class,
        'api-dock-config',
    );

    expect($publishPaths)
        ->not->toBeEmpty()
        ->and(array_values($publishPaths))->toContain(config_path('api-dock.php'));
});

it('returns the generated OpenAPI document for a fixture route', function (): void {
    $response = $this->getJson('/api-dock/spec');

    $response
        ->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonStructure([
            'openapi',
            'info' => ['title', 'version'],
            'paths',
        ]);

    $paths = $response->json('paths');

    expect($response->json('openapi'))
        ->toBeString()
        ->toStartWith('3.')
        ->and($paths['/fixture/{id}']['get'] ?? null)
        ->toBeArray();
});

it('hides the spec route when API Dock is disabled', function (): void {
    config()->set('api-dock.enabled', false);

    $this->getJson('/api-dock/spec')->assertNotFound();
});
