export type HttpMethod =
  | 'get'
  | 'post'
  | 'put'
  | 'patch'
  | 'delete'
  | 'options'
  | 'head'
  | 'trace'

export interface ReferenceObject {
  $ref: string
}

export interface SchemaObject {
  type?: string | string[]
  title?: string
  description?: string
  format?: string
  nullable?: boolean
  enum?: unknown[]
  default?: unknown
  example?: unknown
  properties?: Record<string, SchemaObject | ReferenceObject>
  required?: string[]
  items?: SchemaObject | ReferenceObject
  additionalProperties?: boolean | SchemaObject | ReferenceObject
  allOf?: Array<SchemaObject | ReferenceObject>
  oneOf?: Array<SchemaObject | ReferenceObject>
  anyOf?: Array<SchemaObject | ReferenceObject>
  [key: string]: unknown
}

export interface ParameterObject {
  name: string
  in: string
  description?: string
  required?: boolean
  example?: unknown
  schema?: SchemaObject | ReferenceObject
}

export interface MediaTypeObject {
  schema?: SchemaObject | ReferenceObject
  example?: unknown
}

export interface RequestBodyObject {
  description?: string
  required?: boolean
  content?: Record<string, MediaTypeObject>
}

export interface ResponseObject {
  description?: string
  content?: Record<string, MediaTypeObject>
}

export interface ApiDockFeatures {
  auth?: string
  scopes?: string[]
  rate_limit?: {
    limit: number
    per: string
  }
  deprecated?: boolean
  stability?: string
}

export interface AiExample {
  name: string
  request: unknown
  response: unknown
}

export interface AiTool {
  enabled: boolean
  name?: string
  description?: string
}

export interface AiPitfall {
  order?: number
  text: string
}

export interface ApiDockChangelogEntry {
  date: string
  summary: string
  breaking: boolean
}

export interface ServerVariableObject {
  default: string
  description?: string
  enum?: string[]
}

export interface ServerObject {
  url: string
  description?: string
  variables?: Record<string, ServerVariableObject>
}

export interface OperationObject {
  operationId?: string
  summary?: string
  description?: string
  tags?: string[]
  deprecated?: boolean
  parameters?: Array<ParameterObject | ReferenceObject>
  requestBody?: RequestBodyObject | ReferenceObject
  responses?: Record<string, ResponseObject | ReferenceObject>
  servers?: ServerObject[]
  'x-ai-hint'?: string
  'x-ai-pitfalls'?: AiPitfall[]
  'x-ai-examples'?: AiExample[]
  'x-ai-tool'?: AiTool
  'x-api-dock-changelog'?: ApiDockChangelogEntry[]
  'x-api-dock-features'?: ApiDockFeatures
}

export type PathItemObject = Partial<Record<HttpMethod, OperationObject>> & {
  parameters?: Array<ParameterObject | ReferenceObject>
  servers?: ServerObject[]
}

export interface OpenApiDocument {
  openapi?: string
  info?: {
    title?: string
    version?: string
    description?: string
  }
  servers?: ServerObject[]
  paths?: Record<string, PathItemObject>
  components?: {
    schemas?: Record<string, SchemaObject | ReferenceObject>
    [key: string]: unknown
  }
  tags?: Array<{ name: string; description?: string }>
  [key: string]: unknown
}

export interface OperationEntry {
  key: string
  method: HttpMethod
  path: string
  operation: OperationObject
  parameters: Array<ParameterObject | ReferenceObject>
}

export interface OperationGroup {
  tag: string
  operations: OperationEntry[]
}
