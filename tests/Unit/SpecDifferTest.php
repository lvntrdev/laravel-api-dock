<?php

declare(strict_types=1);

use LvntR\ApiDock\Support\SpecDiffer;

it('classifies a removed operation as breaking', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestDocument([
            '/users' => [
                'get' => ['responses' => ['200' => ['description' => 'OK']]],
            ],
        ]),
        differTestDocument([]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'operation_removed', 'severity' => 'breaking']])
        ->and($result->hasBreaking())->toBeTrue();
});

it('classifies removed and added response codes independently', function (): void {
    $before = differTestOperationDocument([
        'responses' => [
            '200' => ['description' => 'OK'],
            '404' => ['description' => 'Missing'],
        ],
    ]);
    $after = differTestOperationDocument([
        'responses' => [
            '200' => ['description' => 'OK'],
            '201' => ['description' => 'Created'],
        ],
    ]);

    $result = (new SpecDiffer)->diff($before, $after);

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([
            ['type' => 'response_code_removed', 'severity' => 'breaking'],
            ['type' => 'response_code_added', 'severity' => 'additive'],
        ])
        ->and($result->hasBreaking())->toBeTrue();
});

it('classifies a parameter becoming required and a new optional parameter', function (): void {
    $before = differTestOperationDocument([
        'parameters' => [
            ['name' => 'filter', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
        ],
        'responses' => ['200' => ['description' => 'OK']],
    ]);
    $after = differTestOperationDocument([
        'parameters' => [
            ['name' => 'filter', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string']],
        ],
        'responses' => ['200' => ['description' => 'OK']],
    ]);

    $result = (new SpecDiffer)->diff($before, $after);

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([
            ['type' => 'parameter_became_required', 'severity' => 'breaking'],
            ['type' => 'optional_parameter_added', 'severity' => 'additive'],
        ])
        ->and($result->hasBreaking())->toBeTrue();
});

it('distinguishes widened narrowed and replaced schema types', function (): void {
    $differ = new SpecDiffer;

    $widened = $differ->diff(
        differTestSchemaDocument(['type' => 'string']),
        differTestSchemaDocument(['type' => ['string', 'null']]),
    );
    $narrowed = $differ->diff(
        differTestSchemaDocument(['type' => ['string', 'null']]),
        differTestSchemaDocument(['type' => 'string']),
    );
    $replaced = $differ->diff(
        differTestSchemaDocument(['type' => 'string']),
        differTestSchemaDocument(['type' => 'integer']),
    );

    expect(differTestChangeKinds($widened->toArray()['changes']))
        ->toBe([['type' => 'type_widened', 'severity' => 'additive']])
        ->and($widened->hasBreaking())->toBeFalse()
        ->and(differTestChangeKinds($narrowed->toArray()['changes']))
        ->toBe([['type' => 'type_narrowed', 'severity' => 'breaking']])
        ->and($narrowed->hasBreaking())->toBeTrue()
        ->and(differTestChangeKinds($replaced->toArray()['changes']))
        ->toBe([['type' => 'type_narrowed', 'severity' => 'breaking']])
        ->and($replaced->hasBreaking())->toBeTrue();
});

it('classifies added and removed enum values independently', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'string', 'enum' => ['active', 'disabled']]),
        differTestSchemaDocument(['type' => 'string', 'enum' => ['active', 'pending']]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([
            ['type' => 'enum_value_removed', 'severity' => 'breaking'],
            ['type' => 'enum_value_added', 'severity' => 'additive'],
        ])
        ->and($result->hasBreaking())->toBeTrue();
});

it('classifies auth and scope feature changes as breaking', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestOperationDocument([
            'x-api-dock-features' => ['auth' => false, 'scopes' => []],
            'responses' => ['200' => ['description' => 'OK']],
        ]),
        differTestOperationDocument([
            'x-api-dock-features' => ['auth' => true, 'scopes' => ['users:read']],
            'responses' => ['200' => ['description' => 'OK']],
        ]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([
            ['type' => 'auth_requirement_changed', 'severity' => 'breaking'],
            ['type' => 'auth_requirement_changed', 'severity' => 'breaking'],
        ])
        ->and($result->hasBreaking())->toBeTrue();
});

it('classifies a renamed path parameter as breaking', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestDocument([
            '/users/{id}' => [
                'get' => ['responses' => ['200' => ['description' => 'OK']]],
            ],
        ]),
        differTestDocument([
            '/users/{user}' => [
                'get' => ['responses' => ['200' => ['description' => 'OK']]],
            ],
        ]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'path_parameter_name_changed', 'severity' => 'breaking']])
        ->and($result->hasBreaking())->toBeTrue();
});

it('classifies a newly required body and required body property as breaking', function (): void {
    $before = differTestOperationDocument([
        'requestBody' => [
            'required' => false,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['name' => ['type' => 'string']],
                    ],
                ],
            ],
        ],
        'responses' => ['200' => ['description' => 'OK']],
    ]);
    $after = differTestOperationDocument([
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
                        'required' => ['email'],
                    ],
                ],
            ],
        ],
        'responses' => ['200' => ['description' => 'OK']],
    ]);

    $result = (new SpecDiffer)->diff($before, $after);

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([
            ['type' => 'required_body_property_added', 'severity' => 'breaking'],
            ['type' => 'request_body_became_required', 'severity' => 'breaking'],
        ])
        ->and($result->hasBreaking())->toBeTrue();
});

it('returns an empty diff for equivalent documents with different key order', function (): void {
    $before = [
        'paths' => [
            '/users' => [
                'get' => [
                    'responses' => ['200' => ['description' => 'OK']],
                    'summary' => 'List users',
                    'tags' => ['Users'],
                ],
            ],
        ],
        'info' => ['version' => '1.0.0', 'title' => 'Fixture'],
        'openapi' => '3.1.0',
    ];
    $after = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Fixture', 'version' => '1.0.0'],
        'paths' => [
            '/users' => [
                'get' => [
                    'tags' => ['Users'],
                    'summary' => 'List users',
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
    ];

    $result = (new SpecDiffer)->diff($before, $after);

    expect($result->changes)->toBe([])
        ->and($result->toArray())->toBe(['has_breaking' => false, 'changes' => []])
        ->and($result->hasBreaking())->toBeFalse();
});

/**
 * @param  array<string, mixed>  $paths
 * @return array<string, mixed>
 */
function differTestDocument(array $paths): array
{
    return [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Differ Fixture', 'version' => '1.0.0'],
        'paths' => $paths,
    ];
}

/**
 * @param  array<string, mixed>  $operation
 * @return array<string, mixed>
 */
function differTestOperationDocument(array $operation): array
{
    return differTestDocument(['/fixture' => ['get' => $operation]]);
}

/**
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function differTestSchemaDocument(array $schema): array
{
    return differTestOperationDocument([
        'responses' => [
            '200' => [
                'description' => 'OK',
                'content' => ['application/json' => ['schema' => $schema]],
            ],
        ],
    ]);
}

/**
 * @param  list<array{severity: string, path: string, operation: string|null, type: string, description: string}>  $changes
 * @return list<array{type: string, severity: string}>
 */
function differTestChangeKinds(array $changes): array
{
    return array_map(
        static fn (array $change): array => [
            'type' => $change['type'],
            'severity' => $change['severity'],
        ],
        $changes,
    );
}
