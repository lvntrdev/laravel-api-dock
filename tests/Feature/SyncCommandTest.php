<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use LvntR\ApiDock\Support\DocumentGenerator;
use LvntR\ApiDock\Support\OpenApiSnapshot;
use LvntR\ApiDock\Support\SpecDiffer;

beforeEach(function (): void {
    config()->set(
        'api-dock.snapshot.path',
        storage_path('framework/testing/api-dock-'.bin2hex(random_bytes(8)).'.json'),
    );
});

afterEach(function (): void {
    $path = config('api-dock.snapshot.path');

    if (is_string($path) && is_file($path)) {
        unlink($path);
    }
});

it('writes recursively normalised snapshots and preserves list order', function (): void {
    $path = config('api-dock.snapshot.path');
    expect($path)->toBeString();

    $snapshot = new OpenApiSnapshot($path);
    $snapshot->write([
        'z' => true,
        'list' => [
            ['z' => 1, 'a' => 2],
            ['second', 'first'],
        ],
        'a' => ['z' => 1, 'a' => 2],
    ]);

    $bytes = file_get_contents($path);
    expect($bytes)->toBeString();

    // Assert on the decoded key order, not on strpos: a nested "z" occurs
    // before the top-level "z" in the encoded bytes, so substring positions
    // cannot express "these keys are sorted".
    $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($decoded))->toBe(['a', 'list', 'z'])
        ->and(array_keys($decoded['a']))->toBe(['a', 'z'])
        ->and(array_keys($decoded['list'][0]))->toBe(['a', 'z'])
        ->and($decoded['list'][1])->toBe(['second', 'first']);
});

it('produces byte-identical snapshots for consecutive unchanged generations', function (): void {
    $this->artisan('api-dock:sync')->assertExitCode(0);

    $path = config('api-dock.snapshot.path');
    expect($path)->toBeString();
    $first = file_get_contents($path);

    $this->artisan('api-dock:sync')->assertExitCode(0);
    $second = file_get_contents($path);

    expect($first)->toBeString()->and($second)->toBe($first);
});

it('classifies breaking additive and cosmetic changes with stable public fields', function (): void {
    $before = apiDockDocument([
        '/users/{id}' => [
            'get' => [
                'parameters' => [
                    ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => ['name' => ['type' => 'string']],
                                'required' => ['name'],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'User',
                        'content' => [
                            'application/json' => [
                                'schema' => ['type' => 'string', 'enum' => ['active', 'disabled']],
                            ],
                        ],
                    ],
                    '404' => ['description' => 'Missing'],
                ],
                'x-ai-hint' => 'Old hint',
                'x-api-dock-features' => ['auth' => false, 'scopes' => []],
            ],
        ],
    ]);

    $after = apiDockDocument([
        '/users/{user}' => [
            'get' => [
                'parameters' => [
                    ['in' => 'path', 'name' => 'user', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['in' => 'query', 'name' => 'sort', 'required' => false, 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'email' => ['type' => 'string'],
                                ],
                                'required' => ['name', 'email'],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'User result',
                        'content' => [
                            'application/json' => [
                                'schema' => ['type' => 'string', 'enum' => ['active', 'pending']],
                            ],
                        ],
                    ],
                    '201' => ['description' => 'Created'],
                ],
                'x-ai-hint' => 'New hint',
                'x-api-dock-features' => ['auth' => true, 'scopes' => ['users:read']],
            ],
            'post' => [
                'responses' => ['201' => ['description' => 'Created']],
            ],
        ],
    ]);

    $result = (new SpecDiffer)->diff($before, $after);
    $types = array_column($result->toArray()['changes'], 'type');

    expect((new ReflectionClass($result))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($result->changes[0]))->isReadOnly())->toBeTrue()
        ->and($result->hasBreaking())->toBeTrue()
        ->and($types)->toContain(
            'path_parameter_name_changed',
            'response_code_removed',
            'response_code_added',
            'required_parameter_added',
            'optional_parameter_added',
            'request_body_became_required',
            'required_body_property_added',
            'enum_value_removed',
            'enum_value_added',
            'auth_requirement_changed',
            'vendor_extension_changed',
            'operation_added',
        );

    foreach ($result->changes as $change) {
        expect($change->severity)->toBeIn(['breaking', 'additive', 'cosmetic'])
            ->and($change->path)->toBeString()->not->toBeEmpty()
            ->and($change->type)->toBeString()->not->toBeEmpty()
            ->and($change->description)->toBeString()->not->toBeEmpty();
    }
});

