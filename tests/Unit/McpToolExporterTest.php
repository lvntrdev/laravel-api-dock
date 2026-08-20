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

    expect($tools[0]['inputSchema'])->toBe([
        'type' => 'object',
        'properties' => [],
        'required' => [],
    ]);
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

    expect($schema['properties']['state'])->toBe([
        'type' => 'string',
        'enum' => ['ready', 'failed'],
    ])->and($schema['properties']['missing'])->toBe([]);
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
