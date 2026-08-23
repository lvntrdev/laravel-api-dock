import type { OpenApiDocument, ReferenceObject, SchemaObject } from '@/types/openapi'

const SAMPLE_MAX_DEPTH = 6
const STRING_PLACEHOLDERS: Record<string, string> = {
  'date-time': '2024-01-01T00:00:00Z',
  date: '2024-01-01',
  email: 'user@example.com',
  uuid: '00000000-0000-0000-0000-000000000000',
  uri: 'https://example.com',
  url: 'https://example.com',
  password: '',
}

export function isReference(value: object): value is ReferenceObject {
  return typeof (value as ReferenceObject).$ref === 'string'
}

export function resolvePointer(
  document: OpenApiDocument,
  pointer: string,
): SchemaObject | ReferenceObject | undefined {
  if (!pointer.startsWith('#/')) {
    return undefined
  }

  let current: unknown = document

  for (const rawPart of pointer.slice(2).split('/')) {
    if (!isRecord(current)) {
      return undefined
    }

    const part = rawPart.replace(/~1/g, '/').replace(/~0/g, '~')
    current = current[part]
  }

  return isRecord(current) ? (current as SchemaObject | ReferenceObject) : undefined
}

export function mergeAllOf(
  schema: SchemaObject,
  document: OpenApiDocument,
  visited: ReadonlySet<string> = new Set(),
): SchemaObject {
  if (!schema.allOf?.length) {
    return schema
  }

  const merged: SchemaObject = { ...schema }
  delete merged.allOf

  for (const member of schema.allOf) {
    const resolved = resolveSchema(member, document, visited)

    if (!resolved) {
      continue
    }

    const normalized = mergeAllOf(resolved, document, visited)
    Object.assign(merged, normalized, {
      properties: {
        ...(merged.properties ?? {}),
        ...(normalized.properties ?? {}),
      },
      required: [...new Set([...(merged.required ?? []), ...(normalized.required ?? [])])],
    })
  }

  return merged
}

export function schemaType(schema: SchemaObject): string {
  if (schema.type) {
    return Array.isArray(schema.type) ? schema.type.join(' | ') : schema.type
  }

  if (schema.properties) {
    return 'object'
  }

  if (schema.items) {
    return 'array'
  }

  return 'unknown'
}

export function schemaAllowsNull(schema: SchemaObject): boolean {
  return (
    schema.nullable === true ||
    schema.type === 'null' ||
    (Array.isArray(schema.type) && schema.type.includes('null'))
  )
}

export function printableValue(value: unknown): string {
  if (typeof value === 'string') {
    return `\"${value}\"`
  }

  const encoded = JSON.stringify(value)
  return encoded === undefined ? String(value) : encoded
}

/**
 * Resolves a schema entry to a concrete object: follows a `$ref`, then flattens `allOf`.
 * Returns undefined for a dangling pointer or a reference already seen on this branch.
 */
export function resolveSchema(
  schema: SchemaObject | ReferenceObject | undefined,
  document: OpenApiDocument,
  visited: ReadonlySet<string> = new Set(),
): SchemaObject | undefined {
  if (!schema) {
    return undefined
  }

  if (!isReference(schema)) {
    return mergeAllOf(schema, document, visited)
  }

  if (visited.has(schema.$ref)) {
    return undefined
  }

  const resolved = resolvePointer(document, schema.$ref)

  if (!resolved || isReference(resolved)) {
    return undefined
  }

  const nextVisited = new Set(visited)
  nextVisited.add(schema.$ref)

  return mergeAllOf(resolved, document, nextVisited)
}

/**
 * Builds a placeholder payload from a schema so the try-it body editor never starts empty
 * when the spec ships a schema but no example. Depth is capped because a self-referencing
 * component would otherwise recurse until the branch runs out of unseen `$ref`s.
 */
export function sampleFromSchema(
  schema: SchemaObject | ReferenceObject | undefined,
  document: OpenApiDocument,
  visited: ReadonlySet<string> = new Set(),
  depth = 0,
): unknown {
  const resolved = resolveSchema(schema, document, visited)

  if (!resolved || depth > SAMPLE_MAX_DEPTH) {
    return null
  }

  if (resolved.example !== undefined) {
    return resolved.example
  }

  if (resolved.default !== undefined) {
    return resolved.default
  }

  if (resolved.enum?.length) {
    return resolved.enum[0]
  }

  const nextVisited = schema && isReference(schema) ? new Set(visited).add(schema.$ref) : visited
  const variant = resolved.oneOf?.[0] ?? resolved.anyOf?.[0]

  if (variant && !resolved.properties && !resolved.items) {
    return sampleFromSchema(variant, document, nextVisited, depth + 1)
  }

  const type = concreteType(resolved)

  if (type === 'array') {
    return [sampleFromSchema(resolved.items, document, nextVisited, depth + 1)]
  }

  if (type === 'object' || resolved.properties) {
    const sample: Record<string, unknown> = {}

    for (const [name, property] of Object.entries(resolved.properties ?? {})) {
      sample[name] = sampleFromSchema(property, document, nextVisited, depth + 1)
    }

    return sample
  }

  return scalarPlaceholder(type, resolved.format)
}

/** Picks the first non-null entry of a union type, so `["string","null"]` samples as a string. */
function concreteType(schema: SchemaObject): string | undefined {
  if (Array.isArray(schema.type)) {
    return schema.type.find((candidate) => candidate !== 'null') ?? 'null'
  }

  return schema.type
}

function scalarPlaceholder(type: string | undefined, format: string | undefined): unknown {
  if (type === 'integer' || type === 'number') {
    return 0
  }

  if (type === 'boolean') {
    return false
  }

  if (type === 'null') {
    return null
  }

  if (type !== 'string') {
    return null
  }

  return STRING_PLACEHOLDERS[format ?? ''] ?? ''
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
