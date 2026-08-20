import type { OpenApiDocument, ReferenceObject, SchemaObject } from '@/types/openapi'

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
    const resolved = resolveSchemaForMerge(member, document, visited)

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

function resolveSchemaForMerge(
  schema: SchemaObject | ReferenceObject,
  document: OpenApiDocument,
  visited: ReadonlySet<string>,
): SchemaObject | undefined {
  if (!isReference(schema)) {
    return schema
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

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
