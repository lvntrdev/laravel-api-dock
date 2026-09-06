<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Export;

use stdClass;

/**
 * One read model over a generated OpenAPI document for both exporters.
 *
 * The two exporters carried their own copy of the operation walk, the JSON
 * shape helpers and — in the MCP exporter only — reference resolution. The
 * copies drifted: `llms.txt` printed a raw `{"$ref": "..."}` block and reported
 * "No parameters." for a referenced parameter, while the MCP tool resolved the
 * same document. A reader is bound to a single document because it memoises
 * resolution, so instantiate one per export call.
 */
final class SpecReader
{
    /** @var list<string> */
    private const HTTP_METHODS = [
        'get',
        'put',
        'post',
        'delete',
        'options',
        'head',
        'patch',
        'trace',
    ];

    /**
     * Fully resolved targets, keyed by reference.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    /**
     * References on the current resolution chain, for cycle detection.
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    private int $cycleBreaks = 0;

    /** @param array<string, mixed> $document */
    public function __construct(private readonly array $document) {}

    /**
     * @return list<array{
     *     path: string,
     *     method: string,
     *     operation: array<array-key, mixed>,
     *     pathParameters: list<mixed>
     * }>
     */
    public function operations(): array
    {
        $operations = [];

        foreach (self::objectMap($this->document['paths'] ?? null) as $path => $pathItemValue) {
            $pathItem = self::objectMap($pathItemValue);
            $pathParameters = self::listValue($pathItem['parameters'] ?? null);

            foreach ($pathItem as $method => $operationValue) {
                if (! in_array($method, self::HTTP_METHODS, true) || ! is_array($operationValue)) {
                    continue;
                }

                $operations[] = [
                    'path' => (string) $path,
                    'method' => (string) $method,
                    'operation' => self::objectMap($operationValue),
                    'pathParameters' => $pathParameters,
                ];
            }
        }

        return $operations;
    }

    /**
     * Deep-resolves a parameter, request body, response or schema node into a
     * plain JSON object. A node that resolves to an empty schema comes back as
     * an empty array; callers that emit it must hand it to a JSON-object cast.
     *
     * @return array<array-key, mixed>
     */
    public function resolveObject(mixed $value): array
    {
        return self::objectMap($this->resolveNode($value));
    }

    /**
     * `$ref` inlining without a cache re-expands every shared node at every
     * occurrence, so a reused schema graph grows exponentially with depth — a
     * ~1 KB source measured 7.5 KB at depth 6 and 483 KB at depth 12. Each
     * reference is therefore resolved once and reused.
     */
    private function resolveNode(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $reference = $node['$ref'] ?? null;

        if (is_string($reference)) {
            $target = $this->resolveReference($reference);
            $siblings = $node;
            unset($siblings['$ref']);

            if ($siblings === []) {
                return $target;
            }

            // OpenAPI 3.1 keeps sibling keys next to `$ref`; they override the
            // target, which is why they are merged instead of discarded.
            $merged = is_array($target) ? $target : [];

            foreach ($siblings as $key => $value) {
                $merged[$key] = $this->resolveNode($value);
            }

            return $merged;
        }

        $resolved = [];

        foreach ($node as $key => $value) {
            $resolved[$key] = $this->resolveNode($value);
        }

        return $resolved;
    }

    /**
     * A cycle and an unresolvable target both yield an empty JSON Schema object.
     * An empty PHP array would encode as `[]` and emit `"items": []`, which a
     * schema validator reads as a tuple definition rather than "any value".
     */
    private function resolveReference(string $reference): mixed
    {
        if (array_key_exists($reference, $this->memo)) {
            return $this->memo[$reference];
        }

        if (isset($this->resolving[$reference])) {
            $this->cycleBreaks++;

            return new stdClass;
        }

        $target = $this->pointer($reference);

        if ($target === null) {
            return $this->memo[$reference] = new stdClass;
        }

        $this->resolving[$reference] = true;
        $breaksBefore = $this->cycleBreaks;
        $resolved = $this->resolveNode($target);
        unset($this->resolving[$reference]);

        // A result produced by breaking a cycle is only valid under the ancestor
        // chain that broke it, so it is not cached: the same target reached from
        // outside the cycle must still expand.
        if ($this->cycleBreaks === $breaksBefore) {
            $this->memo[$reference] = $resolved;
        }

        return $resolved;
    }

    /**
     * Local component pointers only. An external or remote document is never
     * fetched during an export, so any other reference is unresolvable.
     */
    private function pointer(string $reference): mixed
    {
        if (! str_starts_with($reference, '#/components/')) {
            return null;
        }

        $current = $this->document;

        foreach (explode('/', substr($reference, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Every JSON object key is a string, but PHP turns a numeric one into an
     * int. Filtering on `is_string()` therefore dropped a body property named
     * "2" from `properties` while `required` still demanded it, so the key is
     * cast instead of tested. This also covers response status maps.
     *
     * @return array<array-key, mixed>
     */
    public static function objectMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /** @return list<mixed> */
    public static function listValue(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @return list<string> */
    public static function stringList(mixed $value): array
    {
        return array_values(array_filter(self::listValue($value), is_string(...)));
    }

    public static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Keeps a JSON object an object once encoded: an empty PHP array and a
     * list-shaped one both encode as a JSON array, which turns an empty
     * `properties` map into `[]` and a map whose only key is "0" into `[{…}]`.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|stdClass
     */
    public static function jsonObject(array $value): array|stdClass
    {
        return array_is_list($value) ? (object) $value : $value;
    }
}
