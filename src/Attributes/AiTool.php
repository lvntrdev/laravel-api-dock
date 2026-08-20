<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AiTool
{
    public function __construct(
        public bool $enabled = true,
        public ?string $name = null,
        public ?string $description = null,
    ) {}
}
