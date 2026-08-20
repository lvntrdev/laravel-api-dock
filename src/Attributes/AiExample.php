<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class AiExample
{
    /**
     * @param  array<array-key, mixed>  $request
     * @param  array<array-key, mixed>  $response
     */
    public function __construct(
        public string $name,
        public array $request = [],
        public array $response = [],
    ) {}
}
