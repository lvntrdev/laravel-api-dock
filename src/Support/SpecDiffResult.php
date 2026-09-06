<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Support;

final readonly class SpecDiffResult
{
    /**
     * @param  list<SpecChange>  $changes
     */
    public function __construct(public array $changes) {}

    public function hasBreaking(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->severity === 'breaking') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{has_breaking: bool, changes: list<array{severity: string, path: string, operation: string|null, type: string, description: string}>}
     */
    public function toArray(): array
    {
        return [
            'has_breaking' => $this->hasBreaking(),
            'changes' => array_map(
                static fn (SpecChange $change): array => $change->toArray(),
                $this->changes,
            ),
        ];
    }
}
