<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LvntR\ApiDock\ApiDockServiceProvider;
use LvntR\ApiDock\Http\Middleware\ApiDockAccess;

/**
 * Re-run the provider's boot-time gate registration against the config in effect now.
 *
 * The provider already ran it once during boot, when `gate.enabled` was still false.
 * These tests need the branch as it behaves for an application that boots with the gate
 * turned on, and Testbench fixes the environment before the provider boots, so the
 * private hook is invoked directly rather than rebooting the whole package (which would
 * re-register its routes).
 */
function bootGateDefault(): void
{
    $provider = app()->getProvider(ApiDockServiceProvider::class);

    (new ReflectionMethod(ApiDockServiceProvider::class, 'registerDefaultAccessGate'))
        ->invoke($provider);
}

it('boots the package provider and exposes its configuration', function (): void {
    expect(app()->getProvider(ApiDockServiceProvider::class))
        ->toBeInstanceOf(ApiDockServiceProvider::class)
        ->and(config('api-dock.route_prefix'))->toBe('api-dock')
        // On out of the box, but reaching this application's own host only: the
        // empty allowlist is what keeps the shipped default from being an open proxy.
        ->and(config('api-dock.try_it.enabled'))->toBeTrue()
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

it('ships the access gate off, leaving the routes reachable and the host Gate untouched', function (): void {
    expect(config('api-dock.gate.enabled'))->toBeFalse()
        // While the feature is off the package must not put an ability into the host's
        // Gate at all: an application doing its own `Gate::has()` feature detection
        // should see exactly what it saw before this key existed.
        ->and(Gate::has(ApiDockAccess::ABILITY))->toBeFalse();

    $this->get('/api-dock')->assertOk();
    $this->getJson('/api-dock/spec')->assertOk();
    // Off means every route, including the try-it proxy, not merely the docs page. The
    // proxy's own validation (host allowlist, etc.) still applies past the gate, so a
    // non-404 status is what proves the gate itself let the request through.
    $tryItResponse = $this->postJson('/api-dock/try-it', ['method' => 'GET', 'url' => 'https://api.example.com/']);
    expect($tryItResponse->status())->not->toBe(404);
});

it('denies every route when the gate is on and nobody defined the ability', function (): void {
    config()->set('api-dock.gate.enabled', true);

    // Deliberately no ability registered: this is the operator who turned the gate on
    // and misspelled the name. It must deny, never wave the request through.
    expect(Gate::has(ApiDockAccess::ABILITY))->toBeFalse();

    $this->get('/api-dock')->assertNotFound();
    $this->getJson('/api-dock/spec')->assertNotFound();
    $this->postJson('/api-dock/try-it', ['method' => 'GET', 'url' => 'https://api.example.com/'])
        ->assertNotFound();
});

it('gates the try-it proxy and the credential endpoints, not only the panel', function (): void {
    config()->set('api-dock.gate.enabled', true);
    config()->set('api-dock.try_it.enabled', true);

    // The proxy is the outbound-request surface and the profile endpoints hold
    // credentials, so a gate that only covered the HTML panel would be decorative.
    $this->postJson('/api-dock/try-it', ['method' => 'GET', 'url' => 'https://api.example.com/'])
        ->assertNotFound();
    $this->getJson('/api-dock/try-it/profiles')->assertNotFound();
});

it('answers 404 rather than 403 when the ability denies', function (): void {
    config()->set('api-dock.gate.enabled', true);
    Gate::define(ApiDockAccess::ABILITY, static fn (?Authenticatable $user): bool => false);

    // 403 would confirm that API Dock is served here. 404 is what the package already
    // returns when it is disabled, so a refusal reveals nothing.
    $this->actingAs(new GenericUser(['id' => 1]))
        ->get('/api-dock')
        ->assertNotFound();

    // The denial covers the try-it proxy too, not only the docs page.
    $this->actingAs(new GenericUser(['id' => 1]))
        ->postJson('/api-dock/try-it', ['method' => 'GET', 'url' => 'https://api.example.com/'])
        ->assertNotFound();
});

it('lets an authorized user through when the ability passes', function (): void {
    config()->set('api-dock.gate.enabled', true);
    Gate::define(ApiDockAccess::ABILITY, static fn ($user): bool => $user->is_admin === true);

    $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]))
        ->get('/api-dock')
        ->assertOk();

    $tryItResponse = $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]))
        ->postJson('/api-dock/try-it', ['method' => 'GET', 'url' => 'https://api.example.com/']);
    expect($tryItResponse->status())->not->toBe(404);

    $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false]))
        ->getJson('/api-dock/spec')
        ->assertNotFound();
});

