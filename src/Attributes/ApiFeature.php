<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class ApiFeature
{
    /**
     * @param  list<string>|null  $scopes
     */
    public function __construct(
        public ?string $auth = null,
        public ?array $scopes = null,
        public ?int $rateLimit = null,
        public ?string $rateLimitPer = null,
        public ?bool $deprecated = null,
        public ?string $stability = null,
    ) {}
}
