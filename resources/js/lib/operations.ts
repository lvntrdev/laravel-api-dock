import type {
  HttpMethod,
  OpenApiDocument,
  OperationEntry,
  OperationGroup,
  OperationObject,
  ParameterObject,
  ReferenceObject,
} from '@/types/openapi'

const HTTP_METHODS: HttpMethod[] = [
  'get',
  'post',
  'put',
  'patch',
  'delete',
  'options',
  'head',
  'trace',
]

function operationKey(method: HttpMethod, path: string): string {
  return `${method}:${path}`
}

export function collectOperations(document: OpenApiDocument): OperationEntry[] {
  const entries: OperationEntry[] = []

  for (const [path, pathItem] of Object.entries(document.paths ?? {})) {
    const sharedParameters = pathItem.parameters ?? []

    for (const method of HTTP_METHODS) {
      const operation = pathItem[method]

      if (!operation) {
        continue
      }

      entries.push({
        key: operationKey(method, path),
        method,
        path,
        operation,
        parameters: mergeParameters(sharedParameters, operation.parameters ?? []),
      })
    }
  }

  return entries
}

export function groupOperations(
  document: OpenApiDocument,
  query = '',
): OperationGroup[] {
  const needle = query.trim().toLocaleLowerCase()
  const groups = new Map<string, OperationEntry[]>()

  for (const entry of collectOperations(document)) {
    const tags = entry.operation.tags?.length ? entry.operation.tags : ['Untagged']
    const haystack = [
      entry.method,
      entry.path,
      entry.operation.summary,
      entry.operation.operationId,
      ...tags,
    ]
      .filter(Boolean)
      .join(' ')
      .toLocaleLowerCase()

    if (needle && !haystack.includes(needle)) {
      continue
    }

    for (const tag of tags) {
      const operations = groups.get(tag) ?? []
      operations.push(entry)
      groups.set(tag, operations)
    }
  }

  return [...groups.entries()]
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([tag, operations]) => ({ tag, operations }))
}

export function findOperation(
  document: OpenApiDocument,
  key: string | undefined,
): OperationEntry | undefined {
  const operations = collectOperations(document)

  return operations.find((operation) => operation.key === key) ?? operations[0]
}

export function encodeOperationHash(key: string): string {
  return `#operation=${encodeURIComponent(key)}`
}

export function operationKeyFromHash(hash: string): string | undefined {
  const match = hash.match(/^#operation=(.+)$/)

  if (!match) {
    return undefined
  }

  try {
    return decodeURIComponent(match[1])
  } catch {
    return undefined
  }
}

function mergeParameters(
  shared: Array<ParameterObject | ReferenceObject>,
  operation: Array<ParameterObject | ReferenceObject>,
): Array<ParameterObject | ReferenceObject> {
  const merged = new Map<string, ParameterObject | ReferenceObject>()

  for (const parameter of [...shared, ...operation]) {
    const key = '$ref' in parameter ? parameter.$ref : `${parameter.in}:${parameter.name}`
    merged.set(key, parameter)
  }

  return [...merged.values()]
}
