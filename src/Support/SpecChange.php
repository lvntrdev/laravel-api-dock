<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Support;

final readonly class SpecChange
{
    public function __construct(
        public string $severity,
        public string $path,
        public ?string $operation,
        public string $type,
        public string $description,
    ) {}

    /**
     * @return array{severity: string, path: string, operation: string|null, type: string, description: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'path' => $this->path,
            'operation' => $this->operation,
            'type' => $this->type,
            'description' => $this->description,
        ];
    }
}
