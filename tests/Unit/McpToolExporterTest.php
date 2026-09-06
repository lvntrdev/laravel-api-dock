<?php

declare(strict_types=1);

use LvntR\ApiDock\Export\McpToolExporter;
use LvntR\ApiDock\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('api-dock.ai.mcp_opt_in', false);
});

it('merges path query header and body inputs with the correct required names', function (): void {
    $tools = (new McpToolExporter)->export(mcpExporterDocument([
        '/users/{id}' => [
            'post' => [
                'operationId' => 'updateUser',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'integer']],
                    ['name' => 'expand', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'locale', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']],
                ],
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'nickname' => ['type' => 'string'],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]));

    expect($tools)->toHaveCount(1)
        ->and(array_keys($tools[0]['inputSchema']['properties']))
        ->toBe(['id', 'expand', 'locale', 'name', 'nickname'])
        ->and($tools[0]['inputSchema']['required'])->toBe(['id', 'expand', 'name'])
        ->and($tools[0]['inputSchema']['properties']['id'])->toBe(['type' => 'integer'])
        ->and($tools[0]['inputSchema']['properties']['name'])->toBe(['type' => 'string']);
});

it('lets an operation parameter override the path item parameter of the same name and location', function (): void {
    // OpenAPI 3.1 §4.8.9: same (name, in) means replacement, not a second input.
    // Merging both emitted `id` and a renamed `path_id` for one path segment, so
    // no agent call could satisfy the tool.
    $tools = (new McpToolExporter)->export(mcpExporterDocument([
        '/users/{id}' => [
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'trace', 'in' => 'query', 'schema' => ['type' => 'string']],
            ],
            'get' => [
                'operationId' => 'showUser',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
            ],
        ],
    ]));

    expect($tools)->toHaveCount(1)
        ->and(array_keys($tools[0]['inputSchema']['properties']))->toBe(['id', 'trace'])
        ->and($tools[0]['inputSchema']['properties']['id']['type'])->toBe('integer')
        ->and($tools[0]['inputSchema']['required'])->toBe(['id']);
});

it('keeps a request body that has no properties instead of dropping it from the tool', function (): void {
    $tools = (new McpToolExporter)->export(mcpExporterDocument([
        '/notes' => [
            'post' => [
                'operationId' => 'createNote',
                'requestBody' => [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['type' => 'string']]],
                ],
            ],
        ],
    ]));

    // Spreading `properties` was the only path, so a string body used to vanish
    // and no agent call could send the payload the endpoint requires.
    expect($tools[0]['inputSchema']['properties'])->toHaveKey('body')
        ->and($tools[0]['inputSchema']['properties']['body']['type'])->toBe('string')
        ->and($tools[0]['inputSchema']['required'])->toBe(['body']);
});

it('disambiguates two operations that resolve to the same tool name', function (): void {
    $tools = (new McpToolExporter)->export(mcpExporterDocument([
        '/foo-bar' => ['get' => []],
        '/foo_bar' => ['get' => []],
    ]));

    // An MCP client addresses a tool by name; two identical names are unusable.
    expect($tools)->toHaveCount(2)
        ->and($tools[0]['name'])->toBe('get_foo_bar')
        ->and($tools[1]['name'])->toBe('get_foo_bar_2');
});

it('exports a valid empty object schema when an operation has no inputs', function (): void {
    $tools = (new McpToolExporter)->export(mcpExporterDocument([
        '/health' => [
            'get' => ['operationId' => 'health'],
        ],
    ]));

    // Asserted on the encoded artifact, not on the PHP array: `properties` is an
    // object in JSON Schema, and an empty PHP array encodes as `[]`, which a host
    // that validates `inputSchema` rejects. The array assertion passed either way.
    expect(json_encode($tools[0]['inputSchema']))
        ->toBe('{"type":"object","properties":{},"required":[]}');
});

