<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AiHint
{
    public function __construct(public string $hint) {}
}
