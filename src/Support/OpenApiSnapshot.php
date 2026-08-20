<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Support;

use JsonException;
use RuntimeException;

final readonly class OpenApiSnapshot
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(private string $path)
    {
        if ($this->path === '') {
            throw new RuntimeException('The API Dock snapshot path cannot be empty.');
        }
    }

    public static function fromConfig(): self
    {
        $path = config('api-dock.snapshot.path');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The api-dock.snapshot.path configuration value must be a non-empty string.');
        }

        return new self($path);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException
     */
    public function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read the API Dock snapshot at [%s].', $this->path));
        }

        $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($document) || array_is_list($document)) {
            throw new RuntimeException(sprintf('The API Dock snapshot at [%s] must contain a JSON object.', $this->path));
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws JsonException
     */
    public function write(array $document): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the API Dock snapshot directory [%s].', $directory));
        }

        $bytes = $this->encode($document).PHP_EOL;

        if (file_put_contents($this->path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write the API Dock snapshot at [%s].', $this->path));
        }
    }

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws JsonException
     */
    public function encode(array $document): string
    {
        return json_encode(self::normalise($document), self::JSON_FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * Recursively sort associative keys; list order is preserved.
     *
     * Public and static because the differ needs the exact same normalisation:
     * a stored snapshot is normalised, a freshly generated document is not, so
     * comparing them raw reports key-order differences as real changes.
     */
    public static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalise($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalise($item);
        }

        return $value;
    }
}
