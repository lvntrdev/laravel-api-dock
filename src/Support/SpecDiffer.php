<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Support;

/**
 * Stable change type slugs:
 * operation_removed, operation_added, response_code_removed, response_code_added,
 * required_parameter_added, optional_parameter_added, parameter_removed,
 * parameter_became_required, parameter_became_optional, request_body_became_required,
 * required_body_property_added, property_added, request_property_removed,
 * response_property_removed, response_required_property_removed,
 * type_narrowed, type_widened, enum_value_removed, enum_value_added,
 * schema_variant_removed, schema_variant_added, schema_constraint_narrowed,
 * schema_constraint_widened, schema_reference_changed, schema_block_removed,
 * schema_block_added, path_parameter_name_changed, component_schema_removed,
 * component_schema_added, component_removed, component_added, server_changed,
 * auth_requirement_changed, vendor_extension_changed, cosmetic_change.
 *
 * Severity is direction-bound (see constraintSeverity): a tighter contract
 * breaks whoever sends the request, a looser one breaks whoever reads the
 * response, and a `shared` component is read in both directions so either way
 * counts as breaking.
 */
final class SpecDiffer
{
    /** @var list<string> */
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @var list<string> */
    private const SUBSCHEMA_KEYWORDS = ['items', 'contains', 'additionalProperties'];

    /** @var list<string> */
    private const VARIANT_KEYWORDS = ['allOf', 'anyOf', 'oneOf'];

    /**
     * Schema keywords compared structurally; only what is left over reaches
     * compareCosmeticFields. A keyword may only sit here once both of its
     * sides — including an absent one — are actually classified above.
     *
     * @var list<string>
     */
    private const STRUCTURAL_SCHEMA_KEYS = [
        '$ref', 'type', 'enum', 'required', 'properties',
        'items', 'contains', 'additionalProperties',
        'allOf', 'anyOf', 'oneOf', 'prefixItems',
        'minimum', 'exclusiveMinimum', 'maximum', 'exclusiveMaximum',
        'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern',
    ];

    /** @var list<string> */
    private const ILLUSTRATIVE_KEYS = ['example', 'examples'];

    /** @var list<string> */
    private const SCALAR_TYPES = ['boolean', 'integer', 'null', 'number', 'string'];

    /** @var list<string> */
    private const COMPONENT_SECTIONS = [
        'schemas', 'parameters', 'requestBodies', 'responses', 'headers', 'securitySchemes',
    ];

    /** @var array<string, string> */
    private const COMPONENT_SECTION_LABELS = [
        'schemas' => 'schema',
        'parameters' => 'parameter',
        'requestBodies' => 'request body',
        'responses' => 'response',
        'headers' => 'header',
        'securitySchemes' => 'security scheme',
    ];

    /** @var list<SpecChange> */
    private array $changes = [];

    /** @var array<string, true> */
    private array $changeKeys = [];

    /** @var array<string, mixed> */
    private array $beforeComponents = [];

    /** @var array<string, mixed> */
    private array $afterComponents = [];

    // The document's own `servers`, used as the bottom of the inheritance chain
    // a Path Item or Operation falls back to when it declares no `servers` of
    // its own. `null` when the document has none either.
    private mixed $beforeDocumentServers = null;

    private mixed $afterDocumentServers = null;

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

        $this->beforeComponents = $this->associativeArray($before['components'] ?? []);
        $this->afterComponents = $this->associativeArray($after['components'] ?? []);

        $this->compareAuth(
            $before['security'] ?? null,
            $after['security'] ?? null,
            'security',
            null,
        );
        $this->beforeDocumentServers = $before['servers'] ?? null;
        $this->afterDocumentServers = $after['servers'] ?? null;

