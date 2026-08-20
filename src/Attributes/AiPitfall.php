<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class AiPitfall
{
    public function __construct(
        public string $text,
        public int $order = 0,
    ) {}
}
