<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Support;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use LvntR\ApiDock\ApiDockServiceProvider;
use LvntR\ApiDock\DocumentTransformers\ApiDockTransformer;

/**
 * Single entry point for producing the package's OpenAPI document.
 *
 * Every consumer — the spec route, the console commands, the exporters — goes
 * through here so the document-level post-processing in `ApiDockTransformer`
 * is applied exactly once and identically everywhere.
 */
final readonly class DocumentGenerator
{
    public function __construct(
        private Generator $generator,
        private ApiDockTransformer $transformer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $document = ($this->generator)(
            Scramble::getGeneratorConfig(ApiDockServiceProvider::scrambleApi()),
        );

        return $this->transformer->handle($document);
    }
}
