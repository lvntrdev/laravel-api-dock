<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Export;

use JsonException;

final readonly class LlmsTxtExporter
{
    /**
     * @param  array<array-key, mixed>  $document
     *
     * @throws JsonException
     */
    public function export(array $document): string
    {
        $spec = new SpecReader($document);
        $info = SpecReader::objectMap($document['info'] ?? null);
        $title = SpecReader::nonEmptyString($info['title'] ?? null) ?? 'API';
        $version = SpecReader::nonEmptyString($info['version'] ?? null);
        $lines = ['# '.$title.($version !== null ? ' ('.$version.')' : '')];
        $description = SpecReader::nonEmptyString($info['description'] ?? null);

        if ($description !== null) {
            $lines[] = '';
            $lines[] = $description;
        }

        $grouped = [];
        $untagged = [];

        foreach ($spec->operations() as $operationData) {
            $tag = $this->firstTag($operationData['operation']);

            if ($tag === null) {
                $untagged[] = $operationData;

                continue;
            }

            $grouped[$tag] ??= [];
            $grouped[$tag][] = $operationData;
        }

        foreach ($grouped as $tag => $operations) {
            $this->appendGroup($lines, $spec, (string) $tag, $operations);
        }

        if ($untagged !== []) {
            $this->appendGroup($lines, $spec, 'Untagged', $untagged);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $lines
     * @param  list<array{
     *     path: string,
     *     method: string,
     *     operation: array<array-key, mixed>,
     *     pathParameters: list<mixed>
     * }>  $operations
     *
     * @throws JsonException
     */
    private function appendGroup(array &$lines, SpecReader $spec, string $tag, array $operations): void
    {
        $lines[] = '';
        $lines[] = '## '.$tag;

        foreach ($operations as $operationData) {
            $this->appendOperation($lines, $spec, $operationData);
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array{
     *     path: string,
     *     method: string,
     *     operation: array<array-key, mixed>,
     *     pathParameters: list<mixed>
     * }  $operationData
     *
     * @throws JsonException
     */
    private function appendOperation(array &$lines, SpecReader $spec, array $operationData): void
    {
        $operation = $operationData['operation'];
        $lines[] = '';
        $lines[] = '### '.strtoupper($operationData['method']).' '.$operationData['path'];
        $summary = SpecReader::nonEmptyString($operation['summary'] ?? null);

        if ($summary !== null) {
            $lines[] = '';
            $lines[] = $summary;
        }

        $hint = SpecReader::nonEmptyString($operation['x-ai-hint'] ?? null);

        if ($hint !== null) {
            $lines[] = '';
            $lines[] = '**AI hint:** '.$hint;
        }

        $this->appendPitfalls($lines, $operation);

        $features = SpecReader::objectMap($operation['x-api-dock-features'] ?? null);
        $scopes = SpecReader::stringList($features['scopes'] ?? null);
        $lines[] = '';
        $lines[] = $this->authenticationLine($features['auth'] ?? null, $scopes);

        if (($operation['deprecated'] ?? null) === true || ($features['deprecated'] ?? null) === true) {
            $lines[] = '**Deprecated:** Yes';
        }

        $this->appendParameters(
            $lines,
            $spec,
            [...$operationData['pathParameters'], ...SpecReader::listValue($operation['parameters'] ?? null)],
        );
        $this->appendRequestBody($lines, $spec, $operation);
        $this->appendResponses($lines, $spec, $operation);

        if (config('api-dock.ai.include_examples', true) === true) {
            $this->appendExamples($lines, $operation);
        }

        $this->appendChangelog($lines, $operation);
    }

    /**
     * @param  list<string>  $lines
     * @param  array<array-key, mixed>  $operation
     */
    private function appendPitfalls(array &$lines, array $operation): void
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
     * A parameter is resolved before it is read: a `#/components/parameters/…`
     * entry carries its `name` and `in` on the target, and the unresolved node
     * has neither — the row was skipped and an operation whose inputs are all
     * referenced printed "No parameters." to the model.
     *
     * @param  list<string>  $lines
     * @param  list<mixed>  $parameters
     */
    private function appendParameters(array &$lines, SpecReader $spec, array $parameters): void
    {
        $rows = [];

        foreach ($parameters as $parameterValue) {
            $parameter = $spec->resolveObject($parameterValue);
            $name = SpecReader::nonEmptyString($parameter['name'] ?? null);
            $location = SpecReader::nonEmptyString($parameter['in'] ?? null);

            if ($name === null || $location === null) {
                continue;
            }

            $schema = SpecReader::objectMap($parameter['schema'] ?? null);
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
     * @param  array<array-key, mixed>  $operation
     *
     * @throws JsonException
     */
    private function appendRequestBody(array &$lines, SpecReader $spec, array $operation): void
    {
        $requestBody = $spec->resolveObject($operation['requestBody'] ?? null);
        $content = SpecReader::objectMap($requestBody['content'] ?? null);
        $mediaType = SpecReader::objectMap($content['application/json'] ?? null);
        $schema = SpecReader::objectMap($mediaType['schema'] ?? null);

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
     * @param  array<array-key, mixed>  $operation
     *
     * @throws JsonException
     */
    private function appendResponses(array &$lines, SpecReader $spec, array $operation): void
    {
        $lines[] = '';
        $lines[] = '#### Responses';
        $responses = SpecReader::objectMap($operation['responses'] ?? null);

        if ($responses === []) {
            $lines[] = '';
            $lines[] = 'No documented responses.';

            return;
        }

        foreach ($responses as $status => $responseValue) {
            // The response object, its media type and its schema are all
            // resolved: an unresolved node used to be printed verbatim, handing
            // the model a `{"$ref": "…"}` block with no shape in it.
            $response = $spec->resolveObject($responseValue);
            $lines[] = '';
            $lines[] = '##### '.$status;
            $responseDescription = SpecReader::nonEmptyString($response['description'] ?? null);

            if ($responseDescription !== null) {
                $lines[] = '';
                $lines[] = $responseDescription;
            }

            $content = SpecReader::objectMap($response['content'] ?? null);
            $mediaType = SpecReader::objectMap($content['application/json'] ?? null);
            $schema = SpecReader::objectMap($mediaType['schema'] ?? null);
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
     * @param  array<array-key, mixed>  $operation
     *
     * @throws JsonException
     */
    private function appendExamples(array &$lines, array $operation): void
    {
        $examples = SpecReader::listValue($operation['x-ai-examples'] ?? null);

        if ($examples === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '#### Examples';

        foreach ($examples as $exampleValue) {
            $example = SpecReader::objectMap($exampleValue);
            $name = SpecReader::nonEmptyString($example['name'] ?? null);

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
     * @param  array<array-key, mixed>  $operation
     */
    private function appendChangelog(array &$lines, array $operation): void
    {
        $entries = [];

        foreach (SpecReader::listValue($operation['x-api-dock-changelog'] ?? null) as $entryValue) {
            $entry = SpecReader::objectMap($entryValue);
            $date = SpecReader::nonEmptyString($entry['date'] ?? null);
            $summary = SpecReader::nonEmptyString($entry['summary'] ?? null);

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
        // Not forced into an object: an example payload is legitimately a JSON
        // array. A schema reaches this method non-empty, and a nested empty one
        // is already an object from the resolver.
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
        $scheme = SpecReader::nonEmptyString($auth);
        $required = $auth === true || $scheme !== null;

        if (! $required) {
            return '**Authentication:** Not required';
        }

        $line = '**Authentication:** Required'.($scheme !== null ? ' ('.$scheme.')' : '');

        return $scopes === [] ? $line : $line.' (scopes: '.implode(', ', $scopes).')';
    }

    /** @param array<array-key, mixed> $operation */
    private function firstTag(array $operation): ?string
    {
        $tags = SpecReader::stringList($operation['tags'] ?? null);

        return $tags[0] ?? null;
    }

    /** @param array<array-key, mixed> $schema */
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

    /** @return array<array-key, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
