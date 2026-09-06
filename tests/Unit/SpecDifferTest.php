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

it('classifies a widened response type as breaking and a narrowed subset as additive', function (): void {
    // A response schema is the producer's guarantee to every existing consumer. Adding a
    // possible type (`string` -> `string|null`) introduces a value nobody was prepared
    // for, so it is breaking. Dropping back to a type already covered by the wider union
    // (`string|null` -> `string`) removes nothing a compliant consumer was relying on, so
    // it is additive — each assertion stands alone so neither can mask the other.
    $widened = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'string']),
        differTestSchemaDocument(['type' => ['string', 'null']]),
    );
    $narrowedSubset = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => ['string', 'null']]),
        differTestSchemaDocument(['type' => 'string']),
    );

    expect(differTestChangeKinds($widened->toArray()['changes']))
        ->toBe([['type' => 'type_widened', 'severity' => 'breaking']]);
    expect($widened->hasBreaking())->toBeTrue();

    expect(differTestChangeKinds($narrowedSubset->toArray()['changes']))
        ->toBe([['type' => 'type_narrowed', 'severity' => 'additive']]);
    expect($narrowedSubset->hasBreaking())->toBeFalse();
});

it('classifies a full type replacement on a response as breaking, with no overlapping subset', function (): void {
    // No shared type between `string` and `integer`: unlike the subset-narrowing case
    // above, there is no compatible remainder to fall back on.
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'string']),
        differTestSchemaDocument(['type' => 'integer']),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'type_narrowed', 'severity' => 'breaking']]);
    expect($result->hasBreaking())->toBeTrue();
});

it('keeps a widened request schema type additive, unlike the same widening on a response', function (): void {
    // A request schema is the producer's own intake: accepting a wider range of client
    // input than before never strands an existing, already-valid caller.
    $result = (new SpecDiffer)->diff(
        differTestRequestSchemaDocument(['type' => 'string']),
        differTestRequestSchemaDocument(['type' => ['string', 'null']]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'type_widened', 'severity' => 'additive']])
        ->and($result->hasBreaking())->toBeFalse();
});

it('classifies added and removed enum values independently on a response', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'string', 'enum' => ['active', 'disabled']]),
        differTestSchemaDocument(['type' => 'string', 'enum' => ['active', 'pending']]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([
            ['type' => 'enum_value_added', 'severity' => 'breaking'],
            ['type' => 'enum_value_removed', 'severity' => 'additive'],
        ])
        ->and($result->hasBreaking())->toBeTrue();
});

it('keeps an added request enum value additive, unlike the same addition on a response', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestRequestSchemaDocument(['type' => 'string', 'enum' => ['active', 'disabled']]),
        differTestRequestSchemaDocument(['type' => 'string', 'enum' => ['active', 'disabled', 'pending']]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'enum_value_added', 'severity' => 'additive']])
        ->and($result->hasBreaking())->toBeFalse();
});

it('classifies a newly added enum constraint on a response as an additive narrowing', function (): void {
    // The schema had no enum at all: any string was valid. Restricting to a fixed set is
    // a narrower producer guarantee than before — this was a previously-silent case that
    // reported no change at all, which is the bug this closes, not the direction: a
    // narrower response guarantee introduces no value a consumer wasn't already handling.
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'string']),
        differTestSchemaDocument(['type' => 'string', 'enum' => ['active', 'disabled']]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'schema_constraint_narrowed', 'severity' => 'additive']]);
    expect($result->hasBreaking())->toBeFalse();
});

it('classifies a newly added maxLength constraint on a response as an additive narrowing', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'string']),
        differTestSchemaDocument(['type' => 'string', 'maxLength' => 10]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'schema_constraint_narrowed', 'severity' => 'additive']]);
    expect($result->hasBreaking())->toBeFalse();
});

it('classifies a newly added minimum constraint on a response as an additive narrowing', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'integer']),
        differTestSchemaDocument(['type' => 'integer', 'minimum' => 0]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'schema_constraint_narrowed', 'severity' => 'additive']]);
    expect($result->hasBreaking())->toBeFalse();
});

it('classifies additionalProperties flipping true to false on a response as an additive narrowing', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'object', 'additionalProperties' => true]),
        differTestSchemaDocument(['type' => 'object', 'additionalProperties' => false]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'schema_constraint_narrowed', 'severity' => 'additive']]);
    expect($result->hasBreaking())->toBeFalse();
});

