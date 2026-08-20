<?php

declare(strict_types=1);

use LvntR\ApiDock\Export\LlmsTxtExporter;
use LvntR\ApiDock\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('api-dock.ai.include_examples', true);
});

it('groups tags by first appearance and puts untagged operations last', function (): void {
    $output = (new LlmsTxtExporter)->export(llmsExporterDocument([
        '/billing' => [
            'get' => [
                'tags' => ['Billing'],
                'summary' => 'Billing summary',
                'x-ai-hint' => 'Use the billing workflow.',
                'x-api-dock-features' => ['auth' => true, 'scopes' => ['billing:read', 'tenant:read']],
            ],
        ],
        '/users' => [
            'get' => [
                'tags' => ['Users'],
                'summary' => 'Users summary',
                'x-api-dock-features' => ['auth' => false, 'scopes' => []],
            ],
        ],
        '/invoices' => [
            'post' => [
                'tags' => ['Billing'],
                'summary' => 'Invoice summary',
                'x-api-dock-features' => ['auth' => false, 'scopes' => []],
            ],
        ],
        '/health' => [
            'get' => [
                'summary' => 'Health summary',
                'x-api-dock-features' => ['auth' => false, 'scopes' => []],
            ],
        ],
    ]));

    expect(strpos($output, '## Billing'))->toBeLessThan(strpos($output, '## Users'))
        ->and(strpos($output, '## Users'))->toBeLessThan(strpos($output, '## Untagged'))
        ->and(substr_count($output, '## Billing'))->toBe(1)
        ->and(substr_count($output, '**AI hint:**'))->toBe(1)
        ->and($output)->toContain(
            '**AI hint:** Use the billing workflow.',
            '**Authentication:** Required (scopes: billing:read, tenant:read)',
            '**Authentication:** Not required',
        );
});

it('includes example blocks only when configured', function (): void {
    $document = llmsExporterDocument([
        '/reports' => [
            'post' => [
                'summary' => 'Create report',
                'x-ai-examples' => [[
                    'name' => 'Distinct report example',
                    'request' => ['example_request_marker' => 'alpha'],
                    'response' => ['example_response_marker' => 'omega'],
                ]],
            ],
        ],
    ]);

    $withExamples = (new LlmsTxtExporter)->export($document);
    config()->set('api-dock.ai.include_examples', false);
    $withoutExamples = (new LlmsTxtExporter)->export($document);

    expect($withExamples)->toContain(
        '#### Examples',
        '##### Distinct report example',
        '"example_request_marker": "alpha"',
        '"example_response_marker": "omega"',
    )->and($withoutExamples)->not->toContain(
        '#### Examples',
        'Distinct report example',
        'example_request_marker',
        'example_response_marker',
    );
});

it('renders pitfalls as a numbered list and changelog entries under their own heading', function (): void {
    $output = (new LlmsTxtExporter)->export(llmsExporterDocument([
        '/payments' => [
            'post' => [
                'x-ai-pitfalls' => [
                    ['text' => 'Send an idempotency key.'],
                    ['text' => 'Use minor currency units.'],
                ],
                'x-api-dock-changelog' => [
                    ['date' => '2026-08-20', 'summary' => 'Added receipts.', 'breaking' => false],
                    ['date' => '2026-08-01', 'summary' => 'Renamed amount.', 'breaking' => true],
                ],
            ],
        ],
    ]));

    expect($output)->toContain(
        "#### Pitfalls\n\n1. Send an idempotency key.\n2. Use minor currency units.",
        '#### Changelog',
        '- 2026-08-20 — Added receipts.',
        '- 2026-08-01 — Renamed amount. **Breaking**',
    );
});

it('renders one row per parameter when an operation overrides a path item parameter', function (): void {
    $output = (new LlmsTxtExporter)->export(llmsExporterDocument([
        '/users/{id}' => [
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ],
            'get' => [
                'summary' => 'Show user',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
            ],
        ],
    ]));

    // Two rows would hand the model the stale type alongside the current one.
    expect(substr_count($output, '| id | path |'))->toBe(1)
        ->and($output)->toContain('| id | path | yes | integer |');
});

it('produces byte-identical output for the same document', function (): void {
    $document = llmsExporterDocument([
        '/stable' => [
            'get' => [
                'tags' => ['Stability'],
                'summary' => 'Stable operation',
                'x-ai-hint' => 'Call this operation as documented.',
                'responses' => ['200' => ['description' => 'OK']],
            ],
        ],
    ]);
    $exporter = new LlmsTxtExporter;

    expect($exporter->export($document))->toBe($exporter->export($document));
});

/**
 * @param  array<string, mixed>  $paths
 * @return array<string, mixed>
 */
function llmsExporterDocument(array $paths): array
{
    return [
        'openapi' => '3.1.0',
        'info' => [
            'title' => 'LLMS Fixture',
            'version' => '1.0.0',
            'description' => 'Fixture API.',
        ],
        'paths' => $paths,
    ];
}
