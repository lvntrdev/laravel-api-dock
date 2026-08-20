<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Console;

use Illuminate\Console\Command;
use LvntR\ApiDock\Support\DocumentGenerator;
use LvntR\ApiDock\Support\OpenApiSnapshot;
use LvntR\ApiDock\Support\SpecChange;
use LvntR\ApiDock\Support\SpecDiffer;
use LvntR\ApiDock\Support\SpecDiffResult;

final class SyncCommand extends Command
{
    protected $signature = 'api-dock:sync
        {--check : Exit with code 1 on breaking changes and do not write the snapshot}';

    protected $description = 'Regenerate, compare, and store the API Dock OpenAPI snapshot';

    public function handle(DocumentGenerator $documentGenerator): int
    {
        $document = $documentGenerator();

        $snapshot = OpenApiSnapshot::fromConfig();
        $result = (new SpecDiffer)->diff($snapshot->read() ?? [], $document);

        $this->renderResult($result);

        if ($this->option('check') === true) {
            return $result->hasBreaking() ? self::FAILURE : self::SUCCESS;
        }

        $snapshot->write($document);
        $this->info('OpenAPI snapshot written.');

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