it('classifies a removed items constraint on a response array schema as a breaking widening', function (): void {
    // The array no longer promises a member type at all; a consumer that parsed every
    // element as a string has lost that guarantee, so this is the wider (not narrower)
    // direction and is breaking, unlike the constraint-addition cases above.
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'array', 'items' => ['type' => 'string']]),
        differTestSchemaDocument(['type' => 'array']),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'schema_constraint_widened', 'severity' => 'breaking']]);
    expect($result->hasBreaking())->toBeTrue();
});

it('classifies a response losing its content block entirely as breaking', function (): void {
    // The response object survives (still `200`, still described) but the body it once
    // promised is simply gone — a previously silent case, since the walk stopped at the
    // still-present response and never noticed the vanished content underneath it.
    $result = (new SpecDiffer)->diff(
        differTestDocument([
            '/fixture' => ['get' => ['responses' => [
                '200' => [
                    'description' => 'OK',
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
            ]]],
        ]),
        differTestDocument([
            '/fixture' => ['get' => ['responses' => [
                '200' => ['description' => 'OK'],
            ]]],
        ]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'schema_block_removed', 'severity' => 'breaking']]);
    expect($result->hasBreaking())->toBeTrue();
});

it('classifies a changed response media type as removing the old block and adding the new one', function (): void {
    // Renaming `application/json` to `application/xml` is not a rename to this walk: it
    // is one media type disappearing and an unrelated one appearing in its place, and the
    // disappearance is what makes it breaking for a client that only speaks JSON.
    $result = (new SpecDiffer)->diff(
        differTestDocument([
            '/fixture' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ]]]],
        ]),
        differTestDocument([
            '/fixture' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'content' => ['application/xml' => ['schema' => ['type' => 'object']]],
            ]]]],
        ]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([
            ['type' => 'schema_block_removed', 'severity' => 'breaking'],
            ['type' => 'schema_block_added', 'severity' => 'additive'],
        ]);
    expect($result->hasBreaking())->toBeTrue();
});

it('classifies a schema swapped to point at a different $ref as breaking', function (): void {
    // Same `$ref` keyword, different target: previously silent because the walk only
    // compared the sibling keywords of a schema, never the reference target itself.
    $components = ['schemas' => [
        'User' => ['type' => 'object'],
        'Admin' => ['type' => 'object'],
    ]];

    $result = (new SpecDiffer)->diff(
        array_merge(
            differTestSchemaDocument(['$ref' => '#/components/schemas/User']),
            ['components' => $components],
        ),
        array_merge(
            differTestSchemaDocument(['$ref' => '#/components/schemas/Admin']),
            ['components' => $components],
        ),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'schema_reference_changed', 'severity' => 'breaking']]);
    expect($result->hasBreaking())->toBeTrue();
});

it('classifies a changed securitySchemes definition as breaking', function (): void {
    $before = differTestDocument(['/fixture' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]]);
    $before['components'] = ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]];

    $after = differTestDocument(['/fixture' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]]);
    $after['components'] = ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'basic']]];

    $result = (new SpecDiffer)->diff($before, $after);

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'auth_requirement_changed', 'severity' => 'breaking']]);
    expect($result->hasBreaking())->toBeTrue();
});