        $this->compareServers(
            $this->beforeDocumentServers,
            $this->afterDocumentServers,
            'servers',
            null,
        );
        $this->compareVendorExtensions($before, $after, '', null, false);
        $this->compareDocumentVendorExtensions($before, $after);
        $this->compareComponents($this->beforeComponents, $this->afterComponents);
        $this->compareCosmeticFields($before, $after, ['components', 'paths', 'security', 'servers'], '', null);
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
     * Every section listed in COMPONENT_SECTIONS is walked, not just `schemas`:
     * a changed `securitySchemes` or `parameters` entry used to reach nothing
     * but compareCosmeticFields, which reports `cosmetic` and leaves the
     * `--check` gate green on an authentication change.
     *
     * @param  array<string, mixed>  $beforeComponents
     * @param  array<string, mixed>  $afterComponents
     */
    private function compareComponents(array $beforeComponents, array $afterComponents): void
    {
        foreach (self::COMPONENT_SECTIONS as $section) {
            $beforeEntries = $this->associativeArray($beforeComponents[$section] ?? []);
            $afterEntries = $this->associativeArray($afterComponents[$section] ?? []);
            $label = self::COMPONENT_SECTION_LABELS[$section];

            foreach ($beforeEntries as $name => $beforeEntry) {
                $name = (string) $name;
                $pointer = 'components.'.$section.'.'.$this->pointerSegment($name);

                if (! array_key_exists($name, $afterEntries)) {
                    $this->addChange(
                        'breaking',
                        $pointer,
                        null,
                        $section === 'schemas' ? 'component_schema_removed' : 'component_removed',
                        sprintf('Component %s %s was removed.', $label, $name),
                    );

                    continue;
                }

                $this->compareComponentEntry(
                    $section,
                    $name,
                    $this->associativeArray($beforeEntry),
                    $this->associativeArray($afterEntries[$name]),
                    $pointer,
                );
            }

            foreach ($afterEntries as $name => $_afterEntry) {
                $name = (string) $name;

                if (array_key_exists($name, $beforeEntries)) {
                    continue;
                }

                $this->addChange(
                    'additive',
                    'components.'.$section.'.'.$this->pointerSegment($name),
                    null,
                    $section === 'schemas' ? 'component_schema_added' : 'component_added',
                    sprintf('Component %s %s was added.', $label, $name),
                );
            }
        }

        $this->compareCosmeticFields(
            $beforeComponents,
            $afterComponents,
            self::COMPONENT_SECTIONS,
            'components',
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareComponentEntry(
        string $section,
        string $name,
        array $before,
        array $after,
        string $pointer,
    ): void {
        if ($section === 'schemas') {
            $this->compareSchema($before, $after, $pointer, null, 'shared');

            return;
        }

        if ($section === 'securitySchemes') {
            // `description` is prose, not part of the auth contract — comparing the
            // whole array raw reported a copy-edit as `auth_requirement_changed`,
            // stopping `sync --check` on a rename that changed no behaviour.
            $beforeContract = array_diff_key($before, ['description' => true]);
            $afterContract = array_diff_key($after, ['description' => true]);

            if ($beforeContract !== $afterContract) {
                $this->addChange(
                    'breaking',
                    $pointer,
                    null,
                    'auth_requirement_changed',
                    sprintf('Security scheme %s changed.', $name),
                );

                return;
            }

            if (($before['description'] ?? null) !== ($after['description'] ?? null)) {
                $this->addChange(
                    'cosmetic',
                    $pointer.'.description',
                    null,
                    'cosmetic_change',
                    sprintf('Security scheme %s description changed.', $name),
                );
            }

            return;
        }

        if ($section === 'parameters') {
            $this->compareParameters([$before], [$after], $pointer, null, 'shared');

            return;
        }

        if ($section === 'requestBodies') {
            // A request body reached through a `$ref` is still a request body:
            // sending it through the generic schema walk ignored the scalar
            // `required` flag outright, so flipping a shared body from optional
            // to required produced no change at all.
            $this->compareRequestBody($before, $after, $pointer, null, 'shared');

            return;
        }

        $this->compareEmbeddedSchemas($before, $after, $pointer, null, 'shared');
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
        // A Path Item may override the document's `servers`; only the root list
        // used to be examined, so moving every operation under a path to a new
        // base URL was reported as no change at all. Neither side declaring an
        // override here means both inherit the document's list verbatim — that
        // change is already reported once at the document level, so comparing
        // it again here would duplicate it under this path's pointer too.
        if (array_key_exists('servers', $beforePathItem) || array_key_exists('servers', $afterPathItem)) {
            $this->compareServers(
                $beforePathItem['servers'] ?? $this->beforeDocumentServers,
                $afterPathItem['servers'] ?? $this->afterDocumentServers,
                $beforePath.'.servers',
                null,
            );
        }

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
            $this->effectiveParameters($beforePathItem, $beforeOperation, $this->beforeComponents),
            $this->effectiveParameters($afterPathItem, $afterOperation, $this->afterComponents),
            $pointer.'.parameters',
            $operation,
            'request',
        );
        $this->compareRequestBody(
            $this->associativeArray($beforeOperation['requestBody'] ?? []),
            $this->associativeArray($afterOperation['requestBody'] ?? []),
            $pointer.'.requestBody',
            $operation,
            'request',
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

        // An Operation-level `servers` override outranks both the Path Item and
        // the document list, so it decides the effective endpoint; unclassified
        // it fell through to compareCosmeticFields and read as `cosmetic`.
        // Neither side declaring an override here means both inherit whatever
        // the Path Item (or document) resolves to, which is reported once at
        // that ancestor's own pointer — comparing it again here would
        // duplicate it under this operation's pointer too.
        if (array_key_exists('servers', $beforeOperation) || array_key_exists('servers', $afterOperation)) {
            $this->compareServers(
                $beforeOperation['servers'] ?? $beforePathItem['servers'] ?? $this->beforeDocumentServers,
                $afterOperation['servers'] ?? $afterPathItem['servers'] ?? $this->afterDocumentServers,
                $pointer.'.servers',
                $operation,
            );
        }

        $this->compareVendorExtensions($beforeOperation, $afterOperation, $pointer, $operation, true);
        $this->compareCosmeticFields(
            $beforeOperation,
            $afterOperation,
            ['parameters', 'requestBody', 'responses', 'security', 'servers'],
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
        ?string $operation,
        string $context,
    ): void {
        $beforeByKey = $this->parametersByKey($beforeParameters);
        $afterByKey = $this->parametersByKey($afterParameters);

        foreach ($beforeByKey as $key => $beforeParameter) {
            $parameterPointer = $pointer.'.'.$this->pointerSegment($key);

            if (! isset($afterByKey[$key])) {
                // A path parameter is not optional scaffolding: dropping it
                // leaves a `{template}` variable with nothing to bind, so the
                // document itself stops being valid.
                $isPathParameter = ($beforeParameter['in'] ?? null) === 'path';

                $this->addChange(
                    $isPathParameter ? 'breaking' : 'additive',
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
                $context,
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
        ?string $operation,
        string $context,
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

        $this->compareEmbeddedSchemas($beforeBody, $afterBody, $pointer, $operation, $context);
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
            // `content` and `headers` are structurally classified by
            // compareEmbeddedSchemas above, down to a block that exists on one
            // side only; repeating them here would only add a duplicate
            // `cosmetic` entry on top of the real change.
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
     * Walks the union of both sides' keys. Intersecting them hid every
     * one-sided block: a response that lost its `content`, or a media type
     * renamed from `application/json` to something else, produced no change.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareEmbeddedSchemas(
        array $before,
        array $after,
        string $pointer,
        ?string $operation,
        string $context,
        bool $alternatives = false,
    ): void {
        if ($this->looksLikeSchema($before) || $this->looksLikeSchema($after)) {
            $this->compareSchema($before, $after, $pointer, $operation, $context);

            return;
        }

        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($keys as $key) {
            $key = (string) $key;

            if (str_starts_with($key, 'x-')) {
                // Vendor extensions are not part of the contract, and
                // compareVendorExtensions already walks them separately and reports
                // them as cosmetic. Without this guard, one appearing on only one
                // side read as a structural block gained or lost here too — a
                // duplicate, wrongly-severed report on top of the correct one.
                continue;
            }

            $childPointer = $this->joinPointer($pointer, $key);
            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;

            if (in_array($key, self::ILLUSTRATIVE_KEYS, true)) {
                // Example payloads are not the contract, and descending into
                // one makes example data read as a schema: {"type": "premium"}
                // would otherwise be classified as a type declaration.
                if ($beforeValue !== $afterValue) {
                    $this->addChange(
                        'cosmetic',
                        $childPointer,
                        $operation,
                        'cosmetic_change',
                        sprintf('OpenAPI field %s changed.', $key),
                    );
                }

                continue;
            }

            if (is_array($beforeValue) && is_array($afterValue)) {
                $this->compareEmbeddedSchemas(
                    $this->associativeArray($beforeValue),
                    $this->associativeArray($afterValue),
                    $childPointer,
                    $operation,
                    $context,
                    $key === 'content',
                );

                continue;
            }

            if (! is_array($beforeValue) && ! is_array($afterValue)) {
                continue;
            }

            // Every other block here is a named, independent field: gaining one
            // adds a fresh constraint (breaking for a request, additive for a
            // response), same as any other narrowing. `content`'s own children on
            // the REQUEST side are different — they are media-type ALTERNATIVES a
            // client may choose from, not an independent field, so accepting one
            // more format takes nothing away from an existing caller and dropping
            // one does. The response side already gets this right under the plain
            // rule: the server offering an extra representation is additive, and
            // taking one away is breaking, exactly like any other response block.
            $removed = is_array($beforeValue);
            $requestAlternative = $alternatives && $context === 'request';
            $this->addChange(
                $this->constraintSeverity($requestAlternative ? $removed : ! $removed, $context),
                $childPointer,
                $operation,
                $removed ? 'schema_block_removed' : 'schema_block_added',
                sprintf('%s block %s was %s.', ucfirst($context), $key, $removed ? 'removed' : 'added'),
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
        $this->compareSchemaReference($before, $after, $pointer, $operation);
        $this->compareTypes($before['type'] ?? null, $after['type'] ?? null, $pointer.'.type', $operation, $context);
        $this->compareEnums($before, $after, $pointer, $operation, $context);
        $this->compareConstraints($before, $after, $pointer, $operation, $context);
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

        foreach (self::SUBSCHEMA_KEYWORDS as $key) {
            $this->compareSchemaKeyword($before, $after, $key, $pointer, $operation, $context);
        }

        foreach (self::VARIANT_KEYWORDS as $key) {
            $this->compareSchemaVariants(
                $this->arrayList($before[$key] ?? []),
                $this->arrayList($after[$key] ?? []),
                $key,
                $pointer.'.'.$key,
                $operation,
                $context,
            );
        }

        // `prefixItems` is tuple validation: position IS the identity, so it
        // keeps the positional alignment the set-valued keywords lose.
        $this->comparePositionalSchemas(
            $this->arrayList($before['prefixItems'] ?? []),
            $this->arrayList($after['prefixItems'] ?? []),
            $pointer.'.prefixItems',
            $operation,
            $context,
        );

        $this->compareCosmeticFields(
            $before,
            $after,
            self::STRUCTURAL_SCHEMA_KEYS,
            $pointer,
            $operation,
        );
    }

    /**
     * A swapped reference (`.../UserFull` -> `.../UserSlim`) points at a
     * different contract; without this it only reached compareCosmeticFields
     * and was reported as `cosmetic`.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareSchemaReference(
        array $before,
        array $after,
        string $pointer,
        ?string $operation,
    ): void {
        $beforeReference = is_string($before['$ref'] ?? null) ? $before['$ref'] : null;
        $afterReference = is_string($after['$ref'] ?? null) ? $after['$ref'] : null;

        if ($beforeReference === $afterReference) {
            return;
        }

        $description = match (true) {
            ! is_string($beforeReference) => sprintf('The schema now references %s.', (string) $afterReference),
            ! is_string($afterReference) => sprintf('The schema no longer references %s.', $beforeReference),
            default => sprintf(
                'The schema reference changed from %s to %s.',
                $beforeReference,
                $afterReference,
            ),
        };

        $this->addChange(
            'breaking',
            $pointer.'.$ref',
            $operation,
            'schema_reference_changed',
            $description,
        );
    }

    /**
     * One route for every subschema keyword whose two sides may not both be
     * present. The absent side used to fall straight through to
     * compareCosmeticFields, which ignored the keyword outright: a removed
     * `items` or an `additionalProperties: true` -> `false` flip produced no
     * change at all.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareSchemaKeyword(
        array $before,
        array $after,
        string $keyword,
        string $pointer,
        ?string $operation,
        string $context,
    ): void {
        $hasBefore = array_key_exists($keyword, $before);
        $hasAfter = array_key_exists($keyword, $after);

        if (! $hasBefore && ! $hasAfter) {
            return;
        }

        $beforeValue = $hasBefore ? $before[$keyword] : null;
        $afterValue = $hasAfter ? $after[$keyword] : null;
        $keywordPointer = $pointer.'.'.$keyword;

        if (is_array($beforeValue) && is_array($afterValue)) {
            $this->compareSchema(
                $this->associativeArray($beforeValue),
                $this->associativeArray($afterValue),
                $keywordPointer,
                $operation,
                $context,
            );

            return;
        }

        $beforeRestriction = $this->subSchemaRestriction($beforeValue, $hasBefore);
        $afterRestriction = $this->subSchemaRestriction($afterValue, $hasAfter);

        if ($beforeRestriction === $afterRestriction) {
            return;
        }

        $narrowed = $afterRestriction > $beforeRestriction;

        $this->addChange(
            $this->constraintSeverity($narrowed, $context),
            $keywordPointer,
            $operation,
            $narrowed ? 'schema_constraint_narrowed' : 'schema_constraint_widened',
            sprintf(
                'Schema keyword %s changed from %s to %s.',
                $keyword,
                $this->schemaKeywordSummary($beforeValue, $hasBefore),
                $this->schemaKeywordSummary($afterValue, $hasAfter),
            ),
        );
    }

    /**
     * How tight a subschema keyword is on one side: 0 lets anything through,
     * 1 constrains it to a schema, 2 forbids it outright. Absent and `true`
     * mean the same thing for each keyword this runs over, which is why
     * `additionalProperties: absent` -> `true` is correctly no change while
     * `true` -> `false` is.
     */
    private function subSchemaRestriction(mixed $value, bool $present): int
    {
        if (! $present || $value === true) {
            return 0;
        }

        if ($value === false) {
            return 2;
        }

        return 1;
    }

    private function schemaKeywordSummary(mixed $value, bool $present): string
    {
        if (! $present) {
            return 'absent';
        }

        return is_array($value) ? 'a schema' : $this->displayValue($value);
    }

    private function compareTypes(
        mixed $before,
        mixed $after,
        string $pointer,
        ?string $operation,
        string $context,
    ): void {
        if ($before === $after) {
            return;
        }

        $beforeTypes = $this->typeList($before);
        $afterTypes = $this->typeList($after);

        // `type: 'string'` and `type: ['string']` are the same declaration;
        // only the spelling differs.
        if ($beforeTypes === $afterTypes) {
            return;
        }

        if ($beforeTypes === []) {
            $this->addChange(
                $this->constraintSeverity(true, $context),
                $pointer,
                $operation,
                'type_narrowed',
                'A previously unconstrained schema now restricts its type.',
            );

            return;
        }

        if ($afterTypes === []) {
            $this->addChange(
                $this->constraintSeverity(false, $context),
                $pointer,
                $operation,
                'type_widened',
                'The schema type restriction was removed.',
            );

            return;
        }

        $removed = array_diff($beforeTypes, $afterTypes);
        $added = array_diff($afterTypes, $beforeTypes);

        if ($removed === []) {
            $this->addChange(
                $this->constraintSeverity(false, $context),
                $pointer,
                $operation,
                'type_widened',
                sprintf('Allowed schema types widened to [%s].', implode(', ', $afterTypes)),
            );

            return;
        }

        if ($beforeTypes === ['integer'] && $afterTypes === ['number']) {
            $this->addChange(
                $this->constraintSeverity(false, $context),
                $pointer,
                $operation,
                'type_widened',
                'Schema type widened from integer to number.',
            );

            return;
        }

        if ($added === []) {
            $this->addChange(
                $this->constraintSeverity(true, $context),
                $pointer,
                $operation,
                'type_narrowed',
                sprintf('Allowed schema types narrowed to [%s].', implode(', ', $afterTypes)),
            );

            return;
        }

        // Types moved in both directions at once: neither side is a superset of
        // the other, so no direction makes this safe.
        $this->addChange(
            'breaking',
            $pointer,
            $operation,
            'type_narrowed',
            sprintf(
                'Schema types changed from [%s] to [%s]; compatibility cannot be established safely.',
                implode(', ', $beforeTypes),
                implode(', ', $afterTypes),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareEnums(
        array $before,
        array $after,
        string $pointer,
        ?string $operation,
        string $context,
    ): void {
        $beforeValues = $before['enum'] ?? null;
        $afterValues = $after['enum'] ?? null;
        $hasBefore = is_array($beforeValues);
        $hasAfter = is_array($afterValues);
        $enumPointer = $pointer.'.enum';

        if (! $hasBefore && ! $hasAfter) {
            return;
        }

        // An enum that appears on one side only closes (or opens) the whole
        // value space; comparing member by member would have skipped it.
        if (! $hasBefore || ! $hasAfter) {
            $this->addChange(
                $this->constraintSeverity($hasAfter, $context),
                $enumPointer,
                $operation,
                $hasAfter ? 'schema_constraint_narrowed' : 'schema_constraint_widened',
                $hasAfter
                    ? 'The schema now restricts its value to an enum.'
                    : 'The schema enum restriction was removed.',
            );

            return;
        }

        foreach ($beforeValues as $value) {
            if (! $this->containsStrict($afterValues, $value)) {
                $this->addChange(
                    $this->constraintSeverity(true, $context),
                    $enumPointer,
                    $operation,
                    'enum_value_removed',
                    sprintf('Enum value %s was removed.', $this->displayValue($value)),
                );
            }
        }

        foreach ($afterValues as $value) {
            if (! $this->containsStrict($beforeValues, $value)) {
                $this->addChange(
                    $this->constraintSeverity(false, $context),
                    $enumPointer,
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
    private function compareConstraints(
        array $before,
        array $after,
        string $pointer,
        ?string $operation,
        string $context,
    ): void {
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
            $hasBefore = array_key_exists($key, $before);
            $hasAfter = array_key_exists($key, $after);

            if (! $hasBefore && ! $hasAfter) {
                continue;
            }

            $old = $hasBefore ? $before[$key] : null;
            $new = $hasAfter ? $after[$key] : null;

            if ($hasBefore && $hasAfter && $old === $new) {
                continue;
            }

            $narrowed = match (true) {
                // A bound that only exists on one side is the bound appearing
                // or disappearing; both were silent before.
                ! $hasBefore => true,
                ! $hasAfter => false,
                // OpenAPI 3.0 spells exclusiveMinimum/exclusiveMaximum as a
                // boolean modifier on minimum/maximum, 3.1 as the bound itself.
                // In the boolean form `true` is always the tighter side.
                is_bool($old) || is_bool($new) => $new === true,
                (is_int($old) || is_float($old)) && (is_int($new) || is_float($new)) => ($new <=> $old) === $narrowingDirection,
                default => true,
            };

            $this->addChange(
                $this->constraintSeverity($narrowed, $context),
                $pointer.'.'.$key,
                $operation,
                $narrowed ? 'schema_constraint_narrowed' : 'schema_constraint_widened',
                sprintf(
                    'Schema constraint %s changed from %s to %s.',
                    $key,
                    $hasBefore ? $this->displayValue($old) : 'absent',
                    $hasAfter ? $this->displayValue($new) : 'absent',
                ),
            );
        }

        $oldPattern = $before['pattern'] ?? null;
        $newPattern = $after['pattern'] ?? null;

        if ($oldPattern === $newPattern) {
            return;
        }

        if (is_string($oldPattern) && is_string($newPattern)) {
            $this->addChange(
                'breaking',
                $pointer.'.pattern',
                $operation,
                'schema_constraint_narrowed',
                'The schema pattern changed; compatibility cannot be established safely.',
            );

            return;
        }

        $narrowed = $newPattern !== null;
        $this->addChange(
            $this->constraintSeverity($narrowed, $context),
            $pointer.'.pattern',
            $operation,
            $narrowed ? 'schema_constraint_narrowed' : 'schema_constraint_widened',
            $narrowed
                ? 'A schema pattern restriction was added.'
                : 'The schema pattern restriction was removed.',
        );
    }

    /**
     * `allOf`/`anyOf`/`oneOf` are sets, not tuples. Aligning them by position
     * made a reordered list look like every variant changing and a genuinely
     * swapped variant look like a harmless edit, so members are matched by
     * signature instead — identical ones first, near ones after.
     *
     * Severity follows the keyword, not the direction of the edit: `allOf` is a
     * conjunction, so a branch added to it narrows what validates, while
     * `anyOf`/`oneOf` are disjunctions, where a branch added widens it. Which
     * of the two breaks a consumer is then the ordinary direction question that
     * constraintSeverity answers.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    private function compareSchemaVariants(
        array $before,
        array $after,
        string $keyword,
        string $pointer,
        ?string $operation,
        string $context,
    ): void {
        if ($before === [] && $after === []) {
            return;
        }

        /** @var array<int, int> $pairs */
        $pairs = [];
        /** @var array<int, true> $matchedAfter */
        $matchedAfter = [];

        // Identical variants pair off first. Two branches that share a shape and
        // differ only in a nested constraint used to collide on one signature
        // and be paired by list position, so reordering them compared the wrong
        // pair and invented breaking changes out of a no-op edit.
        $this->matchVariants($before, $after, $pairs, $matchedAfter, true);
        // Whatever is left is matched loosely, so an edited variant is still
        // diffed against its counterpart instead of reported as a swap.
        $this->matchVariants($before, $after, $pairs, $matchedAfter, false);
        // A discriminator tag that widens or narrows (its single-valued enum
        // gains or loses a member) changes the loose signature above too, since
        // that signature folds the tag in — so the branch still looks swapped
        // rather than edited. Retry the leftovers by shape alone.
        $this->matchVariantsByShape($before, $after, $pairs, $matchedAfter);

        $conjunction = $keyword === 'allOf';
        // Dropping the keyword entirely is not the same edit as dropping one of
        // several remaining branches. A partial anyOf/oneOf removal narrows what
        // still validates (fewer shapes accepted); removing the LAST branch drops
        // the keyword altogether, so the schema is unconstrained by it and now
        // accepts anything — a widening, the same direction as removing an allOf
        // branch already is, regardless of which keyword this was.
        $emptied = $after === [];
        // The keyword itself is new — previously nothing here constrained the
        // schema, and now at least one branch must match. That is a narrowing
        // regardless of which keyword introduced it, the mirror image of
        // $emptied dropping the keyword and its constraint altogether.
        $introduced = $before === [];

        foreach ($before as $beforeIndex => $beforeVariant) {
            $afterIndex = $pairs[$beforeIndex] ?? null;

            if ($afterIndex === null) {
                // `oneOf` requires EXACTLY one branch to match. Two scalar-typed
                // branches only compete for the same value when their types can
                // overlap (`integer` also satisfies `number`), so dropping one of
                // them can turn values that used to fail the "exactly one" rule
                // by double-matching into single matches — a widening, not the
                // narrowing a disjoint branch removal is. Structured (object/
                // array) branches are ordinarily told apart by a discriminator
                // property instead, which this can't see, so it leaves those alone.
                $overlapsRemaining = $keyword === 'oneOf'
                    && ! $emptied
                    && $this->oneOfBranchOverlapsRemaining($beforeVariant, $after);

                $this->addChange(
                    $emptied || $overlapsRemaining
                        ? $this->constraintSeverity(false, $context)
                        : $this->constraintSeverity(! $conjunction, $context),
                    $pointer,
                    $operation,
                    'schema_variant_removed',
                    'One or more allowed schema variants were removed.',
                );

                continue;
            }

            $this->compareSchema(
                $beforeVariant,
                $this->associativeArray($after[$afterIndex] ?? []),
                $pointer.'.'.$beforeIndex,
                $operation,
                $context,
            );
        }

        foreach ($after as $index => $_variant) {
            if (isset($matchedAfter[$index])) {
                continue;
            }

            $this->addChange(
                $introduced
                    ? $this->constraintSeverity(true, $context)
                    : $this->constraintSeverity($conjunction, $context),
                $pointer,
                $operation,
                'schema_variant_added',
                'One or more allowed schema variants were added.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $branch
     * @param  list<array<string, mixed>>  $remaining
     */
    private function oneOfBranchOverlapsRemaining(array $branch, array $remaining): bool
    {
        $branchTypes = $this->typeList($branch['type'] ?? null);

        if ($branchTypes === [] || array_diff($branchTypes, self::SCALAR_TYPES) !== []) {
            return false;
        }

        foreach ($remaining as $other) {
            $otherTypes = $this->typeList($other['type'] ?? null);

            foreach ($branchTypes as $type) {
                $typeOverlaps = in_array($type, $otherTypes, true)
                    || ($type === 'integer' && in_array('number', $otherTypes, true))
                    || ($type === 'number' && in_array('integer', $otherTypes, true));

                if ($typeOverlaps && ! $this->scalarEnumsAreDisjoint($branch, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A same-typed pair sharing a scalar `type` still can't both match the same
     * value when their `enum` lists don't intersect — the common "string
     * discriminator" oneOf, where each branch pins a different tag. Either side
     * missing an `enum` leaves overlap unprovable, so this stays conservative.
     *
     * @param  array<string, mixed>  $branch
     * @param  array<string, mixed>  $other
     */
    private function scalarEnumsAreDisjoint(array $branch, array $other): bool
    {
        if (! is_array($branch['enum'] ?? null) || ! is_array($other['enum'] ?? null)) {
            return false;
        }

        return array_intersect($branch['enum'], $other['enum']) === [];
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @param  array<int, int>  $pairs
     * @param  array<int, true>  $matchedAfter
     */
    private function matchVariants(
        array $before,
        array $after,
        array &$pairs,
        array &$matchedAfter,
        bool $exact,
    ): void {
        /** @var array<string, list<int>> $candidates */
        $candidates = [];

        foreach ($after as $index => $variant) {
            if (isset($matchedAfter[$index])) {
                continue;
            }

            $candidates[$this->variantKey($variant, $exact)][] = $index;
        }

        foreach ($before as $beforeIndex => $variant) {
            if (isset($pairs[$beforeIndex])) {
                continue;
            }

            $key = $this->variantKey($variant, $exact);

            if (($candidates[$key] ?? []) === []) {
                continue;
            }

            $afterIndex = (int) array_shift($candidates[$key]);
            $pairs[$beforeIndex] = $afterIndex;
            $matchedAfter[$afterIndex] = true;
        }
    }

    /** @param array<string, mixed> $variant */
    private function variantKey(array $variant, bool $exact): string
    {
        // Both documents are key-sorted by OpenApiSnapshot::normalise before the
        // walk starts, so encoding is a stable structural identity here.
        return $exact
            ? 'exact:'.$this->displayValue($variant)
            : $this->variantSignature($variant);
    }

    /**
     * Shape-only matching without the overlap check below would also pair a
     * branch genuinely swapped for a different one — same properties, an
     * unrelated tag value — as an in-place edit, which is exactly the
     * collision the tag-aware signature above exists to avoid. So a shape
     * match here is only taken when every commonly-tagged property's allowed
     * values still overlap between the two sides.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @param  array<int, int>  $pairs
     * @param  array<int, true>  $matchedAfter
     */
    private function matchVariantsByShape(
        array $before,
        array $after,
        array &$pairs,
        array &$matchedAfter,
    ): void {
        /** @var array<string, list<int>> $candidates */
        $candidates = [];

        foreach ($after as $index => $variant) {
            if (isset($matchedAfter[$index])) {
                continue;
            }

            $candidates[$this->variantSignature($variant, includeTag: false)][] = $index;
        }

        foreach ($before as $beforeIndex => $variant) {
            if (isset($pairs[$beforeIndex])) {
                continue;
            }

            $key = $this->variantSignature($variant, includeTag: false);

            foreach ($candidates[$key] ?? [] as $poolPosition => $afterIndex) {
                if (! $this->variantTagsOverlap($variant, $this->associativeArray($after[$afterIndex] ?? []))) {
                    continue;
                }

                unset($candidates[$key][$poolPosition]);
                $pairs[$beforeIndex] = $afterIndex;
                $matchedAfter[$afterIndex] = true;

                break;
            }
        }
    }

    /**
     * Whether two same-shape variants could be the same discriminated-union
     * branch edited in place, rather than swapped for a different one: every
     * property tagged on BOTH sides must share at least one allowed value, so
     * a singleton enum widened to include it (or a multi-value enum narrowed
     * down to it) still counts as the same branch. A property untagged on
     * either side never blocks the match — it is ordinary data, not identity.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function variantTagsOverlap(array $before, array $after): bool
    {
        $beforeProperties = $this->associativeArray($before['properties'] ?? []);
        $afterProperties = $this->associativeArray($after['properties'] ?? []);

        foreach ($beforeProperties as $name => $property) {
            if (! array_key_exists($name, $afterProperties)) {
                continue;
            }

            $beforeValues = $this->variantTagValues($this->associativeArray($property));
            $afterValues = $this->variantTagValues($this->associativeArray($afterProperties[$name]));

            if ($beforeValues === [] || $afterValues === []) {
                continue;
            }

            if (array_intersect($beforeValues, $afterValues) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * A property's allowed tag values as display strings, or an empty list
     * when it carries no `const`/`enum` at all — ordinary data rather than a
     * discriminator, so it never participates in the overlap check above.
     *
     * @param  array<string, mixed>  $property
     * @return list<string>
     */
    private function variantTagValues(array $property): array
    {
        if (array_key_exists('const', $property)) {
            return [$this->displayValue($property['const'])];
        }

        $enum = $property['enum'] ?? null;

        if (! is_array($enum)) {
            return [];
        }

        return array_map(fn (mixed $value): string => $this->displayValue($value), array_values($enum));
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    private function comparePositionalSchemas(
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

    /**
     * The loose identity of a variant: enough to recognise the same branch
     * across an edit, not so much that an edit stops being recognised. Type and
     * property names alone collide on every branch of a discriminated union, so
     * each property's tag value joins them.
     *
     * @param  array<string, mixed>  $variant
     */
    private function variantSignature(array $variant, bool $includeTag = true): string
    {
        if (is_string($variant['$ref'] ?? null)) {
            return 'ref:'.$variant['$ref'];
        }

        $properties = $this->associativeArray($variant['properties'] ?? []);
        ksort($properties, SORT_STRING);

        $shape = [];

        foreach ($properties as $name => $property) {
            $tag = $includeTag ? $this->variantTagSignature($this->associativeArray($property)) : '';
            $shape[] = (string) $name.$tag;
        }

        $discriminator = $this->associativeArray($variant['discriminator'] ?? [])['propertyName'] ?? null;

        return 'shape:'
            .implode(',', $this->typeList($variant['type'] ?? null)).'|'
            .implode(',', $shape).'|'
            .(is_string($discriminator) ? $discriminator : '');
    }

    /**
     * A discriminated union's branches differ only in the single constant their
     * tag property is pinned to. Only a single-valued `const`/`enum` counts: a
     * multi-valued enum is ordinary data, and folding it into the identity
     * would turn adding one enum member into a swapped variant.
     *
     * @param  array<string, mixed>  $property
     */
    private function variantTagSignature(array $property): string
    {
        if (array_key_exists('const', $property)) {
            return '='.$this->displayValue($property['const']);
        }

        $enum = $property['enum'] ?? null;

        if (is_array($enum) && count($enum) === 1) {
            return '='.$this->displayValue(array_values($enum)[0]);
        }

        return '';
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

        // `security` is an unordered OR list and each requirement's scope list
        // is a set, so only the content decides: reordering is not an
        // authentication change.
        if ($this->securitySignature($before) === $this->securitySignature($after)) {
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

    private function securitySignature(mixed $value): string
    {
        if (! is_array($value)) {
            return 'value:'.$this->displayValue($value);
        }

        $requirements = [];

        foreach ($value as $requirement) {
            if (! is_array($requirement)) {
                $requirements[] = $this->displayValue($requirement);

                continue;
            }

            $normalised = [];

            foreach ($this->associativeArray($requirement) as $scheme => $scopes) {
                $scopeList = $this->stringList($scopes);
                sort($scopeList, SORT_STRING);
                $normalised[$scheme] = array_values(array_unique($scopeList));
            }

            ksort($normalised, SORT_STRING);
            $requirements[] = $this->displayValue($normalised);
        }

        sort($requirements, SORT_STRING);

        return 'list:'.implode('|', $requirements);
    }

    /**
     * A server is not just its URL string: a templated URL only becomes an
     * endpoint once its variables are substituted, so `{region}` defaulting to
     * `us` instead of `eu` moves every client that never sets the variable,
     * while the raw URL list stays byte-for-byte identical.
     */
    private function compareServers(
        mixed $before,
        mixed $after,
        string $pointer,
        ?string $operation,
    ): void {
        if ($before === $after) {
            return;
        }

        $droppedUrls = array_values(array_diff($this->serverUrls($before), $this->serverUrls($after)));

        if ($droppedUrls !== []) {
            $this->addChange(
                'breaking',
                $pointer,
                $operation,
                'server_changed',
                sprintf('Server URL %s is no longer declared.', implode(', ', $droppedUrls)),
            );

            return;
        }

        $droppedDefaults = array_values(array_diff(
            $this->serverDefaultUrls($before),
            $this->serverDefaultUrls($after),
        ));
        $droppedAllowed = $this->droppedServerAllowedUrls($before, $after);

        if ($droppedDefaults !== []) {
            $this->addChange(
                'breaking',
                $pointer,
                $operation,
                'server_changed',
                sprintf(
                    'Server endpoint %s is no longer what the default server variables resolve to.',
                    implode(', ', $droppedDefaults),
                ),
            );
        }

        if ($droppedAllowed !== []) {
            $this->addChange(
                'breaking',
                $pointer,
                $operation,
                'server_changed',
                sprintf(
                    'Server endpoint %s is no longer an allowed server variable value.',
                    implode(', ', $droppedAllowed),
                ),
            );
        }

        if ($droppedDefaults !== [] || $droppedAllowed !== []) {
            return;
        }

        $this->addChange(
            'additive',
            $pointer,
            $operation,
            'server_changed',
            'The declared server list changed without dropping a server URL.',
        );
    }

    /** @return list<string> */
    private function serverUrls(mixed $value): array
    {
        $urls = [];

        foreach ($this->arrayList($value) as $server) {
            $url = $server['url'] ?? null;

            if (is_string($url)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * The endpoint each declared server resolves to when the client sets no
     * variable at all — the only one most clients ever reach.
     *
     * @return list<string>
     */
    private function serverDefaultUrls(mixed $value): array
    {
        $urls = [];

        foreach ($this->arrayList($value) as $server) {
            $url = $server['url'] ?? null;

            if (is_string($url)) {
                $urls[] = $this->expandServerUrl($url, $this->serverVariableDefaults($server));
            }
        }

        return $urls;
    }

    /**
     * Every endpoint dropped from one variable's `enum`, keyed by the (URL,
     * variable) pair whose enum produced it: a value only counts as taken away
     * when the SAME variable still has an enum on the other side and no longer
     * lists it. A variable that loses its enum entirely becomes free-form
     * instead — every value it used to allow is still reachable, a widening,
     * not a removal — so that pairing is skipped rather than compared.
     *
     * @return list<string>
     */
    private function droppedServerAllowedUrls(mixed $before, mixed $after): array
    {
        $beforeAllowed = $this->serverAllowedUrlsByVariable($before);
        $afterAllowed = $this->serverAllowedUrlsByVariable($after);
        $dropped = [];

        foreach ($beforeAllowed as $key => $urls) {
            if (! array_key_exists($key, $afterAllowed)) {
                continue;
            }

            foreach (array_diff($urls, $afterAllowed[$key]) as $url) {
                $dropped[] = $url;
            }
        }

        return array_values(array_unique($dropped));
    }

    /**
     * @return array<string, list<string>> the expanded URLs per (server URL,
     *                                      variable name) enum, keyed by
     *                                      "url\0variable" so the same variable
     *                                      can be compared across documents.
     */
    private function serverAllowedUrlsByVariable(mixed $value): array
    {
        $urls = [];

        foreach ($this->arrayList($value) as $server) {
            $url = $server['url'] ?? null;

            if (! is_string($url)) {
                continue;
            }

            $defaults = $this->serverVariableDefaults($server);

            foreach ($this->associativeArray($server['variables'] ?? []) as $name => $variable) {
                $enum = $this->associativeArray($variable)['enum'] ?? null;

                if (! is_array($enum)) {
                    continue;
                }

                $key = $url."\0".(string) $name;

                foreach ($this->stringList($enum) as $allowed) {
                    $urls[$key][] = $this->expandServerUrl($url, array_merge($defaults, [(string) $name => $allowed]));
                }
            }
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, string>
     */
    private function serverVariableDefaults(array $server): array
    {
        $defaults = [];

        foreach ($this->associativeArray($server['variables'] ?? []) as $name => $variable) {
            $default = $this->associativeArray($variable)['default'] ?? null;

            if (is_string($default) || is_int($default) || is_float($default)) {
                $defaults[(string) $name] = (string) $default;
            }
        }

        return $defaults;
    }

    /** @param array<string, string> $values */
    private function expandServerUrl(string $url, array $values): string
    {
        foreach ($values as $name => $value) {
            $url = str_replace('{'.$name.'}', $value, $url);
        }

        return $url;
    }

    /**
     * Who a change hurts depends on which way the payload travels: a tighter
     * contract rejects a request a client used to send, a looser one breaks a
     * consumer reading the response. A `shared` component is read in both
     * directions, so either way counts.
     */
    private function constraintSeverity(bool $narrowed, string $context): string
    {
        if ($context === 'shared') {
            return 'breaking';
        }

        return $narrowed === ($context === 'request') ? 'breaking' : 'additive';
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
     * @param  array<string, mixed>  $components
     * @return list<array<string, mixed>>
     */
    private function effectiveParameters(array $pathItem, array $operation, array $components): array
    {
        $parameters = [];

        foreach (array_merge(
            $this->arrayList($pathItem['parameters'] ?? []),
            $this->arrayList($operation['parameters'] ?? []),
        ) as $parameter) {
            $resolved = $this->resolveParameter($parameter, $components);
            $parameters[$this->parameterKey($resolved)] = $resolved;
        }

        return array_values($parameters);
    }

    /**
     * A `$ref` parameter has to be resolved before it is keyed. Keying by the
     * reference string made a swapped reference look like one parameter
     * removed and an unrelated one added, instead of the same `name`/`in`
     * changing its `required` flag or schema.
     *
     * @param  array<string, mixed>  $parameter
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>
     */
    private function resolveParameter(array $parameter, array $components): array
    {
        /** @var array<string, true> $seen */
        $seen = [];

        while (true) {
            $reference = $parameter['$ref'] ?? null;

            if (! is_string($reference) || isset($seen[$reference])) {
                return $parameter;
            }

            $seen[$reference] = true;
            $resolved = $this->componentEntry($components, 'parameters', $reference);

            if ($resolved === null) {
                return $parameter;
            }

            $parameter = $resolved;
        }
    }

    /**
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>|null
     */
    private function componentEntry(array $components, string $section, string $reference): ?array
    {
        $prefix = '#/components/'.$section.'/';

        if (! str_starts_with($reference, $prefix)) {
            return null;
        }

        // RFC 6901 escaping: `~1` has to be decoded before `~0`, or a literal
        // `~01` would come back out as `/`.
        $name = str_replace(['~1', '~0'], ['/', '~'], substr($reference, strlen($prefix)));
        $entry = $this->associativeArray($components[$section] ?? [])[$name] ?? null;

        return is_array($entry) ? $this->associativeArray($entry) : null;
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
        // Only an unresolvable reference reaches here — an external document or
        // a component that does not exist. Keying by the raw string keeps it
        // stable rather than collapsing every such parameter onto one key.
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

    /** @param array<array-key, mixed> $values */
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
