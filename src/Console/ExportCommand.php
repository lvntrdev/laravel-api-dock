<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Console;

use Illuminate\Console\Command;
use JsonException;
use LvntR\ApiDock\Export\LlmsTxtExporter;
use LvntR\ApiDock\Export\McpToolExporter;
use LvntR\ApiDock\Support\DocumentGenerator;
use Throwable;

final class ExportCommand extends Command
{
    protected $signature = 'api-dock:export
        {--mcp : Write MCP tool definitions}
        {--llms : Write the llms.txt bundle}
        {--openapi : Write the generated OpenAPI document}
        {--output= : Override the export directory}';

    protected $description = 'Export API Dock artifacts for AI tools and OpenAPI consumers';

    public function handle(DocumentGenerator $documentGenerator): int
    {
        $formats = [
            'mcp' => $this->option('mcp') === true,
            'llms' => $this->option('llms') === true,
            'openapi' => $this->option('openapi') === true,
        ];

        if (! in_array(true, $formats, true)) {
            $this->error('Select at least one export format: --mcp, --llms, or --openapi.');

            return self::FAILURE;
        }

        try {
            $directory = $this->outputDirectory();
            $document = $documentGenerator();
            $artifacts = [];

            if ($formats['mcp']) {
                $artifacts['mcp-tools.json'] = $this->json((new McpToolExporter)->export($document));
            }

            if ($formats['llms']) {
                $artifacts['llms.txt'] = (new LlmsTxtExporter)->export($document);
            }

            if ($formats['openapi']) {
                $artifacts['openapi.json'] = $this->json($document);
            }

            $this->ensureDirectoryExists($directory);

            foreach ($artifacts as $filename => $contents) {
                $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
                $bytes = @file_put_contents($path, $contents);

                if ($bytes === false) {
                    throw new \RuntimeException('Unable to write export file: '.$path);
                }

                $this->info(sprintf('%s (%d bytes)', $path, $bytes));
            }
        } catch (Throwable $throwable) {
            $this->error('Export failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function outputDirectory(): string
    {
        $option = $this->option('output');

        if (is_string($option) && trim($option) !== '') {
            return $option;
        }

        $configured = config('api-dock.ai.export_path');

        if (! is_string($configured) || trim($configured) === '') {
            throw new \RuntimeException('The api-dock.ai.export_path configuration must be a non-empty string.');
        }

        return $configured;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create export directory: '.$directory);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     *
     * @throws JsonException
     */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