it('classifies a $ref parameter becoming required as breaking', function (): void {
    // The operation only holds a `$ref` to the shared parameter; the `required` flip
    // lives in `components.parameters`, so a walk that stops at the `$ref` keyword and
    // never follows it would silently miss this entirely.
    $components = ['parameters' => [
        'Filter' => ['name' => 'filter', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
    ]];
    $before = differTestDocument(['/fixture' => ['get' => [
        'parameters' => [['$ref' => '#/components/parameters/Filter']],
        'responses' => ['200' => ['description' => 'OK']],
    ]]]);
    $before['components'] = $components;

    $components['parameters']['Filter']['required'] = true;
    $after = differTestDocument(['/fixture' => ['get' => [
        'parameters' => [['$ref' => '#/components/parameters/Filter']],
        'responses' => ['200' => ['description' => 'OK']],
    ]]]);
    $after['components'] = $components;

    $result = (new SpecDiffer)->diff($before, $after);

    // Reported once for the operation's own parameter list and once for the shared
    // component definition the `$ref` points at.
    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([
            ['type' => 'parameter_became_required', 'severity' => 'breaking'],
            ['type' => 'parameter_became_required', 'severity' => 'breaking'],
        ]);
    expect($result->hasBreaking())->toBeTrue();
});

it('classifies a removed path parameter as breaking', function (): void {
    // Distinct from renaming the placeholder in the URL template: here the route stays
    // `/users/{id}` but the operation drops the parameter object describing it.
    $result = (new SpecDiffer)->diff(
        differTestDocument([
            '/users/{id}' => ['get' => [
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => ['200' => ['description' => 'OK']],
            ]],
        ]),
        differTestDocument([
            '/users/{id}' => ['get' => [
                'parameters' => [],
                'responses' => ['200' => ['description' => 'OK']],
            ]],
        ]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'parameter_removed', 'severity' => 'breaking']]);
    expect($result->hasBreaking())->toBeTrue();
});

it('classifies a changed servers entry as breaking', function (): void {
    $before = differTestDocument(['/fixture' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]]);
    $before['servers'] = [['url' => 'https://api.example.com']];

    $after = differTestDocument(['/fixture' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]]);
    $after['servers'] = [['url' => 'https://api-v2.example.com']];

    $result = (new SpecDiffer)->diff($before, $after);

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'server_changed', 'severity' => 'breaking']]);
    expect($result->hasBreaking())->toBeTrue();
});

it('reports no change for a reordered oneOf list with the same variants', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['oneOf' => [['type' => 'string'], ['type' => 'integer']]]),
        differTestSchemaDocument(['oneOf' => [['type' => 'integer'], ['type' => 'string']]]),
    );

    expect($result->toArray())->toBe(['has_breaking' => false, 'changes' => []]);
    expect($result->hasBreaking())->toBeFalse();
});

it('reports no change for a reordered security list with the same requirements', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestOperationDocument([
            'security' => [['apiKey' => []], ['oauth' => []]],
            'responses' => ['200' => ['description' => 'OK']],
        ]),
        differTestOperationDocument([
            'security' => [['oauth' => []], ['apiKey' => []]],
            'responses' => ['200' => ['description' => 'OK']],
        ]),
    );

    expect($result->toArray())->toBe(['has_breaking' => false, 'changes' => []]);
    expect($result->hasBreaking())->toBeFalse();
});

it('classifies a swapped oneOf variant as breaking, unlike the reordering above', function (): void {
    // Same slot count, but `integer` was replaced by `boolean` rather than merely moved:
    // the set of variants actually changed, so this must not fall into the same
    // no-op bucket as a pure reordering. Both halves of the swap are still reported;
    // which half is the breaking one follows the direction rule, and this is a
    // response: the branch that appeared is a value the consumer never had to handle,
    // while the branch that vanished only removes an output it already handled.
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['oneOf' => [['type' => 'string'], ['type' => 'integer']]]),
        differTestSchemaDocument(['oneOf' => [['type' => 'string'], ['type' => 'boolean']]]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([
            ['type' => 'schema_variant_added', 'severity' => 'breaking'],
            ['type' => 'schema_variant_removed', 'severity' => 'additive'],
        ]);
    expect($result->hasBreaking())->toBeTrue();
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

it('classifies a referenced request body becoming required as breaking', function (): void {
    // The operation only holds a `$ref`; the `required` flip lives in
    // `components.requestBodies`, where the generic schema walk ignored the scalar and
    // reported nothing at all — leaving `sync --check` green on a body every caller
    // now has to send.
    $result = (new SpecDiffer)->diff(
        differTestReferencedBodyDocument(false),
        differTestReferencedBodyDocument(true),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'request_body_became_required', 'severity' => 'breaking']]);
    expect($result->hasBreaking())->toBeTrue();
});

