<?php

declare(strict_types=1);

/**
 * The point of the command is that nobody has to hand an assistant the rules a
 * second time, so the tests are about the file an agent will actually read.
 */
function agentGuidePath(string $file): string
{
    return base_path($file);
}

function forgetAgentGuideFiles(): void
{
    foreach (['AGENTS.md', 'CLAUDE.md', 'GEMINI.md'] as $file) {
        $path = agentGuidePath($file);

        if (is_file($path)) {
            unlink($path);
        }
    }
}

beforeEach(fn () => forgetAgentGuideFiles());
afterEach(fn () => forgetAgentGuideFiles());

it('creates AGENTS.md when the project keeps no instruction file yet', function (): void {
    $this->artisan('api-dock:agent-guide')->assertSuccessful();

    $contents = (string) file_get_contents(agentGuidePath('AGENTS.md'));

    expect($contents)->toContain('vendor/lvntr/api-dock/docs/ai-metadata-authoring.md')
        ->and($contents)->toContain('<!-- api-dock:begin -->')
        ->and($contents)->toContain('<!-- api-dock:end -->');
});

it('appends to an existing instruction file without touching what is already there', function (): void {
    file_put_contents(agentGuidePath('AGENTS.md'), "# House rules\n\nRun the tests.\n");

    $this->artisan('api-dock:agent-guide')->assertSuccessful();

    $contents = (string) file_get_contents(agentGuidePath('AGENTS.md'));

    expect($contents)->toContain('# House rules')
        ->and($contents)->toContain('Run the tests.')
        ->and($contents)->toContain('API Dock');
});

it('replaces its own block instead of stacking a second copy on re-run', function (): void {
    $this->artisan('api-dock:agent-guide')->assertSuccessful();
    $this->artisan('api-dock:agent-guide')->assertSuccessful();

    $contents = (string) file_get_contents(agentGuidePath('AGENTS.md'));

    expect(substr_count($contents, '<!-- api-dock:begin -->'))->toBe(1)
        ->and(substr_count($contents, '<!-- api-dock:end -->'))->toBe(1);
});

it('preserves text written after the block when the block is replaced', function (): void {
    $this->artisan('api-dock:agent-guide')->assertSuccessful();

    file_put_contents(
        agentGuidePath('AGENTS.md'),
        (string) file_get_contents(agentGuidePath('AGENTS.md'))."\n## Deployment\n\nUse the runbook.\n",
    );

    $this->artisan('api-dock:agent-guide')->assertSuccessful();

    $contents = (string) file_get_contents(agentGuidePath('AGENTS.md'));

    expect($contents)->toContain('## Deployment')
        ->and($contents)->toContain('Use the runbook.')
        ->and(substr_count($contents, '<!-- api-dock:begin -->'))->toBe(1);
});

it('writes every vendor instruction file the project already keeps', function (): void {
    file_put_contents(agentGuidePath('AGENTS.md'), "# A\n");
    file_put_contents(agentGuidePath('CLAUDE.md'), "# C\n");

    $this->artisan('api-dock:agent-guide')->assertSuccessful();

    expect((string) file_get_contents(agentGuidePath('AGENTS.md')))->toContain('API Dock')
        ->and((string) file_get_contents(agentGuidePath('CLAUDE.md')))->toContain('API Dock')
        // GEMINI.md was not adopted by this project, so it is not created.
        ->and(is_file(agentGuidePath('GEMINI.md')))->toBeFalse();
});

it('honours an explicit --file over the files it would have discovered', function (): void {
    file_put_contents(agentGuidePath('AGENTS.md'), "# A\n");

    $this->artisan('api-dock:agent-guide', ['--file' => ['CLAUDE.md']])->assertSuccessful();

    expect((string) file_get_contents(agentGuidePath('CLAUDE.md')))->toContain('API Dock')
        ->and((string) file_get_contents(agentGuidePath('AGENTS.md')))->not->toContain('API Dock');
});

it('prints the block without writing anything when --print is given', function (): void {
    $this->artisan('api-dock:agent-guide', ['--print' => true])->assertSuccessful();

    expect(is_file(agentGuidePath('AGENTS.md')))->toBeFalse();
});

it('states the rules that decide where a fact goes', function (): void {
    $this->artisan('api-dock:agent-guide')->assertSuccessful();

    $contents = (string) file_get_contents(agentGuidePath('AGENTS.md'));

    expect($contents)->toContain('AiChangelog')
        ->and($contents)->toContain('api-dock:diff')
        // Language is the project's decision, so the block must say to match it
        // rather than name one.
        ->and($contents)->toContain('language this project');
});
