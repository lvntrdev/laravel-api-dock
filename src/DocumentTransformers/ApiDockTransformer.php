<?php

declare(strict_types=1);

namespace LvntR\ApiDock\DocumentTransformers;

use DateTimeImmutable;
use LvntR\ApiDock\ApiDockServiceProvider;
use Throwable;

/**
 * Post-processes the generated OpenAPI document.
 *
 * This is deliberately NOT a `Dedoc\Scramble\Contracts\DocumentTransformer`.
 * Scramble's `OpenApi::toArray()` (vendor/dedoc/scramble/src/Support/Generator/OpenApi.php)
 * never merges `extensionPropertiesToArray()`, so a document-level vendor
 * extension set on the `OpenApi` object is silently dropped — unlike
 * `Operation::toArray()`, which does merge them. Document-level metadata is
 * therefore applied to the generated array instead, through
 * `LvntR\ApiDock\Support\DocumentGenerator`.
 */
final class ApiDockTransformer
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function handle(array $document): array
    {
        try {
            $document['x-api-dock'] = $this->metadata();
        } catch (Throwable) {
            // Package metadata is optional; the base document remains usable without it.
        }

        return $document;
    }

    /**
     * @return array<string, string>
     */
    public function metadata(): array
    {
        $metadata = ['version' => $this->packageVersion()];

        // Off by default on purpose: Task 3's snapshot needs two consecutive
        // generations of an unchanged API to be byte-identical.
        if (config('api-dock.include_generation_timestamp', false) === true) {
            $metadata['generated_at'] = (new DateTimeImmutable)->format(DATE_ATOM);
        }

        return $metadata;
    }

    private function packageVersion(): string
    {
        try {
            $contents = file_get_contents(dirname(__DIR__, 2).'/composer.json');

            if ($contents === false) {
                return ApiDockServiceProvider::VERSION;
            }

            $composer = json_decode($contents, true);
            $version = is_array($composer) ? ($composer['version'] ?? null) : null;

            return is_string($version) && $version !== ''
                ? $version
                : ApiDockServiceProvider::VERSION;
        } catch (Throwable) {
            return ApiDockServiceProvider::VERSION;
        }
    }
}
