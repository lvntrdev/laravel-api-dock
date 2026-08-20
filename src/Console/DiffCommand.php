<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Console;

use Illuminate\Console\Command;
use JsonException;
use LvntR\ApiDock\Support\DocumentGenerator;
use LvntR\ApiDock\Support\OpenApiSnapshot;
use LvntR\ApiDock\Support\SpecChange;
use LvntR\ApiDock\Support\SpecDiffer;
use LvntR\ApiDock\Support\SpecDiffResult;

final class DiffCommand extends Command
{
    protected $signature = 'api-dock:diff
        {--json : Emit the structured diff as JSON}';

    protected $description = 'Compare the generated OpenAPI document with the stored snapshot';

    /** @throws JsonException */
    public function handle(DocumentGenerator $documentGenerator): int
    {
        $document = $documentGenerator();

        $result = (new SpecDiffer)->diff(
            OpenApiSnapshot::fromConfig()->read() ?? [],
            $document,
        );

        if ($this->option('json') === true) {
            $this->line((string) json_encode(
                $result->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->renderResult($result);

        return self::SUCCESS;
    }

    private function renderResult(SpecDiffResult $result): void
    {
        if ($result->changes === []) {
            $this->info('No OpenAPI changes detected.');

            return;
        }

        foreach (['breaking', 'additive', 'cosmetic'] as $severity) {
            $changes = array_values(array_filter(
                $result->changes,
                static fn (SpecChange $change): bool => $change->severity === $severity,
            ));

            if ($changes === []) {
                continue;
            }

            $this->line(strtoupper($severity));

            foreach ($changes as $change) {
                $this->line(sprintf('  - [%s] %s', $change->type, $change->description));
            }
        }
    }
}
