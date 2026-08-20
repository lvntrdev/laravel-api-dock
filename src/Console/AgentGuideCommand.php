<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Installs the pointer that makes the authoring rules self-discovering.
 *
 * Handing an assistant the same instructions at the start of every session is the
 * failure this command exists to remove. Coding agents already read a small set of
 * repository-root instruction files before they touch anything; writing one block
 * into those files means "document this endpoint" carries the rules with it from
 * then on, for every agent and every contributor.
 *
 * The block POINTS at the guide inside `vendor/` rather than copying it. A copy is
 * a fork: it stops matching the installed version the first time this package is
 * upgraded, and nothing tells the reader which of the two is current.
 */
final class AgentGuideCommand extends Command
{
    protected $signature = 'api-dock:agent-guide
        {--file=* : Instruction file to write, relative to the project root}
        {--print : Write the block to output instead of to a file}';

    protected $description = 'Install the API Dock authoring rules into this project\'s agent instruction files';

    /**
     * Files a coding agent reads on its own before acting. AGENTS.md is the
     * cross-vendor convention; CLAUDE.md and GEMINI.md are vendor-specific and are
     * only written when the project already keeps one.
     *
     * @var list<string>
     */
    private const KNOWN_FILES = ['AGENTS.md', 'CLAUDE.md', 'GEMINI.md'];

    /** Written into the project's own file, so it must stay stable across versions. */
    private const START_MARKER = '<!-- api-dock:begin -->';

    private const END_MARKER = '<!-- api-dock:end -->';

    public function handle(): int
    {
        $block = self::block();

        if ($this->option('print') === true) {
            $this->line($block);

            return self::SUCCESS;
        }

        $targets = $this->targets();

        if ($targets === []) {
            $this->error('No instruction file to write. Pass --file=AGENTS.md to create one.');

            return self::FAILURE;
        }

        foreach ($targets as $target) {
            try {
                $this->install($target, $block);
            } catch (Throwable) {
                // The path is named but the reason is not: a failure here is a
                // permission or a disk problem on a path the operator supplied, and
                // the exception text adds nothing they cannot see themselves.
                $this->error(sprintf('[%s] could not be written.', $target));

                return self::FAILURE;
            }
        }

        $this->info('API Dock authoring rules installed. An agent reading these files now follows them without being told.');

        return self::SUCCESS;
    }

    /**
     * Explicit `--file` wins. Otherwise every known file that already exists is
     * updated, and if none does, AGENTS.md is created: an agent cannot read an
     * instruction file the project never adopted.
     *
     * @return list<string>
     */
    private function targets(): array
    {
        /** @var mixed $requested */
        $requested = $this->option('file');
        $requested = is_array($requested) ? array_values(array_filter($requested, 'is_string')) : [];

        if ($requested !== []) {
            return $requested;
        }

        $existing = array_values(array_filter(
            self::KNOWN_FILES,
            static fn (string $file): bool => is_file(base_path($file)),
        ));

        return $existing === [] ? ['AGENTS.md'] : $existing;
    }

    private function install(string $file, string $block): void
    {
        $path = base_path($file);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('directory');
        }

        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $start = strpos($existing, self::START_MARKER);
        $end = strpos($existing, self::END_MARKER);

        if ($start !== false && $end !== false && $end > $start) {
            // Re-running replaces the block in place. Without this the rules would
            // accumulate one copy per upgrade, and a stale copy above a fresh one
            // is worse than no copy at all.
            $updated = substr($existing, 0, $start)
                .$block
                .substr($existing, $end + strlen(self::END_MARKER));
        } else {
            $updated = $existing === '' ? $block."\n" : rtrim($existing)."\n\n".$block."\n";
        }

        if (file_put_contents($path, $updated) === false) {
            throw new RuntimeException('write');
        }

        $this->line(sprintf('  <fg=green>updated</> %s', $file));
    }

    /**
     * The block is deliberately short. It carries the one rule that cannot be
     * deferred — do not restate the schema, do not write a prompt into the
     * description — and sends the reader to the full guide for everything else. A
     * long block competes with the project's own instructions for attention.
     */
    private static function block(): string
    {
        $guide = 'vendor/lvntr/api-dock/docs/ai-metadata-authoring.md';
        $startMarker = self::START_MARKER;
        $endMarker = self::END_MARKER;

        return <<<MARKDOWN
        {$startMarker}
        ## Documenting an API endpoint (API Dock)

        When asked to document, re-document or update the documentation of an API
        operation in this project, **read `{$guide}` first and follow it**. It is the
        authoring contract, not background reading.

        The rules that decide everything else:

        - A fact belongs to exactly ONE of three places — the OpenAPI schema
          (parameters, types, status codes, response shapes), the docblock description
          (prose for a human), or the `LvntR\ApiDock\Attributes\*` attributes (the
          structured contract). Never restate one in another.
        - Never write an agent prompt, a parameter table, a status-code list or a
          changelog into the description. The AI panel generates the prompt from the
          schema and the attributes; a hand-written copy goes stale on its own.
        - Every contract change gets an `AiChangelog` entry on the same edit that
          changes the contract. `php artisan api-dock:diff` reporting a change that no
          entry mentions means the work is not finished.
        - Write attribute text in the language this project's existing API
          documentation already uses. Match the surface you are editing; do not
          translate it and do not introduce a second language into it.
        {$endMarker}
        MARKDOWN;
    }
}
