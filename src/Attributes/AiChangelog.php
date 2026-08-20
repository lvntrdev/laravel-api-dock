<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class AiChangelog
{
    public function __construct(
        public string $date,
        public string $summary,
        public bool $breaking = false,
    ) {}
}