it('classifies a servers override changed at path item or operation scope as breaking', function (): void {
    // A `servers` override at either scope outranks the document list and decides the
    // effective endpoint. Only the root list used to be examined, so the path-level
    // change was silent and the operation-level one fell through as `cosmetic`.
    $differ = new SpecDiffer;

    $pathItem = $differ->diff(
        differTestDocument(['/fixture' => [
            'servers' => [['url' => 'https://api.example.com/v1']],
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
        ]]),
        differTestDocument(['/fixture' => [
            'servers' => [['url' => 'https://api.example.com/v2']],
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
        ]]),
    );
    $operation = $differ->diff(
        differTestOperationDocument([
            'servers' => [['url' => 'https://api.example.com/v1']],
            'responses' => ['200' => ['description' => 'OK']],
        ]),
        differTestOperationDocument([
            'servers' => [['url' => 'https://api.example.com/v2']],
            'responses' => ['200' => ['description' => 'OK']],
        ]),
    );

    expect(differTestChangeKinds($pathItem->toArray()['changes']))
        ->toBe([['type' => 'server_changed', 'severity' => 'breaking']]);
    expect($pathItem->hasBreaking())->toBeTrue();
    expect(differTestChangeKinds($operation->toArray()['changes']))
        ->toBe([['type' => 'server_changed', 'severity' => 'breaking']]);
    expect($operation->hasBreaking())->toBeTrue();
});

it('reads an added schema variant by keyword and payload direction', function (): void {
    // `allOf` is a conjunction and `oneOf` a disjunction, so an added branch pulls the
    // schema in opposite directions; the direction rule then decides who it breaks.
    // Adding a request `allOf` branch rejects input that used to be accepted, and
    // adding a response `oneOf` branch returns output no consumer was prepared for —
    // both were reported as a flat `additive` before.
    $differ = new SpecDiffer;

    $requestAllOf = $differ->diff(
        differTestRequestSchemaDocument(['allOf' => [['type' => 'object']]]),
        differTestRequestSchemaDocument(['allOf' => [
            ['type' => 'object'],
            ['properties' => ['extra' => ['type' => 'string']], 'required' => ['extra']],
        ]]),
    );
    $responseOneOf = $differ->diff(
        differTestSchemaDocument(['oneOf' => [['type' => 'string']]]),
        differTestSchemaDocument(['oneOf' => [['type' => 'string'], ['type' => 'integer']]]),
    );
    $responseAllOf = $differ->diff(
        differTestSchemaDocument(['allOf' => [['type' => 'object']]]),
        differTestSchemaDocument(['allOf' => [
            ['type' => 'object'],
            ['properties' => ['extra' => ['type' => 'string']], 'required' => ['extra']],
        ]]),
    );

    expect(differTestChangeKinds($requestAllOf->toArray()['changes']))
        ->toBe([['type' => 'schema_variant_added', 'severity' => 'breaking']]);
    expect($requestAllOf->hasBreaking())->toBeTrue();
    expect(differTestChangeKinds($responseOneOf->toArray()['changes']))
        ->toBe([['type' => 'schema_variant_added', 'severity' => 'breaking']]);
    expect($responseOneOf->hasBreaking())->toBeTrue();
    // Same keyword as the first case, opposite direction: narrowing a response
    // guarantee takes nothing away from a consumer.
    expect(differTestChangeKinds($responseAllOf->toArray()['changes']))
        ->toBe([['type' => 'schema_variant_added', 'severity' => 'additive']]);
    expect($responseAllOf->hasBreaking())->toBeFalse();
});

it('classifies a changed server variable default as breaking while an added allowed value stays additive', function (): void {
    // The URL template is byte-for-byte identical on both sides, so the declared URL
    // list shows nothing dropped; what moved is the endpoint every client that sets no
    // variable actually reaches. Widening the allowed set moves nobody.
    $differ = new SpecDiffer;

    $defaultChanged = $differ->diff(
        differTestServerVariableDocument('eu', ['eu', 'us']),
        differTestServerVariableDocument('us', ['eu', 'us']),
    );
    $allowedAdded = $differ->diff(
        differTestServerVariableDocument('eu', ['eu']),
        differTestServerVariableDocument('eu', ['eu', 'us']),
    );
    $allowedRemoved = $differ->diff(
        differTestServerVariableDocument('eu', ['eu', 'us']),
        differTestServerVariableDocument('eu', ['eu']),
    );

    expect(differTestChangeKinds($defaultChanged->toArray()['changes']))
        ->toBe([['type' => 'server_changed', 'severity' => 'breaking']]);
    expect($defaultChanged->hasBreaking())->toBeTrue();
    expect(differTestChangeKinds($allowedAdded->toArray()['changes']))
        ->toBe([['type' => 'server_changed', 'severity' => 'additive']]);
    expect($allowedAdded->hasBreaking())->toBeFalse();
    expect(differTestChangeKinds($allowedRemoved->toArray()['changes']))
        ->toBe([['type' => 'server_changed', 'severity' => 'breaking']]);
    expect($allowedRemoved->hasBreaking())->toBeTrue();
});

