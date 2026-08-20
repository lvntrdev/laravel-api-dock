<?php

declare(strict_types=1);

use LvntR\ApiDock\Export\LlmsTxtExporter;
use LvntR\ApiDock\Export\McpToolExporter;

beforeEach(function (): void {
    config()->set(
        'api-dock.ai.export_path',
        storage_path('framework/testing/api-dock-export-'.bin2hex(random_bytes(8))),
    );
    config()->set('api-dock.ai.include_examples', true);
    config()->set('api-dock.ai.mcp_opt_in', false);
});

afterEach(function (): void {
    $directory = config('api-dock.ai.export_path');

    if (! is_string($directory)) {
        return;
    }

    foreach (['mcp-tools.json', 'llms.txt', 'openapi.json'] as $filename) {
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        if (is_file($path)) {
            unlink($path);
        }
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }
});

it('writes all selected export formats', function (): void {
    $directory = config('api-dock.ai.export_path');
    expect($directory)->toBeString();

    $this->artisan('api-dock:export', ['--mcp' => true, '--llms' => true])
        ->expectsOutputToContain('mcp-tools.json')
        ->expectsOutputToContain('llms.txt')
        ->assertExitCode(0);

    expect($directory.DIRECTORY_SEPARATOR.'mcp-tools.json')->toBeFile()
        ->and($directory.DIRECTORY_SEPARATOR.'llms.txt')->toBeFile()
        ->and(json_decode(
            (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'mcp-tools.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ))->toBeArray();
});

it('rejects an export without a selected format and writes nothing', function (): void {
    $directory = config('api-dock.ai.export_path');
    expect($directory)->toBeString();

    $this->artisan('api-dock:export')
        ->expectsOutputToContain('Select at least one export format')
        ->assertExitCode(1);

    expect($directory)->not->toBeDirectory();
});

it('merges MCP parameters and request properties without overwriting collisions', function (): void {
    $document = exportDocument([
        '/users/{id}' => [
            'post' => [
                'operationId' => 'updateUser',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'string']],
                    ['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['name' => 'filter', 'in' => 'query', 'schema' => ['type' => 'string']],
                ],
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'filter' => ['type' => 'boolean'],
                                    'name' => ['type' => 'string'],
                                ],
                                'required' => ['id', 'name'],
                            ],
                        ],
                    ],
                ],
                'responses' => ['200' => ['description' => 'Updated']],
            ],
        ],
    ]);

    $tools = (new McpToolExporter)->export($document);
    $schema = $tools[0]['inputSchema'];

    expect(array_keys($schema['properties']))->toBe([
        'id',
        'query_id',
        'filter',
        'body_id',
        'body_filter',
        'name',
    ])->and($schema['required'])->toBe(['id', 'query_id', 'body_id', 'name'])
        ->and($schema['properties']['query_id']['description'])->toContain('(query parameter "id")')
        ->and($schema['properties']['body_filter']['description'])->toContain('(request body property "filter")');
});

it('exports only explicitly enabled MCP tools in opt-in mode', function (): void {
    config()->set('api-dock.ai.mcp_opt_in', true);

    $tools = (new McpToolExporter)->export(exportDocument([
        '/included' => [
            'get' => [
                'operationId' => 'included',
                'x-ai-tool' => ['enabled' => true, 'name' => null, 'description' => null],
                'responses' => ['200' => ['description' => 'OK']],
            ],
        ],
        '/unannotated' => [
            'get' => [
                'operationId' => 'unannotated',
                'responses' => ['200' => ['description' => 'OK']],
            ],
        ],
        '/disabled' => [
            'get' => [
                'operationId' => 'disabled',
                'x-ai-tool' => ['enabled' => false, 'name' => null, 'description' => null],
                'responses' => ['200' => ['description' => 'OK']],
            ],
        ],
    ]));

    expect(array_column($tools, 'name'))->toBe(['included']);
});

it('renders llms text in first-tag order with optional hints and examples', function (): void {
    $document = exportDocument([
        '/beta' => [
            'get' => [
                'tags' => ['Beta'],
                'summary' => 'Beta summary',
                'x-ai-hint' => 'Use the beta workflow.',
                'x-ai-examples' => [[
                    'name' => 'Beta example',
                    'request' => ['id' => '42'],
                    'response' => ['status' => 'ready'],
                ]],
                'x-api-dock-features' => ['auth' => true, 'scopes' => ['beta:read']],
                'responses' => ['200' => ['description' => 'OK']],
            ],
        ],
        '/alpha' => [
            'post' => [
                'tags' => ['Alpha'],
                'summary' => 'Alpha summary',
                'x-api-dock-features' => ['auth' => false, 'scopes' => []],
                'responses' => ['201' => ['description' => 'Created']],
            ],
        ],
        '/misc' => [
            'delete' => [
                'summary' => 'Untagged summary',
                'deprecated' => true,
                'responses' => ['204' => ['description' => 'Deleted']],
            ],
        ],
    ]);

    $withExamples = (new LlmsTxtExporter)->export($document);

    expect($withExamples)->toContain(
        '# Export Fixture (1.2.3)',
        '**AI hint:** Use the beta workflow.',
        '**Authentication:** Required (scopes: beta:read)',
        '#### Examples',
        '##### Beta example',
        '##### 200',
        '##### 201',
        '##### 204',
        '**Deprecated:** Yes',
    )->and(strpos($withExamples, '## Beta'))->toBeLessThan(strpos($withExamples, '## Alpha'))
        ->and(strpos($withExamples, '## Alpha'))->toBeLessThan(strpos($withExamples, '## Untagged'))
        ->and(substr_count($withExamples, '**AI hint:**'))->toBe(1);

    config()->set('api-dock.ai.include_examples', false);
    $withoutExamples = (new LlmsTxtExporter)->export($document);

    expect($withoutExamples)->not->toContain('#### Examples', '##### Beta example');
});

/**
 * @param  array<string, mixed>  $paths
 * @return array<string, mixed>
 */
function exportDocument(array $paths): array
{
    return [
        'openapi' => '3.1.0',
        'info' => [
            'title' => 'Export Fixture',
            'version' => '1.2.3',
            'description' => 'Fixture API for exporter tests.',
        ],
        'paths' => $paths,
    ];
}
