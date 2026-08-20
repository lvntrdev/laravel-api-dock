<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Tests\Fixtures;

use LvntR\ApiDock\Attributes\AiChangelog;
use LvntR\ApiDock\Attributes\AiExample;
use LvntR\ApiDock\Attributes\AiHint;
use LvntR\ApiDock\Attributes\AiPitfall;
use LvntR\ApiDock\Attributes\AiTool;
use LvntR\ApiDock\Attributes\ApiFeature;

#[AiHint('Default resource inspection guidance.')]
#[AiExample(
    name: 'Known resource',
    request: ['id' => '42'],
    response: ['id' => '42', 'status' => 'ready'],
)]
#[AiTool(name: 'inspect_resource', description: 'Inspect one API resource.')]
#[AiPitfall('First class pitfall in declaration order.')]
#[AiPitfall('Second class pitfall in declaration order.')]
#[AiChangelog('2025-06-01', 'Class-level metadata was introduced.')]
#[AiChangelog('not-a-date', 'Malformed dates remain visible.')]
#[ApiFeature(stability: 'beta')]
final class AiMetadataController
{
    /**
     * @return array{id: string, status: string}
     */
    #[AiHint('Use this endpoint to inspect an API resource.')]
    #[AiPitfall('Method pitfall with an earlier explicit order.', order: 10)]
    #[AiPitfall('Method pitfall with a later explicit order.', order: 20)]
    #[AiChangelog('2026-08-20', 'The response contract changed.', breaking: true)]
    #[AiChangelog('2026-01-15', 'The status field was documented.')]
    public function show(string $id): array
    {
        return [
            'id' => $id,
            'status' => 'ready',
        ];
    }
}