it('renames a colliding body property without overwriting either source', function (): void {
    $tools = (new McpToolExporter)->export(mcpExporterDocument([
        '/search' => [
            'post' => [
                'operationId' => 'search',
                'parameters' => [
                    ['name' => 'term', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => ['term' => ['type' => 'integer']],
                                'required' => ['term'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]));

    $schema = $tools[0]['inputSchema'];

    expect(array_keys($schema['properties']))->toBe(['term', 'body_term'])
        ->and($schema['required'])->toBe(['term', 'body_term'])
        ->and($schema['properties']['term'])->toBe(['type' => 'string'])
        ->and($schema['properties']['body_term']['type'])->toBe('integer')
        ->and($schema['properties']['body_term']['description'])
        ->toBe('(request body property "term")');
});

it('applies opt-in filtering and always excludes explicitly disabled tools', function (): void {
    $document = mcpExporterDocument([
        '/enabled' => [
            'get' => ['operationId' => 'enabled', 'x-ai-tool' => ['enabled' => true]],
        ],
        '/implicit' => [
            'get' => ['operationId' => 'implicit'],
        ],
        '/disabled' => [
            'get' => ['operationId' => 'disabled', 'x-ai-tool' => ['enabled' => false]],
        ],
    ]);

    $optOutTools = (new McpToolExporter)->export($document);
    config()->set('api-dock.ai.mcp_opt_in', true);
    $optInTools = (new McpToolExporter)->export($document);

    expect(array_column($optOutTools, 'name'))->toBe(['enabled', 'implicit'])
        ->and(array_column($optInTools, 'name'))->toBe(['enabled']);
});

it('uses configured name then operation id then a deterministic fallback', function (): void {
    $document = mcpExporterDocument([
        '/configured' => [
            'post' => [
                'operationId' => 'ignoredOperationId',
                'x-ai-tool' => ['name' => 'configured_name'],
            ],
        ],
        '/identified' => [
            'patch' => ['operationId' => 'identifiedName'],
        ],
        '/Reports/{report-id}' => [
            'get' => [],
        ],
    ]);

    $exporter = new McpToolExporter;
    $first = $exporter->export($document);
    $second = $exporter->export($document);

    expect(array_column($first, 'name'))->toBe([
        'configured_name',
        'identifiedName',
        'get_reports_report_id',
    ])->and($second[2]['name'])->toBe($first[2]['name']);
});

it('resolves component schema references and degrades missing references to an empty schema', function (): void {
    $document = mcpExporterDocument([
        '/reports' => [
            'get' => [
                'operationId' => 'reports',
                'parameters' => [
                    ['name' => 'state', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/State']],
                    ['name' => 'missing', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Missing']],
                ],
            ],
        ],
    ]);
    $document['components'] = [
        'schemas' => [
            'State' => ['type' => 'string', 'enum' => ['ready', 'failed']],
        ],
    ];

    $schema = (new McpToolExporter)->export($document)[0]['inputSchema'];

    // Asserted on the encoded artifact: an unresolvable reference must degrade to
    // an empty JSON Schema object. As a PHP `[]` it encodes as `[]`, which a
    // validator reads as a tuple rather than "any value".
    expect($schema['properties']['state'])->toBe([
        'type' => 'string',
        'enum' => ['ready', 'failed'],
    ])->and(json_encode($schema['properties']['missing']))->toBe('{}');
});

it('breaks a schema cycle into an empty object instead of recursing', function (): void {
    $document = mcpExporterDocument([
        '/nodes' => [
            'post' => [
                'operationId' => 'createNode',
                'requestBody' => [
                    'content' => [
                        'application/json' => ['schema' => ['$ref' => '#/components/schemas/Node']],
                    ],
                ],
            ],
        ],
    ]);
    $document['components'] = [
        'schemas' => [
            'Node' => [
                'type' => 'object',
                'properties' => [
                    'label' => ['type' => 'string'],
                    'parent' => ['$ref' => '#/components/schemas/Node'],
                ],
            ],
        ],
    ];

    $schema = (new McpToolExporter)->export($document)[0]['inputSchema'];

    expect(json_encode($schema['properties']['label']))->toBe('{"type":"string"}')
        ->and(json_encode($schema['properties']['parent']))->toBe('{}');
});

it('resolves a reused reference once instead of re-expanding every occurrence', function (): void {
    $schemas = [];

    // Each level composes the level below TWICE. Resolving a reference
    // independently at every occurrence doubles the work per level, so this
    // 16-level source expands into 2^16 subtree copies; resolving each
    // reference once keeps it linear in both time and memory.
    for ($level = 1; $level <= 16; $level++) {
        $schema = ['type' => 'object', 'properties' => ['field'.$level => ['type' => 'string']]];

        if ($level > 1) {
            $reference = ['$ref' => '#/components/schemas/Level'.($level - 1)];
            $schema['allOf'] = [$reference, $reference];
        }

        $schemas['Level'.$level] = $schema;
    }

    $document = mcpExporterDocument([
        '/deep' => [
            'post' => [
                'operationId' => 'deep',
                'requestBody' => [
                    'content' => [
                        'application/json' => ['schema' => ['$ref' => '#/components/schemas/Level16']],
                    ],
                ],
            ],
        ],
    ]);
    $document['components'] = ['schemas' => $schemas];

    $before = memory_get_peak_usage(true);
    $schema = (new McpToolExporter)->export($document)[0]['inputSchema'];
    $allocated = (memory_get_peak_usage(true) - $before) / 1_048_576;

    // Re-expanding every occurrence allocated 80 MB for this document and
    // 1.2 GB four levels deeper; resolving once stays under a megabyte.
    expect(array_keys($schema['properties']))->toBe([
        'field16', 'field15', 'field14', 'field13', 'field12', 'field11', 'field10', 'field9',
        'field8', 'field7', 'field6', 'field5', 'field4', 'field3', 'field2', 'field1',
    ])->and($allocated)->toBeLessThan(32.0);
});

it('keeps an integer-like body property name in the encoded properties object', function (): void {
    $tools = (new McpToolExporter)->export(mcpExporterDocument([
        '/legacy' => [
            'post' => [
                'operationId' => 'legacy',
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    '2' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                ],
                                'required' => ['2'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        '/zero' => [
            'post' => [
                'operationId' => 'zero',
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => ['0' => ['type' => 'boolean']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]));

    // A JSON object key is always a string; PHP turns "2" into an int, and the
    // old string-key filter dropped the property while `required` still asked
    // for it. A lone "0" key additionally makes the map a PHP list, which would
    // encode as a JSON array.
    expect(json_encode($tools[0]['inputSchema']))
        ->toBe('{"type":"object","properties":{"2":{"type":"string"},"name":{"type":"string"}},"required":["2"]}')
        ->and(json_encode($tools[1]['inputSchema']['properties']))
        ->toBe('{"0":{"type":"boolean"}}');
});

it('spreads a composed request body instead of nesting it under one property', function (): void {
    $document = mcpExporterDocument([
        '/invoices' => [
            'post' => [
                'operationId' => 'createInvoice',
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'allOf' => [
                                    ['$ref' => '#/components/schemas/Identified'],
                                    [
                                        'type' => 'object',
                                        'properties' => ['note' => ['type' => 'string']],
                                        'required' => ['note'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);
    $document['components'] = [
        'schemas' => [
            'Identified' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ],
        ],
    ];

    $schema = (new McpToolExporter)->export($document)[0]['inputSchema'];

    // A composed body has no top-level `properties`, so it used to collapse into
    // a single nested `body` property and every documented field vanished.
    expect(array_keys($schema['properties']))->toBe(['id', 'note'])
        ->and($schema['properties'])->not->toHaveKey('body')
        ->and($schema['required'])->toBe(['id', 'note']);
});

it('sanitises a tool name that cannot match the MCP pattern and leaves a valid one alone', function (): void {
    $longName = str_repeat('n', 100);

    $tools = (new McpToolExporter)->export(mcpExporterDocument([
        '/action' => [
            'get' => ['operationId' => 'App\\Http\\Controllers\\UserController@index'],
        ],
        '/long' => [
            'get' => ['operationId' => $longName],
        ],
        '/kept' => [
            'get' => ['x-ai-tool' => ['name' => 'already-valid_Name2']],
        ],
        '/symbols' => [
            'get' => ['operationId' => '///'],
        ],
    ]));

    $names = array_column($tools, 'name');

    // An MCP client calls a tool by name, so a conforming name is never
    // rewritten; only a name the host would reject is transformed.
    expect($names[0])->toBe('App_Http_Controllers_UserController_index')
        ->and($names[2])->toBe('already-valid_Name2')
        ->and(strlen($names[1]))->toBe(64)
        ->and($names[3])->toStartWith('tool_')
        ->and($names)->each->toMatch('/^[A-Za-z0-9_-]{1,64}$/');
});

/**
 * @param  array<string, mixed>  $paths
 * @return array<string, mixed>
 */
function mcpExporterDocument(array $paths): array
{
    return [
        'openapi' => '3.1.0',
        'info' => ['title' => 'MCP Fixture', 'version' => '1.0.0'],
        'paths' => $paths,
    ];
}
