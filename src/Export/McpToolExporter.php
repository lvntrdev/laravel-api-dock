<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Export;

final readonly class McpToolExporter
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
     * @param  array<string, mixed>  $document
     * @return list<array{
     *     name: string,
     *     description: string,
     *     inputSchema: array{
     *         type: string,
     *         properties: array<string, array<string, mixed>>,
     *         required: list<string>
     *     }
     * }>
     */
    public function export(array $document): array
    {
        $tools = [];
        $optIn = config('api-dock.ai.mcp_opt_in', false) === true;

        // An MCP client addresses a tool by name, so two tools sharing one is a
        // broken export rather than a cosmetic clash. Two paths can normalise to
        // the same fallback (`/foo-bar` and `/foo_bar`), and two AiTool
        // attributes can simply be given the same name.
        $usedNames = [];

        foreach ($this->operations($document) as $operationData) {
            $operation = $operationData['operation'];
            $aiTool = $this->stringMap($operation['x-ai-tool'] ?? null);
            $enabled = $aiTool['enabled'] ?? null;

            if (($optIn && $enabled !== true) || $enabled === false) {
                continue;
            }

            $tools[] = [
                'name' => $this->uniqueToolName(
                    $usedNames,
                    $this->toolName(
                        $operation,
                        $aiTool,
                        $operationData['method'],
                        $operationData['path'],
                    ),
                ),
                'description' => $this->description($operation, $aiTool),
                'inputSchema' => $this->inputSchema(
                    $document,
                    $operationData['pathParameters'],
                    $operation,
                ),
            ];
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{
     *     path: string,
     *     method: string,
     *     operation: array<string, mixed>,
     *     pathParameters: list<mixed>
     * }>
     */
    private function operations(array $document): array
    {
        $operations = [];

        foreach ($this->stringMap($document['paths'] ?? null) as $path => $pathItemValue) {
            $pathItem = $this->stringMap($pathItemValue);
            $pathParameters = $this->listValue($pathItem['parameters'] ?? null);

            foreach ($pathItem as $method => $operationValue) {
                if (! in_array($method, self::HTTP_METHODS, true) || ! is_array($operationValue)) {
                    continue;
                }

                $operation = $this->stringMap($operationValue);

                $operations[] = [
                    'path' => $path,
                    'method' => $method,
                    'operation' => $operation,
                    'pathParameters' => $pathParameters,
                ];
            }
        }

        return $operations;
    }

    /**
     * Deterministic `_2`, `_3`, … suffix on a name already taken by an earlier
     * tool. Export order is the document's own path order, so the same document
     * always produces the same names.
     *
     * @param  array<string, int>  $usedNames
     */
    private function uniqueToolName(array &$usedNames, string $name): string
    {
        if (! isset($usedNames[$name])) {
            $usedNames[$name] = 1;

            return $name;
        }

        do {
            $usedNames[$name]++;
            $candidate = $name.'_'.$usedNames[$name];
        } while (isset($usedNames[$candidate]));

        $usedNames[$candidate] = 1;

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $aiTool
     */
    private function toolName(array $operation, array $aiTool, string $method, string $path): string
    {
        $configuredName = $aiTool['name'] ?? null;

        if (is_string($configuredName) && trim($configuredName) !== '') {
            return $configuredName;
        }

        $operationId = $operation['operationId'] ?? null;

        if (is_string($operationId) && trim($operationId) !== '') {
            return $operationId;
        }

        $suffix = trim((string) preg_replace('/[^a-z0-9]+/i', '_', trim($path, '/')), '_');

        return strtolower($method).'_'.($suffix !== '' ? strtolower($suffix) : 'root');
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $aiTool
     */
    private function description(array $operation, array $aiTool): string
    {
        $toolDescription = $aiTool['description'] ?? null;

        if (is_string($toolDescription)) {
            return $this->appendPitfalls($toolDescription, $operation);
        }

        $summary = $this->nonEmptyString($operation['summary'] ?? null);
        $hint = $this->nonEmptyString($operation['x-ai-hint'] ?? null);

        if ($summary !== null && $hint !== null) {
            return $this->appendPitfalls($summary."\n\n".$hint, $operation);
        }

        if ($summary !== null) {
            return $this->appendPitfalls($summary, $operation);
        }

        if ($hint !== null) {
            return $this->appendPitfalls($hint, $operation);
        }

        return $this->appendPitfalls(
            $this->nonEmptyString($operation['description'] ?? null) ?? '',
            $operation,
        );
    }

    /** @param array<string, mixed> $operation */
    private function appendPitfalls(string $description, array $operation): string
    {
        $pitfalls = [];

        foreach ($this->listValue($operation['x-ai-pitfalls'] ?? null) as $pitfallValue) {
            $pitfall = $this->stringMap($pitfallValue);
            $text = $this->nonEmptyString($pitfall['text'] ?? null);

            if ($text !== null) {
                $pitfalls[] = $text;
            }
        }

        if ($pitfalls === []) {
            return $description;
        }

        $lines = ['Pitfalls:'];

        foreach ($pitfalls as $index => $pitfall) {
            $lines[] = ($index + 1).'. '.$pitfall;
        }

        return ($description !== '' ? $description."\n\n" : '').implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<mixed>  $pathParameters
     * @param  array<string, mixed>  $operation
     * @return array{
     *     type: string,
     *     properties: array<string, array<string, mixed>>,
     *     required: list<string>
     * }
     */
    private function inputSchema(array $document, array $pathParameters, array $operation): array
    {
        $properties = [];
        $required = [];
        // OpenAPI 3.1 §4.8.9: an operation-level parameter OVERRIDES a path-item
        // one with the same (name, in) pair — it does not sit beside it. Merging
        // both would emit two properties for a single path segment.
        $parameters = $this->overrideParameters(
            $pathParameters,
            $this->listValue($operation['parameters'] ?? null),
            $document,
        );

        foreach (['path', 'query', 'header'] as $source) {
            foreach ($parameters as $parameterValue) {
                $parameter = $this->resolveParameter($parameterValue, $document);

                if (($parameter['in'] ?? null) !== $source) {
                    continue;
                }

                $name = $this->nonEmptyString($parameter['name'] ?? null);

                if ($name === null) {
                    continue;
                }

                $schema = $this->resolveSchema($parameter['schema'] ?? [], $document);
                $parameterDescription = $this->nonEmptyString($parameter['description'] ?? null);

                if ($parameterDescription !== null) {
                    $schema['description'] = $parameterDescription;
                }

                $exportedName = $this->insertProperty($properties, $name, $schema, $source);

                if ($source === 'path' || ($parameter['required'] ?? null) === true) {
                    $this->appendRequired($required, $exportedName);
                }
            }
        }

        $bodySchema = $this->requestBodySchema($operation, $document);
        $bodyProperties = $this->stringMap($bodySchema['properties'] ?? null);

        // A body that is a string, an array, or an object without declared
        // properties has nothing to spread. Spreading was the only path, so such
        // a body used to vanish from the tool entirely and no agent could send it.
        if ($bodySchema !== [] && $bodyProperties === []) {
            $exportedName = $this->insertProperty($properties, 'body', $bodySchema, 'body');

            if ($this->requestBodyIsRequired($operation, $document)) {
                $this->appendRequired($required, $exportedName);
            }

            return [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
            ];
        }

        $bodyNames = [];

        foreach ($bodyProperties as $name => $schemaValue) {
            $bodyNames[$name] = $this->insertProperty(
                $properties,
                $name,
                $this->resolveSchema($schemaValue, $document),
                'body',
            );
        }

        foreach ($this->listValue($bodySchema['required'] ?? null) as $requiredName) {
            if (! is_string($requiredName)) {
                continue;
            }

            $this->appendRequired($required, $bodyNames[$requiredName] ?? $requiredName);
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $document
     */
    private function requestBodyIsRequired(array $operation, array $document): bool
    {
        return ($this->requestBody($operation, $document)['required'] ?? null) === true;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function requestBody(array $operation, array $document): array
    {
        $requestBody = $this->stringMap($operation['requestBody'] ?? null);

        if (isset($requestBody['$ref']) && is_string($requestBody['$ref'])) {
            $resolved = $this->resolveReference($requestBody['$ref'], $document);
            $requestBody = $this->stringMap($resolved);
        }

        return $requestBody;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function requestBodySchema(array $operation, array $document): array
    {
        $requestBody = $this->requestBody($operation, $document);

        $content = $this->stringMap($requestBody['content'] ?? null);
        $jsonContent = $this->stringMap($content['application/json'] ?? null);

        return $this->resolveSchema($jsonContent['schema'] ?? [], $document);
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<string, mixed>  $schema
     */
    private function insertProperty(array &$properties, string $name, array $schema, string $source): string
    {
        $exportedName = $name;

        if (array_key_exists($exportedName, $properties)) {
            $exportedName = $source.'_'.$name;
            $suffix = 2;

            while (array_key_exists($exportedName, $properties)) {
                $exportedName = $source.'_'.$name.'_'.$suffix;
                $suffix++;
            }

            $origin = match ($source) {
                'body' => sprintf('(request body property "%s")', $name),
                default => sprintf('(%s parameter "%s")', $source, $name),
            };
            $description = $this->nonEmptyString($schema['description'] ?? null);
            $schema['description'] = $description !== null ? $description.' '.$origin : $origin;
        }

        $properties[$exportedName] = $schema;

        return $exportedName;
    }

    /** @param list<string> $required */
    private function appendRequired(array &$required, string $name): void
    {
        if (! in_array($name, $required, true)) {
            $required[] = $name;
        }
    }

    /**
     * Path-item parameters, with any operation-level parameter sharing the same
     * `(name, in)` pair replacing it. A parameter whose identity cannot be read
     * is kept as-is: dropping it would silently lose an input.
     *
     * @param  list<mixed>  $pathParameters
     * @param  list<mixed>  $operationParameters
     * @param  array<string, mixed>  $document
     * @return list<mixed>
     */
    private function overrideParameters(array $pathParameters, array $operationParameters, array $document): array
    {
        $merged = [];
        $anonymous = [];

        foreach ([...$pathParameters, ...$operationParameters] as $parameterValue) {
            $resolved = $this->resolveParameter($parameterValue, $document);
            $name = $this->nonEmptyString($resolved['name'] ?? null);
            $in = $this->nonEmptyString($resolved['in'] ?? null);

            if ($name === null || $in === null) {
                $anonymous[] = $parameterValue;

                continue;
            }

            $merged[$in.':'.$name] = $parameterValue;
        }

        return [...array_values($merged), ...$anonymous];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function resolveParameter(mixed $value, array $document): array
    {
        $parameter = $this->stringMap($value);
        $reference = $parameter['$ref'] ?? null;

        if (! is_string($reference)) {
            return $parameter;
        }

        $resolved = $this->resolveReference($reference, $document);

        return $this->stringMap($resolved);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function resolveSchema(mixed $schema, array $document): array
    {
        $resolved = $this->resolveSchemaNode($schema, $document, []);

        return $this->stringMap($resolved);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, true>  $seen
     */
    private function resolveSchemaNode(mixed $node, array $document, array $seen): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $reference = $node['$ref'] ?? null;

        if (is_string($reference)) {
            if (isset($seen[$reference])) {
                return [];
            }

            $target = $this->resolveReference($reference, $document);

            if ($target === null) {
                return [];
            }

            $seen[$reference] = true;
            $resolvedTarget = $this->resolveSchemaNode($target, $document, $seen);
            $siblings = $node;
            unset($siblings['$ref']);
            $resolvedSiblings = $this->resolveSchemaNode($siblings, $document, $seen);

            if (! is_array($resolvedTarget) || ! is_array($resolvedSiblings)) {
                return [];
            }

            return array_replace($resolvedTarget, $resolvedSiblings);
        }

        $resolved = [];

        foreach ($node as $key => $value) {
            $resolved[$key] = $this->resolveSchemaNode($value, $document, $seen);
        }

        return $resolved;
    }

    /** @param array<string, mixed> $document */
    private function resolveReference(string $reference, array $document): mixed
    {
        if (! str_starts_with($reference, '#/components/')) {
            return null;
        }

        $current = $document;

        foreach (explode('/', substr($reference, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    /** @return list<mixed> */
    private function listValue(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
