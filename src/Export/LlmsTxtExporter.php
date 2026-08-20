<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Export;

use JsonException;

final readonly class LlmsTxtExporter
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
     *
     * @throws JsonException
     */
    public function export(array $document): string
    {
        $info = $this->stringMap($document['info'] ?? null);
        $title = $this->stringValue($info['title'] ?? null) ?? 'API';
        $version = $this->stringValue($info['version'] ?? null);
        $lines = ['# '.$title.($version !== null ? ' ('.$version.')' : '')];
        $description = $this->stringValue($info['description'] ?? null);

        if ($description !== null) {
            $lines[] = '';
            $lines[] = $description;
        }

        $grouped = [];
        $untagged = [];

        foreach ($this->operations($document) as $operationData) {
            $tag = $this->firstTag($operationData['operation']);

            if ($tag === null) {
                $untagged[] = $operationData;

                continue;
            }

            $grouped[$tag] ??= [];
            $grouped[$tag][] = $operationData;
        }

        foreach ($grouped as $tag => $operations) {
            $this->appendGroup($lines, $tag, $operations);
        }

        if ($untagged !== []) {
            $this->appendGroup($lines, 'Untagged', $untagged);
        }

        return implode("\n", $lines)."\n";
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
     * @param  list<string>  $lines
     * @param  list<array{
     *     path: string,
     *     method: string,
     *     operation: array<string, mixed>,
     *     pathParameters: list<mixed>
     * }>  $operations
     *
     * @throws JsonException
     */
    private function appendGroup(array &$lines, string $tag, array $operations): void
    {
        $lines[] = '';
        $lines[] = '## '.$tag;

        foreach ($operations as $operationData) {
            $this->appendOperation($lines, $operationData);
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array{
     *     path: string,
     *     method: string,
     *     operation: array<string, mixed>,
     *     pathParameters: list<mixed>
     * }  $operationData
     *
     * @throws JsonException
     */
    private function appendOperation(array &$lines, array $operationData): void
    {
        $operation = $operationData['operation'];
        $lines[] = '';
        $lines[] = '### '.strtoupper($operationData['method']).' '.$operationData['path'];
        $summary = $this->stringValue($operation['summary'] ?? null);

        if ($summary !== null) {
            $lines[] = '';
            $lines[] = $summary;
        }

        $hint = $this->stringValue($operation['x-ai-hint'] ?? null);

        if ($hint !== null) {
            $lines[] = '';
            $lines[] = '**AI hint:** '.$hint;
        }

        $this->appendPitfalls($lines, $operation);

        $features = $this->stringMap($operation['x-api-dock-features'] ?? null);
        $scopes = $this->stringList($features['scopes'] ?? null);
        $lines[] = '';
        $lines[] = $this->authenticationLine($features['auth'] ?? null, $scopes);

        if (($operation['deprecated'] ?? null) === true || ($features['deprecated'] ?? null) === true) {
            $lines[] = '**Deprecated:** Yes';
        }

        $this->appendParameters(
            $lines,
            [...$operationData['pathParameters'], ...$this->listValue($operation['parameters'] ?? null)],
        );
        $this->appendRequestBody($lines, $operation);
        $this->appendResponses($lines, $operation);

        if (config('api-dock.ai.include_examples', true) === true) {
            $this->appendExamples($lines, $operation);
        }

        $this->appendChangelog($lines, $operation);
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $operation
     */
    private function appendPitfalls(array &$lines, array $operation): void
    {
        $pitfalls = [];

        foreach ($this->listValue($operation['x-ai-pitfalls'] ?? null) as $pitfallValue) {
            $pitfall = $this->stringMap($pitfallValue);
            $text = $this->stringValue($pitfall['text'] ?? null);

            if ($text !== null) {
                $pitfalls[] = $text;
            }
        }

        if ($pitfalls === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '#### Pitfalls';
        $lines[] = '';

        foreach ($pitfalls as $index => $pitfall) {
            $lines[] = ($index + 1).'. '.$pitfall;
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  list<mixed>  $parameters
     */
    private function appendParameters(array &$lines, array $parameters): void
    {
        $rows = [];

        foreach ($parameters as $parameterValue) {
            $parameter = $this->stringMap($parameterValue);
            $name = $this->stringValue($parameter['name'] ?? null);
            $location = $this->stringValue($parameter['in'] ?? null);

            if ($name === null || $location === null) {
                continue;
            }

            $schema = $this->stringMap($parameter['schema'] ?? null);
            // Keyed by (name, in): OpenAPI 3.1 §4.8.9 says an operation-level
            // parameter REPLACES the path-item one it shares an identity with.
            // The caller passes path-item parameters first, so a later write
            // wins — otherwise the model reads two rows for one input and the
            // stale type and required flag alongside the current ones.
            $rows[$location.':'.$name] = [
                $this->escapeTableCell($name),
                $this->escapeTableCell($location),
                $location === 'path' || ($parameter['required'] ?? null) === true ? 'yes' : 'no',
                $this->escapeTableCell($this->schemaType($schema)),
            ];
        }

        $lines[] = '';
        $lines[] = '#### Parameters';
        $lines[] = '';

        if ($rows === []) {
            $lines[] = 'No parameters.';

            return;
        }

        $lines[] = '| Name | In | Required | Type |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $operation
     *
     * @throws JsonException
     */
    private function appendRequestBody(array &$lines, array $operation): void
    {
        $requestBody = $this->stringMap($operation['requestBody'] ?? null);
        $content = $this->stringMap($requestBody['content'] ?? null);
        $mediaType = $this->stringMap($content['application/json'] ?? null);
        $schema = $this->stringMap($mediaType['schema'] ?? null);

        $lines[] = '';
        $lines[] = '#### Request Body';
        $lines[] = '';

        if ($schema === []) {
            $lines[] = 'No documented JSON request body.';

            return;
        }

        $this->appendJson($lines, $schema);
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $operation
     *
     * @throws JsonException
     */
    private function appendResponses(array &$lines, array $operation): void
    {
        $lines[] = '';
        $lines[] = '#### Responses';
        $responses = $this->responseMap($operation['responses'] ?? null);

        if ($responses === []) {
            $lines[] = '';
            $lines[] = 'No documented responses.';

            return;
        }

        foreach ($responses as $status => $responseValue) {
            $response = $this->stringMap($responseValue);
            $lines[] = '';
            $lines[] = '##### '.$status;
            $responseDescription = $this->stringValue($response['description'] ?? null);

            if ($responseDescription !== null) {
                $lines[] = '';
                $lines[] = $responseDescription;
            }

            $content = $this->stringMap($response['content'] ?? null);
            $mediaType = $this->stringMap($content['application/json'] ?? null);
            $schema = $this->stringMap($mediaType['schema'] ?? null);
            $lines[] = '';

            if ($schema === []) {
                $lines[] = 'No documented JSON response body.';

                continue;
            }

            $this->appendJson($lines, $schema);
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $operation
     *
     * @throws JsonException
     */
    private function appendExamples(array &$lines, array $operation): void
    {
        $examples = $this->listValue($operation['x-ai-examples'] ?? null);

        if ($examples === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '#### Examples';

        foreach ($examples as $exampleValue) {
            $example = $this->stringMap($exampleValue);
            $name = $this->stringValue($example['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $lines[] = '';
            $lines[] = '##### '.$name;
            $lines[] = '';
            $lines[] = '**Request**';
            $lines[] = '';
            $this->appendJson($lines, $this->arrayValue($example['request'] ?? null));
            $lines[] = '';
            $lines[] = '**Response**';
            $lines[] = '';
            $this->appendJson($lines, $this->arrayValue($example['response'] ?? null));
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $operation
     */
    private function appendChangelog(array &$lines, array $operation): void
    {
        $entries = [];

        foreach ($this->listValue($operation['x-api-dock-changelog'] ?? null) as $entryValue) {
            $entry = $this->stringMap($entryValue);
            $date = $this->stringValue($entry['date'] ?? null);
            $summary = $this->stringValue($entry['summary'] ?? null);

            if ($date === null || $summary === null) {
                continue;
            }

            $entries[] = $date.' — '.$summary.(($entry['breaking'] ?? null) === true ? ' **Breaking**' : '');
        }

        if ($entries === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '#### Changelog';
        $lines[] = '';

        foreach ($entries as $entry) {
            $lines[] = '- '.$entry;
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<array-key, mixed>  $value
     *
     * @throws JsonException
     */
    private function appendJson(array &$lines, array $value): void
    {
        $lines[] = '```json';
        $lines[] = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $lines[] = '```';
    }

    /**
     * `x-api-dock-features.auth` carries the guard name FeatureOperationExtension
     * derived from the route middleware ('sanctum', 'auth:api', …) and is null on
     * a public route. Comparing it against `true` therefore reported every
     * authenticated operation as public in the agent-facing bundle; a bool is
     * still accepted so a hand-written fixture keeps working.
     *
     * @param  list<string>  $scopes
     */
    private function authenticationLine(mixed $auth, array $scopes): string
    {
        $scheme = $this->stringValue($auth);
        $required = $auth === true || $scheme !== null;

        if (! $required) {
            return '**Authentication:** Not required';
        }

        $line = '**Authentication:** Required'.($scheme !== null ? ' ('.$scheme.')' : '');

        return $scopes === [] ? $line : $line.' (scopes: '.implode(', ', $scopes).')';
    }

    /** @param array<string, mixed> $operation */
    private function firstTag(array $operation): ?string
    {
        $tags = $this->stringList($operation['tags'] ?? null);

        return $tags[0] ?? null;
    }

    /** @param array<string, mixed> $schema */
    private function schemaType(array $schema): string
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return $type;
        }

        if (is_array($type)) {
            $types = array_values(array_filter($type, is_string(...)));

            if ($types !== []) {
                return implode('|', $types);
            }
        }

        $reference = $schema['$ref'] ?? null;

        if (is_string($reference)) {
            return (string) basename($reference);
        }

        return isset($schema['properties']) ? 'object' : 'unknown';
    }

    private function escapeTableCell(string $value): string
    {
        return str_replace('|', '\\|', str_replace(["\r", "\n"], ' ', $value));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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

    /** @return array<string, mixed> */
    private function responseMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $responses = [];

        foreach ($value as $status => $response) {
            $responses[(string) $status] = $response;
        }

        return $responses;
    }

    /** @return array<array-key, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<mixed> */
    private function listValue(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
