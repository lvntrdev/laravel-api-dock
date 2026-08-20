<?php

declare(strict_types=1);

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\ServerVariable;

/**
 * The package documents the HOST application's Scramble API, not a private copy.
 *
 * `Scramble::registerApi()` clones the default configuration at the moment it is
 * called, so a package registering during its own boot froze a copy taken before
 * the application configured Scramble in its own service provider. Everything the
 * application had set — route filter, servers, transformers — was then missing
 * from this package's document while Scramble's own page showed it correctly.
 */
it('honours a server the host application configured after the package booted', function (): void {
    Scramble::configure()->withDocumentTransformers(static function (OpenApi $openApi): void {
        $openApi->servers = [
            Server::make('https://{tenant}.example.com/api')
                ->variables(['tenant' => ServerVariable::make(default: 'acme')]),
        ];
    });

    $response = $this->getJson('/api-dock/spec');

    $response->assertOk();

    expect($response->json('servers.0.url'))->toBe('https://{tenant}.example.com/api')
        ->and($response->json('servers.0.variables.tenant.default'))->toBe('acme');
});

it('honours an operation transformer the host application registered', function (): void {
    Scramble::configure()->withOperationTransformers(static function (mixed $operation): void {
        $operation->setAttribute('x-host-marker', true);
    });

    $response = $this->getJson('/api-dock/spec');

    $response->assertOk();

    // The package's own extensions still run alongside the host's.
    expect($response->json('paths./metadata/{id}.get.x-ai-hint'))
        ->toBe('Use this endpoint to inspect an API resource.');
});