it('denies a guest unless the ability opts into one', function (): void {
    config()->set('api-dock.gate.enabled', true);
    // First parameter admits no null, which is how Laravel spells "authenticated only".
    Gate::define(ApiDockAccess::ABILITY, static fn ($user): bool => true);

    $this->get('/api-dock')->assertNotFound();

    // Opting in is the nullable parameter, and nothing else changes.
    Gate::define(ApiDockAccess::ABILITY, static fn (?Authenticatable $user): bool => true);

    $this->get('/api-dock')->assertOk();
});

it('registers a deny-all viewApiDock default when the application defined none', function (): void {
    config()->set('api-dock.gate.enabled', true);

    bootGateDefault();

    expect(Gate::has(ApiDockAccess::ABILITY))->toBeTrue()
        // Denies the guest it was resolved for, and an authenticated user alike.
        ->and(Gate::allows(ApiDockAccess::ABILITY))->toBeFalse()
        ->and(Gate::forUser(new GenericUser(['id' => 1]))->allows(ApiDockAccess::ABILITY))->toBeFalse();

    $this->actingAs(new GenericUser(['id' => 1]))->get('/api-dock')->assertNotFound();
});

it('never overwrites a viewApiDock ability the application already defined', function (): void {
    config()->set('api-dock.gate.enabled', true);

    // The reversed boot order: this provider listed after the host's own provider in
    // bootstrap/providers.php. Clobbering the definition here would lock out the very
    // admins it was written for.
    Gate::define(ApiDockAccess::ABILITY, static fn ($user): bool => true);

    bootGateDefault();

    $this->actingAs(new GenericUser(['id' => 1]))->get('/api-dock')->assertOk();
});

it('registers no ability at all while the gate is off', function (): void {
    bootGateDefault();

    expect(Gate::has(ApiDockAccess::ABILITY))->toBeFalse();

    $this->get('/api-dock')->assertOk();
});

it('renders the installed package version on the docs page', function (): void {
    $version = ApiDockServiceProvider::version();

    expect($version)->toBeString()->not->toBe('');

    // The panel reads the version off its own mount element; without the
    // attribute the footer silently falls back to `dev` on every install.
    $this->get('/api-dock')
        ->assertOk()
        ->assertSee('data-version="'.e($version).'"', false);
});

it('stamps the docs mount with a per-account identity that is not the user id', function (): void {
    // The browser stores try-it state per account, so it needs to tell one account
    // from another — and nothing more. A raw id would be a gratuitous disclosure,
    // and a shared stamp would let the next account read the previous one's history.
    $identityOf = function (?int $userId): string {
        $request = $userId === null
            ? $this->get('/api-dock')
            : $this->actingAs(new GenericUser(['id' => $userId]))->get('/api-dock');

        $matched = preg_match('/data-identity="([^"]*)"/', $request->assertOk()->getContent() ?: '', $matches);

        expect($matched)->toBe(1);

        return $matches[1];
    };

    expect($identityOf(null))->toBe('');
    expect($identityOf(1))->toMatch('/^[0-9a-f]{16}$/')
        ->not->toBe($identityOf(2));
});
