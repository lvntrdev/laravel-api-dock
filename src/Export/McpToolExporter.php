<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Export;

use stdClass;

final readonly class McpToolExporter
{
    /**
     * An MCP host accepts a tool name of `^[A-Za-z0-9_-]{1,64}$`; 64 is the
     * shortest cap among the common hosts, so it is the one that holds.
     */
    private const TOOL_NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    private const TOOL_NAME_MAX = 64;

    /**
     * @param  array<array-key, mixed>  $document
     * @return list<array{
     *     name: string,
     *     description: string,
     *     inputSchema: array{
     *         type: string,
     *         properties: array<array-key, mixed>|stdClass,
     *         required: list<string>
     *     }
     * }>
     */
    public function export(array $document): array
    {
        $spec = new SpecReader($document);
        $tools = [];
        $optIn = config('api-dock.ai.mcp_opt_in', false) === true;

        // An MCP client addresses a tool by name, so two tools sharing one is a
        // broken export rather than a cosmetic clash. Two paths can normalise to
        // the same fallback (`/foo-bar` and `/foo_bar`), and two AiTool
        // attributes can simply be given the same name.
        $usedNames = [];

        foreach ($spec->operations() as $operationData) {
            $operation = $operationData['operation'];
            $aiTool = SpecReader::objectMap($operation['x-ai-tool'] ?? null);
            $enabled = $aiTool['enabled'] ?? null;

            if (($optIn && $enabled !== true) || $enabled === false) {
                continue;
            }

            $tools[] = [
                'name' => $this->uniqueToolName(
                    $usedNames,
                    $this->conformingToolName(
                        $this->toolName(
                            $operation,
                            $aiTool,
                            $operationData['method'],
                            $operationData['path'],
                        ),
                    ),
                ),
                'description' => $this->description($operation, $aiTool),
                'inputSchema' => $this->inputSchema(
                    $spec,
                    $operationData['pathParameters'],
                    $operation,
                ),
            ];
        }

        return $tools;
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
            $candidate = self::withSuffix($name, '_'.$usedNames[$name]);
        } while (isset($usedNames[$candidate]));

        $usedNames[$candidate] = 1;

        return $candidate;
    }

    /**
     * A client calls a tool by the name in the export, so a name that already
     * matches the host pattern is returned verbatim — rewriting it would break
     * the caller. Only a non-conforming name is transformed: a controller
     * action id such as `App\Http\Controllers\UserController@index` reaches the
     * exporter as an `operationId` and is rejected by the host as-is.
     */
    private function conformingToolName(string $name): string
    {
        if (preg_match(self::TOOL_NAME_PATTERN, $name) === 1) {
            return $name;
        }

        $candidate = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $name), '_');

        if ($candidate === '') {
            return 'tool_'.substr(sha1($name), 0, 8);
        }

        if (strlen($candidate) <= self::TOOL_NAME_MAX) {
            return $candidate;
        }

        // Two long names sharing a prefix would truncate onto each other, and a
        // collision suffix is a worse identifier than a digest of the original.
        return substr($candidate, 0, self::TOOL_NAME_MAX - 9).'_'.substr(sha1($name), 0, 8);
    }

    private static function withSuffix(string $name, string $suffix): string
    {
        $room = self::TOOL_NAME_MAX - strlen($suffix);

        return (strlen($name) <= $room ? $name : substr($name, 0, $room)).$suffix;
    }

    /**
     * @param  array<array-key, mixed>  $operation
     * @param  array<array-key, mixed>  $aiTool
     */
    private function toolName(array $operation, array $aiTool, string $method, string $path): string
    {
        $configuredName = SpecReader::nonEmptyString($aiTool['name'] ?? null);

        if ($configuredName !== null) {
            return $configuredName;
        }

        $operationId = SpecReader::nonEmptyString($operation['operationId'] ?? null);

        if ($operationId !== null) {
            return $operationId;
        }

        $suffix = trim((string) preg_replace('/[^a-z0-9]+/i', '_', trim($path, '/')), '_');

        return strtolower($method).'_'.($suffix !== '' ? strtolower($suffix) : 'root');
    }

    /**
     * @param  array<array-key, mixed>  $operation
     * @param  array<array-key, mixed>  $aiTool
     */
    private function description(array $operation, array $aiTool): string
    {
        $toolDescription = $aiTool['description'] ?? null;

        if (is_string($toolDescription)) {
            return $this->appendPitfalls($toolDescription, $operation);
        }

        $summary = SpecReader::nonEmptyString($operation['summary'] ?? null);
        $hint = SpecReader::nonEmptyString($operation['x-ai-hint'] ?? null);

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
            SpecReader::nonEmptyString($operation['description'] ?? null) ?? '',
            $operation,
        );
    }

    /** @param array<array-key, mixed> $operation */
    private function appendPitfalls(string $description, array $operation): string
    {
        $pitfalls = [];

        foreach (SpecReader::listValue($operation['x-ai-pitfalls'] ?? null) as $pitfallValue) {
            $pitfall = SpecReader::objectMap($pitfallValue);
            $text = SpecReader::nonEmptyString($pitfall['text'] ?? null);

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
     * `properties` is an object in JSON Schema, and an empty PHP array encodes as
     * `[]`, so an operation that takes no input would emit `"properties": []` —
     * which a host that validates `inputSchema` rejects. See
     * {@see SpecReader::jsonObject()}.
     *
     * @param  list<mixed>  $pathParameters
     * @param  array<array-key, mixed>  $operation
     * @return array{
     *     type: string,
     *     properties: array<array-key, mixed>|stdClass,
     *     required: list<string>
     * }
     */
    private function inputSchema(SpecReader $spec, array $pathParameters, array $operation): array
    {
        $properties = [];
        $required = [];
        // OpenAPI 3.1 §4.8.9: an operation-level parameter OVERRIDES a path-item
        // one with the same (name, in) pair — it does not sit beside it. Merging
        // both would emit two properties for a single path segment.
        $parameters = $this->overrideParameters(
            $pathParameters,
            SpecReader::listValue($operation['parameters'] ?? null),
            $spec,
        );

        foreach (['path', 'query', 'header'] as $source) {
            foreach ($parameters as $parameterValue) {
                $parameter = $spec->resolveObject($parameterValue);

                if (($parameter['in'] ?? null) !== $source) {
                    continue;
                }

                $name = SpecReader::nonEmptyString($parameter['name'] ?? null);

                if ($name === null) {
                    continue;
                }

                $schema = SpecReader::objectMap($parameter['schema'] ?? null);
                $parameterDescription = SpecReader::nonEmptyString($parameter['description'] ?? null);

                if ($parameterDescription !== null) {
                    $schema['description'] = $parameterDescription;
                }

                $exportedName = $this->insertProperty($properties, $name, $schema, $source);

                if ($source === 'path' || ($parameter['required'] ?? null) === true) {
                    $this->appendRequired($required, $exportedName);
                }
            }
        }

        $requestBody = $spec->resolveObject($operation['requestBody'] ?? null);
        $bodySchema = $this->requestBodySchema($requestBody);
        $body = $this->composedObject($bodySchema);

        // A body that is a string, an array, or an object without declared
        // properties has nothing to spread. Spreading was the only path, so such
        // a body used to vanish from the tool entirely and no agent could send it.
        if ($bodySchema !== [] && $body['properties'] === []) {
            $exportedName = $this->insertProperty($properties, 'body', $bodySchema, 'body');

            if (($requestBody['required'] ?? null) === true) {
                $this->appendRequired($required, $exportedName);
            }

            return [
                'type' => 'object',
                'properties' => SpecReader::jsonObject($properties),
                'required' => $required,
            ];
        }

        $bodyNames = [];

        foreach ($body['properties'] as $name => $schemaValue) {
            $bodyNames[$name] = $this->insertProperty(
                $properties,
                (string) $name,
                SpecReader::objectMap($schemaValue),
                'body',
            );
        }

        foreach ($body['required'] as $requiredName) {
            $this->appendRequired($required, $bodyNames[$requiredName] ?? $requiredName);
        }

        return [
            'type' => 'object',
            'properties' => SpecReader::jsonObject($properties),
            'required' => $required,
        ];
    }

    /**
     * The `properties` and `required` of a body, with every `allOf` branch
     * folded in. A composed body carries no top-level `properties`, so it used
     * to fall into the single nested `body` property and each field of the
     * composition disappeared from the tool. An outer declaration wins over a
     * branch: it is the more specific one.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array{properties: array<array-key, mixed>, required: list<string>}
     */
    private function composedObject(array $schema): array
    {
        $properties = SpecReader::objectMap($schema['properties'] ?? null);
        $required = [];

        foreach (SpecReader::stringList($schema['required'] ?? null) as $name) {
            $this->appendRequired($required, $name);
        }

        foreach (SpecReader::listValue($schema['allOf'] ?? null) as $branchValue) {
            $branch = $this->composedObject(SpecReader::objectMap($branchValue));

            foreach ($branch['properties'] as $name => $branchSchema) {
                if (! array_key_exists($name, $properties)) {
                    $properties[$name] = $branchSchema;
                }
            }

            foreach ($branch['required'] as $name) {
                $this->appendRequired($required, $name);
            }
        }

        return ['properties' => $properties, 'required' => $required];
    }

    /**
     * @param  array<array-key, mixed>  $requestBody
     * @return array<array-key, mixed>
     */
    private function requestBodySchema(array $requestBody): array
    {
        $content = SpecReader::objectMap($requestBody['content'] ?? null);
        $jsonContent = SpecReader::objectMap($content['application/json'] ?? null);

        return SpecReader::objectMap($jsonContent['schema'] ?? null);
    }

    /**
     * @param  array<array-key, array<array-key, mixed>|stdClass>  $properties
     * @param  array<array-key, mixed>  $schema
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
            $description = SpecReader::nonEmptyString($schema['description'] ?? null);
            $schema['description'] = $description !== null ? $description.' '.$origin : $origin;
        }

        // An empty property schema means "any value" and has to stay a JSON
        // object; `[]` would encode as an array and a validator would read it as
        // a tuple.
        $properties[$exportedName] = SpecReader::jsonObject($schema);

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
     * @return list<mixed>
     */
    private function overrideParameters(array $pathParameters, array $operationParameters, SpecReader $spec): array
    {
        $merged = [];
        $anonymous = [];

        foreach ([...$pathParameters, ...$operationParameters] as $parameterValue) {
            $resolved = $spec->resolveObject($parameterValue);
            $name = SpecReader::nonEmptyString($resolved['name'] ?? null);
            $in = SpecReader::nonEmptyString($resolved['in'] ?? null);

            if ($name === null || $in === null) {
                $anonymous[] = $parameterValue;

                continue;
            }

            $merged[$in.':'.$name] = $parameterValue;
        }

        return [...array_values($merged), ...$anonymous];
    }
}
