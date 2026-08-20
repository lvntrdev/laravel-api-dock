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

/**
 * Stable change type slugs:
 * operation_removed, operation_added, response_code_removed, response_code_added,
 * required_parameter_added, optional_parameter_added, parameter_removed,
 * parameter_became_required, parameter_became_optional, request_body_became_required,
 * required_body_property_added, property_added, request_property_removed,
 * response_property_removed, response_required_property_removed,
 * type_narrowed, type_widened, enum_value_removed, enum_value_added,
 * schema_variant_removed, schema_variant_added, schema_constraint_narrowed,
 * schema_constraint_widened, path_parameter_name_changed,
 * component_schema_removed, component_schema_added, auth_requirement_changed,
 * vendor_extension_changed, cosmetic_change.
 */
final class SpecDiffer
{
    /** @var list<string> */
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @var list<SpecChange> */
    private array $changes = [];

    /** @var array<string, true> */
    private array $changeKeys = [];

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function normalisedDocument(array $document): array
    {
        $normalised = OpenApiSnapshot::normalise($document);

        return is_array($normalised) ? $normalised : [];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function diff(array $before, array $after): SpecDiffResult
    {
        $this->changes = [];
        $this->changeKeys = [];

        // Both sides go through the snapshot's normalisation first. A stored
        // snapshot is key-sorted on write while a freshly generated document is
        // not, so comparing them raw reports pure key-order differences as real
        // changes — phantom diffs on every single run.
        $before = self::normalisedDocument($before);
        $after = self::normalisedDocument($after);

        $this->compareAuth(
            $before['security'] ?? null,
            $after['security'] ?? null,
            'security',
            null,
        );
        $this->compareVendorExtensions($before, $after, '', null, false);
        $this->compareDocumentVendorExtensions($before, $after);
        $this->compareComponents(
            $this->associativeArray($before['components'] ?? []),
            $this->associativeArray($after['components'] ?? []),
        );
        $this->compareCosmeticFields($before, $after, ['components', 'paths', 'security'], '', null);
        $this->comparePaths(
            $this->associativeArray($before['paths'] ?? []),
            $this->associativeArray($after['paths'] ?? []),
        );

        $severityOrder = ['breaking' => 0, 'additive' => 1, 'cosmetic' => 2];

        usort(
            $this->changes,
            static fn (SpecChange $left, SpecChange $right): int => [
                $severityOrder[$left->severity],
                $left->path,
                $left->type,
                $left->description,
            ] <=> [
                $severityOrder[$right->severity],
                $right->path,
                $right->type,
                $right->description,
            ],
        );

        return new SpecDiffResult($this->changes);
    }

    /**
     * @param  array<string, mixed>  $beforePaths
     * @param  array<string, mixed>  $afterPaths
     */
    private function comparePaths(array $beforePaths, array $afterPaths): void
    {
        $matches = $this->matchPaths($beforePaths, $afterPaths);
        $matchedAfter = array_fill_keys(array_values($matches), true);

        foreach ($beforePaths as $beforePath => $beforePathItemValue) {
            $beforePathItem = $this->associativeArray($beforePathItemValue);
            $afterPath = $matches[$beforePath] ?? null;

            if ($afterPath === null) {
                foreach ($this->operations($beforePathItem) as $method => $_operation) {
                    $this->addChange(
                        'breaking',
                        $beforePath.'.'.$method,
                        strtoupper($method).' '.$beforePath,
                        'operation_removed',
                        sprintf('%s %s was removed.', strtoupper($method), $beforePath),
                    );
                }

                continue;
            }

            $afterPathItem = $this->associativeArray($afterPaths[$afterPath] ?? []);
            $this->comparePathPair($beforePath, $beforePathItem, $afterPath, $afterPathItem);
        }

        foreach ($afterPaths as $afterPath => $afterPathItemValue) {
            if (isset($matchedAfter[$afterPath])) {
                continue;
            }

            foreach ($this->operations($this->associativeArray($afterPathItemValue)) as $method => $_operation) {
                $this->addChange(
                    'additive',
                    $afterPath.'.'.$method,
                    strtoupper($method).' '.$afterPath,
                    'operation_added',
                    sprintf('%s %s was added.', strtoupper($method), $afterPath),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $beforeComponents
     * @param  array<string, mixed>  $afterComponents
     */
    private function compareComponents(array $beforeComponents, array $afterComponents): void
    {
        $beforeSchemas = $this->associativeArray($beforeComponents['schemas'] ?? []);
        $afterSchemas = $this->associativeArray($afterComponents['schemas'] ?? []);

        foreach ($beforeSchemas as $name => $beforeSchema) {
            $pointer = 'components.schemas.'.$this->pointerSegment($name);

            if (! array_key_exists($name, $afterSchemas)) {
                $this->addChange(
                    'breaking',
                    $pointer,
                    null,
                    'component_schema_removed',
                    sprintf('Component schema %s was removed.', $name),
                );

                continue;
            }

            $this->compareSchema(
                $this->associativeArray($beforeSchema),
                $this->associativeArray($afterSchemas[$name]),
                $pointer,
                null,
                'shared',
            );
        }

        foreach ($afterSchemas as $name => $_afterSchema) {
            if (array_key_exists($name, $beforeSchemas)) {
                continue;
            }

            $this->addChange(
                'additive',
                'components.schemas.'.$this->pointerSegment($name),
                null,
                'component_schema_added',
                sprintf('Component schema %s was added.', $name),
            );
        }

        $this->compareCosmeticFields(
            $beforeComponents,
            $afterComponents,
            ['schemas'],
            'components',
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $beforePathItem
     * @param  array<string, mixed>  $afterPathItem
     */
    private function comparePathPair(
        string $beforePath,
        array $beforePathItem,
        string $afterPath,
        array $afterPathItem,
    ): void {
        $beforeOperations = $this->operations($beforePathItem);
        $afterOperations = $this->operations($afterPathItem);

        $this->compareVendorExtensions(
            $beforePathItem,
            $afterPathItem,
            $beforePath,
            null,
            false,
        );

        foreach ($beforeOperations as $method => $beforeOperation) {
            $operationName = strtoupper($method).' '.$beforePath;
            $pointer = $beforePath.'.'.$method;

            if (! isset($afterOperations[$method])) {
                $this->addChange(
                    'breaking',
                    $pointer,
                    $operationName,
                    'operation_removed',
                    sprintf('%s was removed.', $operationName),
                );

                continue;
            }

            if ($beforePath !== $afterPath) {
                $this->addChange(
                    'breaking',
                    $pointer,
                    $operationName,
                    'path_parameter_name_changed',
                    sprintf('Path parameter names changed from %s to %s.', $beforePath, $afterPath),
                );
            }

            $this->compareOperation(
                $beforeOperation,
                $afterOperations[$method],
                $beforePathItem,
                $afterPathItem,
                $pointer,
                $operationName,
            );
        }

        foreach ($afterOperations as $method => $_afterOperation) {
            if (isset($beforeOperations[$method])) {
                continue;
            }

            $this->addChange(
                'additive',
                $afterPath.'.'.$method,
                strtoupper($method).' '.$afterPath,
                'operation_added',
                sprintf('%s %s was added.', strtoupper($method), $afterPath),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $beforeOperation
     * @param  array<string, mixed>  $afterOperation
     * @param  array<string, mixed>  $beforePathItem
     * @param  array<string, mixed>  $afterPathItem
     */
    private function compareOperation(
        array $beforeOperation,
        array $afterOperation,
        array $beforePathItem,
        array $afterPathItem,
        string $pointer,
        string $operation,
    ): void {
        $this->compareParameters(
            $this->effectiveParameters($beforePathItem, $beforeOperation),
            $this->effectiveParameters($afterPathItem, $afterOperation),
            $pointer.'.parameters',
            $operation,
        );
        $this->compareRequestBody(
            $this->associativeArray($beforeOperation['requestBody'] ?? []),
            $this->associativeArray($afterOperation['requestBody'] ?? []),
            $pointer.'.requestBody',
            $operation,
        );
        $this->compareResponses(
            $this->associativeArray($beforeOperation['responses'] ?? []),
            $this->associativeArray($afterOperation['responses'] ?? []),
            $pointer.'.responses',
            $operation,
        );

        if (array_key_exists('security', $beforeOperation) || array_key_exists('security', $afterOperation)) {
            $this->compareAuth(
                $beforeOperation['security'] ?? null,
                $afterOperation['security'] ?? null,
                $pointer.'.security',
                $operation,
            );
        }

        $this->compareVendorExtensions($beforeOperation, $afterOperation, $pointer, $operation, true);
        $this->compareCosmeticFields(
            $beforeOperation,
            $afterOperation,
            ['parameters', 'requestBody', 'responses', 'security'],
            $pointer,
            $operation,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $beforeParameters
     * @param  list<array<string, mixed>>  $afterParameters
     */
    private function compareParameters(
        array $beforeParameters,
        array $afterParameters,
        string $pointer,
        string $operation,
    ): void {
        $beforeByKey = $this->parametersByKey($beforeParameters);
        $afterByKey = $this->parametersByKey($afterParameters);

        foreach ($beforeByKey as $key => $beforeParameter) {
            $parameterPointer = $pointer.'.'.$this->pointerSegment($key);

            if (! isset($afterByKey[$key])) {
                $this->addChange(
                    'additive',
                    $parameterPointer,
                    $operation,
                    'parameter_removed',
                    sprintf('Request parameter %s was removed.', $key),
                );

                continue;
            }

            $afterParameter = $afterByKey[$key];
            $wasRequired = ($beforeParameter['required'] ?? false) === true;
            $isRequired = ($afterParameter['required'] ?? false) === true;

            if (! $wasRequired && $isRequired) {
                $this->addChange(
                    'breaking',
                    $parameterPointer.'.required',
                    $operation,
                    'parameter_became_required',
                    sprintf('Request parameter %s is now required.', $key),
                );
            } elseif ($wasRequired && ! $isRequired) {
                $this->addChange(
                    'additive',
                    $parameterPointer.'.required',
                    $operation,
                    'parameter_became_optional',
                    sprintf('Request parameter %s is now optional.', $key),
                );
            }

            $this->compareEmbeddedSchemas(
                $beforeParameter,
                $afterParameter,
                $parameterPointer,
                $operation,
                'request',
            );
            $this->compareVendorExtensions($beforeParameter, $afterParameter, $parameterPointer, $operation, true);
        }

        foreach ($afterByKey as $key => $afterParameter) {
            if (isset($beforeByKey[$key])) {
                continue;
            }

            $required = ($afterParameter['required'] ?? false) === true;
            $this->addChange(
                $required ? 'breaking' : 'additive',
                $pointer.'.'.$this->pointerSegment($key),
                $operation,
                $required ? 'required_parameter_added' : 'optional_parameter_added',
                sprintf('%s request parameter %s was added.', $required ? 'Required' : 'Optional', $key),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $beforeBody
     * @param  array<string, mixed>  $afterBody
     */
    private function compareRequestBody(
        array $beforeBody,
        array $afterBody,
        string $pointer,
        string $operation,
    ): void {
        if ($beforeBody === [] && $afterBody === []) {
            return;
        }

        if (($beforeBody['required'] ?? false) !== true && ($afterBody['required'] ?? false) === true) {
            $this->addChange(
                'breaking',
                $pointer.'.required',
                $operation,
                'request_body_became_required',
                'The request body is now required.',
            );
        }

        $this->compareEmbeddedSchemas($beforeBody, $afterBody, $pointer, $operation, 'request');
        $this->compareVendorExtensions($beforeBody, $afterBody, $pointer, $operation, true);
    }

    /**
     * @param  array<string, mixed>  $beforeResponses
     * @param  array<string, mixed>  $afterResponses
     */
    private function compareResponses(
        array $beforeResponses,
        array $afterResponses,
        string $pointer,
        string $operation,
    ): void {
        foreach ($beforeResponses as $code => $beforeResponseValue) {
            $responsePointer = $pointer.'.'.$code;

            if (! array_key_exists($code, $afterResponses)) {
                $this->addChange(
                    'breaking',
                    $responsePointer,
                    $operation,
                    'response_code_removed',
                    sprintf('Response code %s was removed.', $code),
                );

                continue;
            }

            $beforeResponse = $this->associativeArray($beforeResponseValue);
            $afterResponse = $this->associativeArray($afterResponses[$code]);
            $this->compareEmbeddedSchemas(
                $beforeResponse,
                $afterResponse,
                $responsePointer,
                $operation,
                'response',
            );
            $this->compareVendorExtensions($beforeResponse, $afterResponse, $responsePointer, $operation, true);
            $this->compareCosmeticFields(
                $beforeResponse,
                $afterResponse,
                ['content', 'headers'],
                $responsePointer,
                $operation,
            );
        }

        foreach ($afterResponses as $code => $_afterResponse) {
            if (array_key_exists($code, $beforeResponses)) {
                continue;
            }

            $this->addChange(
                'additive',
                $pointer.'.'.$code,
                $operation,
                'response_code_added',
                sprintf('Response code %s was added.', $code),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareEmbeddedSchemas(
        array $before,
        array $after,
        string $pointer,
        string $operation,
        string $context,
    ): void {
        if ($this->looksLikeSchema($before) || $this->looksLikeSchema($after)) {
            $this->compareSchema($before, $after, $pointer, $operation, $context);

            return;
        }

        foreach (array_intersect(array_keys($before), array_keys($after)) as $key) {
            $beforeValue = $before[$key];
            $afterValue = $after[$key];

            if (! is_array($beforeValue) || ! is_array($afterValue)) {
                continue;
            }

            $this->compareEmbeddedSchemas(
                $this->associativeArray($beforeValue),
                $this->associativeArray($afterValue),
                $pointer.'.'.$this->pointerSegment((string) $key),
                $operation,
                $context,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareSchema(
        array $before,
        array $after,
        string $pointer,
        ?string $operation,
        string $context,
    ): void {
        $this->compareTypes($before['type'] ?? null, $after['type'] ?? null, $pointer.'.type', $operation);
        $this->compareEnums($before['enum'] ?? null, $after['enum'] ?? null, $pointer.'.enum', $operation);
        $this->compareConstraints($before, $after, $pointer, $operation);
        $this->compareVendorExtensions($before, $after, $pointer, $operation, false);

        $beforeProperties = $this->associativeArray($before['properties'] ?? []);
        $afterProperties = $this->associativeArray($after['properties'] ?? []);
        $beforeRequired = $this->stringList($before['required'] ?? []);
        $afterRequired = $this->stringList($after['required'] ?? []);

        foreach ($beforeProperties as $name => $beforeProperty) {
            $propertyPointer = $pointer.'.properties.'.$this->pointerSegment($name);

            if (! array_key_exists($name, $afterProperties)) {
                $isBreaking = in_array($context, ['response', 'shared'], true);
                $this->addChange(
                    $isBreaking ? 'breaking' : 'additive',
                    $propertyPointer,
                    $operation,
                    $isBreaking ? 'response_property_removed' : 'request_property_removed',
                    sprintf('%s property %s was removed.', ucfirst($context), $name),
                );

                continue;
            }

            $this->compareSchema(
                $this->associativeArray($beforeProperty),
                $this->associativeArray($afterProperties[$name]),
                $propertyPointer,
                $operation,
                $context,
            );
        }

        foreach ($afterProperties as $name => $_afterProperty) {
            if (array_key_exists($name, $beforeProperties)) {
                continue;
            }

            $required = in_array($name, $afterRequired, true);
            $breaking = in_array($context, ['request', 'shared'], true) && $required;
            $this->addChange(
                $breaking ? 'breaking' : 'additive',
                $pointer.'.properties.'.$this->pointerSegment($name),
                $operation,
                $breaking ? 'required_body_property_added' : 'property_added',
                sprintf('%s property %s was added.', $breaking ? 'Required request' : ucfirst($context), $name),
            );
        }

        foreach (array_diff($afterRequired, $beforeRequired) as $name) {
            if (
                ! array_key_exists($name, $beforeProperties)
                || ! in_array($context, ['request', 'shared'], true)
            ) {
                continue;
            }

            $this->addChange(
                'breaking',
                $pointer.'.required.'.$this->pointerSegment($name),
                $operation,
                'required_body_property_added',
                sprintf('Request property %s is now required.', $name),
            );
        }

        if (in_array($context, ['response', 'shared'], true)) {
            foreach (array_diff($beforeRequired, $afterRequired) as $name) {
                $this->addChange(
                    'breaking',
                    $pointer.'.required.'.$this->pointerSegment($name),
                    $operation,
                    'response_required_property_removed',
                    sprintf('Response property %s is no longer guaranteed.', $name),
                );
            }
        }

        foreach (['items', 'contains', 'additionalProperties'] as $key) {
            if (! is_array($before[$key] ?? null) || ! is_array($after[$key] ?? null)) {
                continue;
            }

            $this->compareSchema(
                $this->associativeArray($before[$key]),
                $this->associativeArray($after[$key]),
                $pointer.'.'.$key,
                $operation,
                $context,
            );
        }

        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $key) {
            $this->compareSchemaVariants(
                $this->arrayList($before[$key] ?? []),
                $this->arrayList($after[$key] ?? []),
                $pointer.'.'.$key,
                $operation,
                $context,
            );
        }

        $this->compareCosmeticFields(
            $before,
            $after,
            [
                'type', 'enum', 'required', 'properties', 'items', 'contains',
                'additionalProperties', 'allOf', 'anyOf', 'oneOf', 'prefixItems',
                'minimum', 'exclusiveMinimum', 'maximum', 'exclusiveMaximum',
                'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern',
            ],
            $pointer,
            $operation,
        );
    }

    private function compareTypes(mixed $before, mixed $after, string $pointer, ?string $operation): void
    {
        if ($before === $after) {
            return;
        }

        $beforeTypes = $this->typeList($before);
        $afterTypes = $this->typeList($after);

        if ($beforeTypes === [] && $afterTypes !== []) {
            $this->addChange(
                'breaking',
                $pointer,
                $operation,
                'type_narrowed',
                'A previously unconstrained schema now restricts its type.',
            );

            return;
        }

        if ($beforeTypes !== [] && $afterTypes === []) {
            $this->addChange(
                'additive',
                $pointer,
                $operation,
                'type_widened',
                'The schema type restriction was removed.',
            );

            return;
        }

        $removed = array_diff($beforeTypes, $afterTypes);
        $added = array_diff($afterTypes, $beforeTypes);

        if ($removed === [] && $added !== []) {
            $this->addChange(
                'additive',
                $pointer,
                $operation,
                'type_widened',
                sprintf('Allowed schema types widened to [%s].', implode(', ', $afterTypes)),
            );

            return;
        }

        if ($beforeTypes === ['integer'] && $afterTypes === ['number']) {
            $this->addChange(
                'additive',
                $pointer,
                $operation,
                'type_widened',
                'Schema type widened from integer to number.',
            );

            return;
        }

        $description = $added === []
            ? sprintf('Allowed schema types narrowed to [%s].', implode(', ', $afterTypes))
            : sprintf(
                'Schema types changed from [%s] to [%s]; compatibility cannot be established safely.',
                implode(', ', $beforeTypes),
                implode(', ', $afterTypes),
            );

        $this->addChange('breaking', $pointer, $operation, 'type_narrowed', $description);
    }

    private function compareEnums(mixed $before, mixed $after, string $pointer, ?string $operation): void
    {
        if (! is_array($before) || ! is_array($after)) {
            return;
        }

        foreach ($before as $value) {
            if (! $this->containsStrict($after, $value)) {
                $this->addChange(
                    'breaking',
                    $pointer,
                    $operation,
                    'enum_value_removed',
                    sprintf('Enum value %s was removed.', $this->displayValue($value)),
                );
            }
        }

        foreach ($after as $value) {
            if (! $this->containsStrict($before, $value)) {
                $this->addChange(
                    'additive',
                    $pointer,
                    $operation,
                    'enum_value_added',
                    sprintf('Enum value %s was added.', $this->displayValue($value)),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareConstraints(array $before, array $after, string $pointer, ?string $operation): void
    {
        $directions = [
            'minimum' => 1,
            'exclusiveMinimum' => 1,
            'minLength' => 1,
            'minItems' => 1,
            'maximum' => -1,
            'exclusiveMaximum' => -1,
            'maxLength' => -1,
            'maxItems' => -1,
        ];

        foreach ($directions as $key => $narrowingDirection) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old === $new || (! is_int($old) && ! is_float($old)) || (! is_int($new) && ! is_float($new))) {
                continue;
            }

            $narrowed = ($new <=> $old) === $narrowingDirection;
            $this->addChange(
                $narrowed ? 'breaking' : 'additive',
                $pointer.'.'.$key,
                $operation,
                $narrowed ? 'schema_constraint_narrowed' : 'schema_constraint_widened',
                sprintf('Schema constraint %s changed from %s to %s.', $key, (string) $old, (string) $new),
            );
        }

        $oldPattern = $before['pattern'] ?? null;
        $newPattern = $after['pattern'] ?? null;

        if ($oldPattern !== $newPattern) {
            $narrowed = $newPattern !== null;
            $this->addChange(
                $narrowed ? 'breaking' : 'additive',
                $pointer.'.pattern',
                $operation,
                $narrowed ? 'schema_constraint_narrowed' : 'schema_constraint_widened',
                $narrowed
                    ? 'The schema pattern changed; compatibility cannot be established safely.'
                    : 'The schema pattern restriction was removed.',
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    private function compareSchemaVariants(
        array $before,
        array $after,
        string $pointer,
        ?string $operation,
        string $context,
    ): void {
        $commonCount = min(count($before), count($after));

        for ($index = 0; $index < $commonCount; $index++) {
            $this->compareSchema($before[$index], $after[$index], $pointer.'.'.$index, $operation, $context);
        }

        if (count($before) > count($after)) {
            $this->addChange(
                'breaking',
                $pointer,
                $operation,
                'schema_variant_removed',
                'One or more allowed schema variants were removed.',
            );
        } elseif (count($after) > count($before)) {
            $this->addChange(
                'additive',
                $pointer,
                $operation,
                'schema_variant_added',
                'One or more allowed schema variants were added.',
            );
        }
    }

    private function compareAuth(
        mixed $before,
        mixed $after,
        string $pointer,
        ?string $operation,
    ): void {
        if ($before === $after) {
            return;
        }

        $this->addChange(
            'breaking',
            $pointer,
            $operation,
            'auth_requirement_changed',
            'Authentication requirements changed.',
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareVendorExtensions(
        array $before,
        array $after,
        string $pointer,
        ?string $operation,
        bool $recursive,
    ): void {
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($keys as $key) {
            $key = (string) $key;
            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;
            $childPointer = $this->joinPointer($pointer, $key);

            if (str_starts_with($key, 'x-')) {
                if ($key === 'x-api-dock-features') {
                    $beforeFeatures = $this->associativeArray($beforeValue);
                    $afterFeatures = $this->associativeArray($afterValue);

                    foreach (['auth', 'scopes'] as $feature) {
                        if (($beforeFeatures[$feature] ?? null) !== ($afterFeatures[$feature] ?? null)) {
                            $this->addChange(
                                'breaking',
                                $childPointer.'.'.$feature,
                                $operation,
                                'auth_requirement_changed',
                                sprintf('API Dock authentication feature %s changed.', $feature),
                            );
                        }
                    }

                    $otherBefore = array_diff_key($beforeFeatures, array_flip(['auth', 'scopes']));
                    $otherAfter = array_diff_key($afterFeatures, array_flip(['auth', 'scopes']));

                    if ($otherBefore !== $otherAfter) {
                        $this->addChange(
                            'cosmetic',
                            $childPointer,
                            $operation,
                            'vendor_extension_changed',
                            sprintf('Vendor extension %s changed.', $key),
                        );
                    }
                } elseif ($beforeValue !== $afterValue) {
                    $this->addChange(
                        'cosmetic',
                        $childPointer,
                        $operation,
                        'vendor_extension_changed',
                        sprintf('Vendor extension %s changed.', $key),
                    );
                }

                continue;
            }

            if ($recursive && is_array($beforeValue) && is_array($afterValue)) {
                $this->compareVendorExtensions(
                    $this->associativeArray($beforeValue),
                    $this->associativeArray($afterValue),
                    $childPointer,
                    $operation,
                    true,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareDocumentVendorExtensions(array $before, array $after): void
    {
        foreach (array_intersect(array_keys($before), array_keys($after)) as $key) {
            if ($key === 'paths' || str_starts_with((string) $key, 'x-')) {
                continue;
            }

            $beforeValue = $before[$key];
            $afterValue = $after[$key];

            if (! is_array($beforeValue) || ! is_array($afterValue)) {
                continue;
            }

            $this->compareVendorExtensions(
                $this->associativeArray($beforeValue),
                $this->associativeArray($afterValue),
                (string) $key,
                null,
                true,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $ignored
     */
    private function compareCosmeticFields(
        array $before,
        array $after,
        array $ignored,
        string $pointer,
        ?string $operation,
    ): void {
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($keys as $key) {
            $key = (string) $key;
            if (in_array($key, $ignored, true) || str_starts_with($key, 'x-')) {
                continue;
            }

            if (($before[$key] ?? null) === ($after[$key] ?? null)) {
                continue;
            }

            $this->addChange(
                'cosmetic',
                $this->joinPointer($pointer, $key),
                $operation,
                'cosmetic_change',
                sprintf('OpenAPI field %s changed.', $key),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $beforePaths
     * @param  array<string, mixed>  $afterPaths
     * @return array<string, string>
     */
    private function matchPaths(array $beforePaths, array $afterPaths): array
    {
        $matches = [];
        $unmatchedBefore = [];
        $unmatchedAfter = [];

        foreach (array_keys($beforePaths) as $path) {
            if (array_key_exists($path, $afterPaths)) {
                $matches[$path] = $path;
            } else {
                $unmatchedBefore[] = $path;
            }
        }

        foreach (array_keys($afterPaths) as $path) {
            if (! array_key_exists($path, $beforePaths)) {
                $unmatchedAfter[] = $path;
            }
        }

        foreach ($unmatchedBefore as $beforePath) {
            $candidates = array_values(array_filter(
                $unmatchedAfter,
                fn (string $afterPath): bool => $this->pathSignature($beforePath) === $this->pathSignature($afterPath),
            ));

            if (count($candidates) !== 1) {
                continue;
            }

            $candidate = $candidates[0];
            $reverseCandidates = array_values(array_filter(
                $unmatchedBefore,
                fn (string $oldPath): bool => $this->pathSignature($oldPath) === $this->pathSignature($candidate),
            ));

            if (count($reverseCandidates) === 1) {
                $matches[$beforePath] = $candidate;
                $unmatchedAfter = array_values(array_diff($unmatchedAfter, [$candidate]));
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $pathItem
     * @return array<string, array<string, mixed>>
     */
    private function operations(array $pathItem): array
    {
        $operations = [];

        foreach (self::HTTP_METHODS as $method) {
            if (is_array($pathItem[$method] ?? null)) {
                $operations[$method] = $this->associativeArray($pathItem[$method]);
            }
        }

        return $operations;
    }

    /**
     * @param  array<string, mixed>  $pathItem
     * @param  array<string, mixed>  $operation
     * @return list<array<string, mixed>>
     */
    private function effectiveParameters(array $pathItem, array $operation): array
    {
        $parameters = [];

        foreach (array_merge(
            $this->arrayList($pathItem['parameters'] ?? []),
            $this->arrayList($operation['parameters'] ?? []),
        ) as $parameter) {
            $key = $this->parameterKey($parameter);
            $parameters[$key] = $parameter;
        }

        return array_values($parameters);
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, array<string, mixed>>
     */
    private function parametersByKey(array $parameters): array
    {
        $byKey = [];

        foreach ($parameters as $parameter) {
            $byKey[$this->parameterKey($parameter)] = $parameter;
        }

        ksort($byKey, SORT_STRING);

        return $byKey;
    }

    /** @param array<string, mixed> $parameter */
    private function parameterKey(array $parameter): string
    {
        if (is_string($parameter['$ref'] ?? null)) {
            return '$ref:'.$parameter['$ref'];
        }

        $location = is_string($parameter['in'] ?? null) ? $parameter['in'] : 'unknown';
        $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : 'unnamed';

        return $location.':'.$name;
    }

    /** @param array<string, mixed> $value */
    private function looksLikeSchema(array $value): bool
    {
        return array_intersect(
            ['type', 'properties', 'enum', 'items', 'allOf', 'anyOf', 'oneOf', '$ref'],
            array_keys($value),
        ) !== [];
    }

    private function pathSignature(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', $path) ?? $path;
    }

    /** @return list<string> */
    private function typeList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        $types = $this->stringList($value);
        sort($types, SORT_STRING);

        return array_values(array_unique($types));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /** @return array<string, mixed> */
    private function associativeArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @param list<mixed> $values */
    private function containsStrict(array $values, mixed $needle): bool
    {
        return in_array($needle, $values, true);
    }

    private function displayValue(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? get_debug_type($value) : $encoded;
    }

    private function pointerSegment(string $value): string
    {
        return str_replace('.', '\\.', $value);
    }

    private function joinPointer(string $pointer, string $segment): string
    {
        return $pointer === '' ? $segment : $pointer.'.'.$this->pointerSegment($segment);
    }

    private function addChange(
        string $severity,
        string $path,
        ?string $operation,
        string $type,
        string $description,
    ): void {
        $key = implode("\0", [$severity, $path, $operation ?? '', $type, $description]);

        if (isset($this->changeKeys[$key])) {
            return;
        }

        $this->changeKeys[$key] = true;
        $this->changes[] = new SpecChange($severity, $path, $operation, $type, $description);
    }
}