it('reports no change for a reordered discriminated oneOf whose branches share a shape', function (): void {
    // Both branches are objects with the same two property names and differ only in the
    // constant their tag is pinned to. On one signature they were paired by list
    // position, so reordering compared `kind: a` against `kind: b` and invented a pair
    // of breaking enum changes out of an edit that changed nothing.
    $alpha = ['type' => 'object', 'properties' => [
        'kind' => ['type' => 'string', 'enum' => ['alpha']],
        'value' => ['type' => 'string'],
    ]];
    $beta = ['type' => 'object', 'properties' => [
        'kind' => ['type' => 'string', 'enum' => ['beta']],
        'value' => ['type' => 'string'],
    ]];

    $reordered = (new SpecDiffer)->diff(
        differTestSchemaDocument(['oneOf' => [$alpha, $beta]]),
        differTestSchemaDocument(['oneOf' => [$beta, $alpha]]),
    );

    expect($reordered->toArray())->toBe(['has_breaking' => false, 'changes' => []]);

    // The same shape genuinely swapped is still reported, so the collision-resistant
    // signature did not buy quiet by matching everything.
    $gamma = ['type' => 'object', 'properties' => [
        'kind' => ['type' => 'string', 'enum' => ['gamma']],
        'value' => ['type' => 'string'],
    ]];
    $swapped = (new SpecDiffer)->diff(
        differTestSchemaDocument(['oneOf' => [$alpha, $beta]]),
        differTestSchemaDocument(['oneOf' => [$alpha, $gamma]]),
    );

    expect(differTestChangeKinds($swapped->toArray()['changes']))
        ->toBe([
            ['type' => 'schema_variant_added', 'severity' => 'breaking'],
            ['type' => 'schema_variant_removed', 'severity' => 'additive'],
        ]);
    expect($swapped->hasBreaking())->toBeTrue();
});

it('classifies removing an overlapping oneOf branch as breaking, unlike a disjoint one', function (): void {
    // `number` and `integer` overlap: an integer used to double-match and fail
    // oneOf's "exactly one" rule, so dropping the `integer` branch lets it
    // through instead — a widening, not the narrowing a disjoint removal is.
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['oneOf' => [['type' => 'number'], ['type' => 'integer']]]),
        differTestSchemaDocument(['oneOf' => [['type' => 'number']]]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'schema_variant_removed', 'severity' => 'breaking']]);
    expect($result->hasBreaking())->toBeTrue();
});

it('keeps removing a disjoint-enum oneOf branch additive despite a shared scalar type', function (): void {
    // Same `type: string` on both sides, but the `enum` tags never overlap — the
    // classic string-discriminator oneOf. No value can double-match, so this
    // stays a disjoint removal, not the number/integer overlap case above.
    $result = (new SpecDiffer)->diff(
        differTestSchemaDocument(['oneOf' => [
            ['type' => 'string', 'enum' => ['alpha']],
            ['type' => 'string', 'enum' => ['beta']],
        ]]),
        differTestSchemaDocument(['oneOf' => [
            ['type' => 'string', 'enum' => ['alpha']],
        ]]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'schema_variant_removed', 'severity' => 'additive']]);
    expect($result->hasBreaking())->toBeFalse();
});