it('treats scalar replacements and reduced unions as breaking but expanded unions as additive', function (): void {
    $differ = new SpecDiffer;

    $scalar = $differ->diff(
        apiDockSchemaDocument(['type' => 'string']),
        apiDockSchemaDocument(['type' => 'integer']),
    );
    $reducedUnion = $differ->diff(
        apiDockSchemaDocument(['type' => ['string', 'null']]),
        apiDockSchemaDocument(['type' => 'string']),
    );
    $expandedUnion = $differ->diff(
        apiDockSchemaDocument(['type' => 'string']),
        apiDockSchemaDocument(['type' => ['string', 'null']]),
    );

    expect($scalar->hasBreaking())->toBeTrue()
        ->and(array_column($scalar->toArray()['changes'], 'type'))->toContain('type_narrowed')
        ->and($reducedUnion->hasBreaking())->toBeTrue()
        ->and($expandedUnion->hasBreaking())->toBeFalse()
        ->and(array_column($expandedUnion->toArray()['changes'], 'type'))->toContain('type_widened');
});

it('returns one from sync check for breaking changes and never writes', function (): void {
    // Must match how the commands themselves build the document: through
    // DocumentGenerator, which applies the package's document-level metadata.
    $generated = (app(DocumentGenerator::class))();
    $baseline = $generated;
    $baseline['paths']['/removed'] = [
        'get' => ['responses' => ['200' => ['description' => 'OK']]],
    ];

    $snapshot = OpenApiSnapshot::fromConfig();
    $snapshot->write($baseline);
    $path = config('api-dock.snapshot.path');
    expect($path)->toBeString();
    $beforeCheck = file_get_contents($path);

    $this->artisan('api-dock:sync', ['--check' => true])
        ->expectsOutputToContain('BREAKING')
        ->assertExitCode(1);

    expect(file_get_contents($path))->toBe($beforeCheck);
});

it('returns zero from sync check for non-breaking changes and never writes', function (): void {
    // Must match how the commands themselves build the document: through
    // DocumentGenerator, which applies the package's document-level metadata.
    $generated = (app(DocumentGenerator::class))();
    $baseline = $generated;
    $baseline['paths'] = [];

    $snapshot = OpenApiSnapshot::fromConfig();
    $snapshot->write($baseline);
    $path = config('api-dock.snapshot.path');
    expect($path)->toBeString();
    $beforeCheck = file_get_contents($path);

    $this->artisan('api-dock:sync', ['--check' => true])->assertExitCode(0);

    expect(file_get_contents($path))->toBe($beforeCheck);
});

it('prints exactly the structured result from diff json without writing', function (): void {
    // Must match how the commands themselves build the document: through
    // DocumentGenerator, which applies the package's document-level metadata.
    $generated = (app(DocumentGenerator::class))();
    $baseline = $generated;
    $baseline['paths']['/removed'] = [
        'get' => ['responses' => ['200' => ['description' => 'OK']]],
    ];

    $snapshot = OpenApiSnapshot::fromConfig();
    $snapshot->write($baseline);
    $path = config('api-dock.snapshot.path');
    expect($path)->toBeString();
    $beforeDiff = file_get_contents($path);
    $expected = json_encode(
        (new SpecDiffer)->diff($baseline, $generated)->toArray(),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    $exitCode = Artisan::call('api-dock:diff', ['--json' => true]);

    expect($exitCode)->toBe(0)
        ->and(trim(Artisan::output()))->toBe($expected);

    expect(file_get_contents($path))->toBe($beforeDiff);
});

/**
 * @param  array<string, mixed>  $paths
 * @return array<string, mixed>
 */
function apiDockDocument(array $paths): array
{
    return [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Fixture', 'version' => '1.0.0'],
        'paths' => $paths,
    ];
}

/**
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function apiDockSchemaDocument(array $schema): array
{
    return apiDockDocument([
        '/fixture' => [
            'get' => [
                'responses' => [
                    '200' => [
                        'description' => 'OK',
                        'content' => [
                            'application/json' => ['schema' => $schema],
                        ],
                    ],
                ],
            ],
        ],
    ]);
}
