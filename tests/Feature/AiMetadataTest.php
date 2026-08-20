<?php

declare(strict_types=1);

use LvntR\ApiDock\Export\LlmsTxtExporter;
use LvntR\ApiDock\Export\McpToolExporter;

it('adds AI metadata and derived API features to annotated operations', function (): void {
    $response = $this->getJson('/api-dock/spec');

    $response->assertOk();

    $operation = $response->json('paths./metadata/{id}.get');

    expect($operation)
        ->toBeArray()
        ->and($operation['x-ai-hint'] ?? null)
        ->toBe('Use this endpoint to inspect an API resource.')
        ->and($operation['x-ai-examples'] ?? null)
        ->toBe([[
            'name' => 'Known resource',
            'request' => ['id' => '42'],
            'response' => ['id' => '42', 'status' => 'ready'],
        ]])
        ->and($operation['x-ai-tool'] ?? null)
        ->toBe([
            'enabled' => true,
            'name' => 'inspect_resource',
            'description' => 'Inspect one API resource.',
        ])
        ->and($operation['x-api-dock-features'] ?? null)
        ->toBe([
            'auth' => 'sanctum',
            'scopes' => ['resources.view'],
            'rate_limit' => ['limit' => 60, 'per' => 'minute'],
            'deprecated' => false,
            'stability' => 'beta',
        ]);

    expect($operation['x-ai-pitfalls'] ?? null)
        ->toBe([
            ['order' => 0, 'text' => 'First class pitfall in declaration order.'],
            ['order' => 0, 'text' => 'Second class pitfall in declaration order.'],
            ['order' => 10, 'text' => 'Method pitfall with an earlier explicit order.'],
            ['order' => 20, 'text' => 'Method pitfall with a later explicit order.'],
        ])
        ->and($operation['x-api-dock-changelog'] ?? null)
        ->toBe([
            ['date' => '2026-08-20', 'summary' => 'The response contract changed.', 'breaking' => true],
            ['date' => '2026-01-15', 'summary' => 'The status field was documented.', 'breaking' => false],
            ['date' => '2025-06-01', 'summary' => 'Class-level metadata was introduced.', 'breaking' => false],
            ['date' => 'not-a-date', 'summary' => 'Malformed dates remain visible.', 'breaking' => false],
        ]);
});

it('leaves an unannotated closure operation clean', function (): void {
    $response = $this->getJson('/api-dock/spec');

    $response->assertOk();

    $operation = $response->json('paths./fixture/{id}.get');

    expect($operation)
        ->toBeArray()
        ->not->toHaveKeys([
            'x-ai-hint',
            'x-ai-examples',
            'x-ai-tool',
            'x-api-dock-features',
        ]);

    expect($operation)->not->toHaveKeys([
        'x-ai-pitfalls',
        'x-api-dock-changelog',
    ]);
});

it('exports pitfalls and the human changelog to their intended agent surfaces', function (): void {
    $response = $this->getJson('/api-dock/spec');

    $response->assertOk();
    $document = $response->json();
    expect($document)->toBeArray();

    $llms = (new LlmsTxtExporter)->export($document);
    $tools = (new McpToolExporter)->export($document);
    $tool = collect($tools)->firstWhere('name', 'inspect_resource');

    expect($llms)
        ->toContain(
            '#### Pitfalls',
            '1. First class pitfall in declaration order.',
            '#### Changelog',
            '- 2026-08-20 — The response contract changed. **Breaking**',
            '- not-a-date — Malformed dates remain visible.',
        )
        ->and($tool)
        ->toBeArray()
        ->and($tool['description'] ?? null)
        ->toBe(implode("\n", [
            'Inspect one API resource.',
            '',
            'Pitfalls:',
            '1. First class pitfall in declaration order.',
            '2. Second class pitfall in declaration order.',
            '3. Method pitfall with an earlier explicit order.',
            '4. Method pitfall with a later explicit order.',
        ]))
        ->not->toContain('2026-08-20', 'The response contract changed.', 'not-a-date');
});

it('adds deterministic package metadata to the document', function (): void {
    $first = $this->getJson('/api-dock/spec');
    $second = $this->getJson('/api-dock/spec');

    $first->assertOk();
    $second->assertOk();

    expect($first->json('x-api-dock'))
        ->toBe(['version' => 'dev'])
        ->and($second->json('x-api-dock'))
        ->toBe($first->json('x-api-dock'));
});