it('classifies a first anyOf addition as breaking on a request and additive on a response', function (): void {
    $request = (new SpecDiffer)->diff(
        differTestRequestSchemaDocument(['type' => 'object']),
        differTestRequestSchemaDocument(['type' => 'object', 'anyOf' => [
            ['properties' => ['extra' => ['type' => 'string']], 'required' => ['extra']],
        ]]),
    );
    $response = (new SpecDiffer)->diff(
        differTestSchemaDocument(['type' => 'object']),
        differTestSchemaDocument(['type' => 'object', 'anyOf' => [
            ['properties' => ['extra' => ['type' => 'string']], 'required' => ['extra']],
        ]]),
    );

    expect(differTestChangeKinds($request->toArray()['changes']))
        ->toBe([['type' => 'schema_variant_added', 'severity' => 'breaking']]);
    expect($request->hasBreaking())->toBeTrue();
    expect(differTestChangeKinds($response->toArray()['changes']))
        ->toBe([['type' => 'schema_variant_added', 'severity' => 'additive']]);
    expect($response->hasBreaking())->toBeFalse();
});

it('classifies adding a request media type alternative as additive and removing one as breaking', function (): void {
    $withOneType = differTestOperationDocument([
        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        'responses' => ['200' => ['description' => 'OK']],
    ]);
    $withTwoTypes = differTestOperationDocument([
        'requestBody' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object']],
            'text/plain' => ['schema' => ['type' => 'string']],
        ]],
        'responses' => ['200' => ['description' => 'OK']],
    ]);

    // Accepting an extra format takes nothing away from a client already
    // sending `application/json`; dropping one does.
    $added = (new SpecDiffer)->diff($withOneType, $withTwoTypes);
    $removed = (new SpecDiffer)->diff($withTwoTypes, $withOneType);

    expect(differTestChangeKinds($added->toArray()['changes']))
        ->toBe([['type' => 'schema_block_added', 'severity' => 'additive']]);
    expect($added->hasBreaking())->toBeFalse();
    expect(differTestChangeKinds($removed->toArray()['changes']))
        ->toBe([['type' => 'schema_block_removed', 'severity' => 'breaking']]);
    expect($removed->hasBreaking())->toBeTrue();
});

it('does not duplicate a vendor extension added to a request body as a structural change', function (): void {
    $result = (new SpecDiffer)->diff(
        differTestOperationDocument([
            'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            'responses' => ['200' => ['description' => 'OK']],
        ]),
        differTestOperationDocument([
            'requestBody' => [
                'x-ui' => ['label' => 'Input'],
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ],
            'responses' => ['200' => ['description' => 'OK']],
        ]),
    );

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'vendor_extension_changed', 'severity' => 'cosmetic']]);
    expect($result->hasBreaking())->toBeFalse();
});

it('classifies a securityScheme description edit as cosmetic, unlike a scheme change', function (): void {
    $document = static fn (string $description): array => array_merge(
        differTestOperationDocument(['responses' => ['200' => ['description' => 'OK']]]),
        ['components' => ['securitySchemes' => ['bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'description' => $description,
        ]]]],
    );

    $result = (new SpecDiffer)->diff($document('Use a token.'), $document('Use a bearer token.'));

    expect(differTestChangeKinds($result->toArray()['changes']))
        ->toBe([['type' => 'cosmetic_change', 'severity' => 'cosmetic']]);
    expect($result->hasBreaking())->toBeFalse();
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
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function differTestRequestSchemaDocument(array $schema): array
{
    return differTestOperationDocument([
        'requestBody' => [
            'content' => ['application/json' => ['schema' => $schema]],
        ],
        'responses' => ['200' => ['description' => 'OK']],
    ]);
}

/**
 * An operation that reaches its request body only through `components.requestBodies`.
 *
 * @return array<string, mixed>
 */
function differTestReferencedBodyDocument(bool $required): array
{
    $document = differTestOperationDocument([
        'requestBody' => ['$ref' => '#/components/requestBodies/Payload'],
        'responses' => ['200' => ['description' => 'OK']],
    ]);

    $document['components'] = ['requestBodies' => ['Payload' => [
        'required' => $required,
        'content' => ['application/json' => ['schema' => ['type' => 'object']]],
    ]]];

    return $document;
}

/**
 * A templated server whose URL string never changes; only its variable does.
 *
 * @param  list<string>  $allowed
 * @return array<string, mixed>
 */
function differTestServerVariableDocument(string $default, array $allowed): array
{
    $document = differTestOperationDocument(['responses' => ['200' => ['description' => 'OK']]]);

    $document['servers'] = [[
        'url' => 'https://{region}.example.com',
        'variables' => ['region' => ['default' => $default, 'enum' => $allowed]],
    ]];

    return $document;
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
