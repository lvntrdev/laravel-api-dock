<script setup lang="ts">
import { computed, ref } from 'vue'

import JsonTree from '@/components/JsonTree.vue'
import { t } from '@/lib/i18n'
import { isReference, resolvePointer } from '@/lib/schema'
import type {
  OpenApiDocument,
  OperationEntry,
  ParameterObject,
  RequestBodyObject,
  ResponseObject,
  SchemaObject,
} from '@/types/openapi'

const RENDER_LIMIT = 20_000

const props = defineProps<{
  document: OpenApiDocument
  operation: OperationEntry
}>()

const copied = ref<'prompt' | 'mcp' | 'llms' | ''>('')
const hint = computed(() => props.operation.operation['x-ai-hint'])
const pitfalls = computed(() => props.operation.operation['x-ai-pitfalls'] ?? [])
const examples = computed(() => props.operation.operation['x-ai-examples'] ?? [])
const changelog = computed(() => props.operation.operation['x-api-dock-changelog'] ?? [])
const agentPrompt = computed(() => buildAgentPrompt())
const mcpTool = computed(() => JSON.stringify(buildMcpTool(), null, 2) ?? '{}')
const llmsSection = computed(() => buildLlmsSection())

async function copy(kind: 'prompt' | 'mcp' | 'llms', value: string): Promise<void> {
  await navigator.clipboard.writeText(value)
  copied.value = kind
}

function buildAgentPrompt(): string {
  const operation = props.operation.operation
  const lines = [`# ${props.operation.method.toUpperCase()} ${props.operation.path}`]

  if (operation.summary) {
    lines.push('', operation.summary)
  }

  if (operation.description) {
    lines.push('', operation.description)
  }

  if (hint.value) {
    lines.push('', hint.value)
  }

  const requirements = featureRequirements()

  if (requirements.length) {
    lines.push('', `${t('ai.requirements')}:`, ...requirements)
  }

  const parameters = props.operation.parameters
    .map(resolveParameter)
    .filter((parameter): parameter is ParameterObject => !!parameter)

  if (parameters.length) {
    lines.push('', `${t('ai.parameters')}:`)
    parameters.forEach((parameter) => {
      const type = resolveSchema(parameter.schema)?.type ?? t('common.unknown')
      const requirement = parameter.in === 'path' || parameter.required
        ? t('operation.required')
        : t('ai.optional')
      lines.push(
        `- ${parameter.name} (${parameter.in}, ${requirement}, ${Array.isArray(type) ? type.join(' | ') : type})`
        + (parameter.description ? `: ${parameter.description}` : ''),
      )
    })
  }

  const requestBody = resolveRequestBody()
  const requestSchema = resolveSchema(
    requestBody?.content?.['application/json']?.schema
      ?? Object.values(requestBody?.content ?? {})[0]?.schema,
  )

  if (requestSchema) {
    lines.push(
      '',
      `${t('ai.requestBody')}${requestBody?.required ? ` (${t('operation.required')})` : ''}:`,
      '```json',
      prettyJson(requestSchema),
      '```',
    )
  }

  const responses = Object.entries(operation.responses ?? {})

  if (responses.length) {
    lines.push('', `${t('ai.responses')}:`)
    responses.forEach(([status, candidate]) => {
      const response = resolveResponse(candidate)
      const responseSchema = resolveSchema(
        response?.content?.['application/json']?.schema
          ?? Object.values(response?.content ?? {})[0]?.schema,
      )
      lines.push('', `## ${status}${response?.description ? ` — ${response.description}` : ''}`)
      if (responseSchema) {
        lines.push('```json', prettyJson(responseSchema), '```')
      }
    })
  }

  if (pitfalls.value.length) {
    lines.push('', `${t('ai.pitfalls')}:`)
    pitfalls.value.forEach((pitfall, index) => lines.push(`${index + 1}. ${pitfall.text}`))
  }

  if (examples.value.length) {
    lines.push('', `${t('ai.examples')}:`)
    examples.value.forEach((example) => {
      lines.push(
        '',
        `## ${example.name}`,
        `${t('ai.request')}:`,
        prettyJson(example.request),
        `${t('ai.response')}:`,
        prettyJson(example.response),
      )
    })
  }

  if (changelog.value.length) {
    lines.push('', `${t('ai.changelog')}:`)
    changelog.value.forEach((entry) => {
      lines.push(`- ${entry.date} — ${entry.summary}${entry.breaking ? ` (${t('diff.breaking')})` : ''}`)
    })
  }

  return lines.join('\n')
}

function featureRequirements(): string[] {
  const operation = props.operation.operation
  const features = operation['x-api-dock-features']
  const lines: string[] = []

  if (features) {
    // `auth` carries a guard NAME ("sanctum"), never a boolean: any non-empty string
    // means the endpoint is authenticated.
    const auth = typeof features.auth === 'string' ? features.auth.trim() : ''
    lines.push(`- ${auth !== ''
      ? t('ai.authenticationRequired', { guard: auth })
      : t('ai.authenticationNotRequired')}`)

    if (features.scopes?.length) {
      lines.push(`- ${t('ai.scopes')}: ${features.scopes.join(', ')}`)
    }
    if (features.rate_limit) {
      lines.push(`- ${t('ai.rateLimit')}: ${features.rate_limit.limit} ${t('ai.per')} ${features.rate_limit.per}`)
    }
    if (features.stability) {
      lines.push(`- ${t('ai.stability')}: ${features.stability}`)
    }
  }

  if (operation.deprecated || features?.deprecated) {
    lines.push(`- ${t('ai.deprecated')}: ${t('common.yes').toLocaleLowerCase()}`)
  }

  return lines
}

function buildMcpTool(): Record<string, unknown> {
  const operation = props.operation.operation
  const aiTool = operation['x-ai-tool']
  const properties: Record<string, SchemaObject> = {}
  const required: string[] = []

  for (const candidate of props.operation.parameters) {
    const parameter = resolveParameter(candidate)

    if (!parameter || !['path', 'query', 'header'].includes(parameter.in)) {
      continue
    }

    const name = uniqueName(properties, parameter.name, parameter.in)
    const schema = resolveSchema(parameter.schema) ?? { type: 'string' }
    properties[name] = parameter.description
      ? { ...schema, description: parameter.description }
      : schema

    if (parameter.in === 'path' || parameter.required) {
      appendRequired(required, name)
    }
  }

  const body = resolveRequestBody()
  const bodySchema = resolveSchema(
    body?.content?.['application/json']?.schema
      ?? Object.values(body?.content ?? {})[0]?.schema,
  )

  // Mirrors McpToolExporter::inputSchema(): the body schema's own top-level properties
  // are spread into the tool arguments (a collision is renamed with a `body_` prefix),
  // and the body schema's `required` names become tool-level required names. A body
  // schema carrying no properties travels whole under a single `body` argument.
  if (bodySchema) {
    const bodyProperties = Object.entries(bodySchema.properties ?? {})

    if (bodyProperties.length) {
      const exportedNames: Record<string, string> = {}

      for (const [property, candidate] of bodyProperties) {
        const name = uniqueName(properties, property, 'body')
        properties[name] = resolveSchema(candidate) ?? {}
        exportedNames[property] = name
      }

      for (const requiredName of bodySchema.required ?? []) {
        appendRequired(required, exportedNames[requiredName] ?? requiredName)
      }
    } else {
      const name = uniqueName(properties, 'body', 'body')
      properties[name] = bodySchema
      if (body?.required) {
        appendRequired(required, name)
      }
    }
  }

  return {
    name: aiTool?.name || operation.operationId || fallbackToolName(),
    description: toolDescription(),
    inputSchema: {
      type: 'object',
      properties,
      required,
    },
  }
}

function buildLlmsSection(): string {
  const operation = props.operation.operation
  const lines = [`### ${props.operation.method.toUpperCase()} ${props.operation.path}`]

  if (operation.summary) {
    lines.push('', operation.summary)
  }
  if (hint.value) {
    lines.push('', `**${t('ai.hintLabel')}:** ${hint.value}`)
  }
  if (pitfalls.value.length) {
    lines.push('', `#### ${t('ai.pitfalls')}`, '')
    pitfalls.value.forEach((pitfall, index) => lines.push(`${index + 1}. ${pitfall.text}`))
  }

  const features = operation['x-api-dock-features']
  const scopes = features?.scopes ?? []
  lines.push(
    '',
    features?.auth
      ? `**${t('operation.auth')}:** ${t('ai.required')}${scopes.length ? ` (${t('ai.scopes').toLocaleLowerCase()}: ${scopes.join(', ')})` : ''}`
      : `**${t('operation.auth')}:** ${t('ai.authenticationNotRequired').split(': ').at(-1)}`,
  )

  if (operation.deprecated || features?.deprecated) {
    lines.push(`**${t('ai.deprecated')}:** ${t('common.yes')}`)
  }

  lines.push('', `#### ${t('ai.parameters')}`, '')
  const parameters = props.operation.parameters
    .map(resolveParameter)
    .filter((parameter): parameter is ParameterObject => !!parameter)

  if (parameters.length) {
    lines.push(`| ${t('schema.name')} | ${t('ai.parameterLocation')} | ${t('ai.required')} | ${t('ai.type')} |`, '| --- | --- | --- | --- |')
    parameters.forEach((parameter) => {
      const type = resolveSchema(parameter.schema)?.type ?? t('common.unknown')
      lines.push(`| ${parameter.name} | ${parameter.in} | ${parameter.in === 'path' || parameter.required ? t('common.yes') : t('common.no')} | ${Array.isArray(type) ? type.join(' | ') : type} |`)
    })
  } else {
    lines.push(t('ai.noParameters'))
  }

  lines.push('', `#### ${t('ai.requestBody')}`, '')
  const requestBody = resolveRequestBody()
  const requestSchema = resolveSchema(
    requestBody?.content?.['application/json']?.schema
      ?? Object.values(requestBody?.content ?? {})[0]?.schema,
  )

  if (requestSchema) {
    lines.push('```json', prettyJson(requestSchema), '```')
  } else {
    lines.push(t('ai.noRequestBody'))
  }

  lines.push('', `#### ${t('ai.responses')}`)
  const responses = Object.entries(operation.responses ?? {})

  if (!responses.length) {
    lines.push('', t('ai.noResponses'))
  }

  responses.forEach(([status, candidate]) => {
    const response = resolveResponse(candidate)
    const responseSchema = resolveSchema(
      response?.content?.['application/json']?.schema
        ?? Object.values(response?.content ?? {})[0]?.schema,
    )
    lines.push('', `##### ${status}`)
    if (response?.description) {
      lines.push('', response.description)
    }
    lines.push('')
    if (responseSchema) {
      lines.push('```json', prettyJson(responseSchema), '```')
    } else {
      lines.push(t('ai.noResponseBody'))
    }
  })

  if (examples.value.length) {
    lines.push('', `#### ${t('ai.examples')}`)
    examples.value.forEach((example) => {
      lines.push(
        '',
        `##### ${example.name}`,
        '',
        `**${t('ai.request')}**`,
        '',
        '```json',
        prettyJson(example.request),
        '```',
        '',
        `**${t('ai.response')}**`,
        '',
        '```json',
        prettyJson(example.response),
        '```',
      )
    })
  }

  if (changelog.value.length) {
    lines.push('', `#### ${t('ai.changelog')}`, '')
    changelog.value.forEach((entry) => {
      lines.push(`- ${entry.date} — ${entry.summary}${entry.breaking ? ` **${t('ai.breaking')}**` : ''}`)
    })
  }

  return lines.join('\n')
}

function toolDescription(): string {
  const operation = props.operation.operation
  const base = operation['x-ai-tool']?.description
    || [operation.summary, hint.value].filter(Boolean).join('\n\n')
    || operation.description
    || ''

  if (!pitfalls.value.length) {
    return base
  }

  return [
    base,
    base ? '' : undefined,
    `${t('ai.pitfalls')}:`,
    ...pitfalls.value.map((pitfall, index) => `${index + 1}. ${pitfall.text}`),
  ].filter((line): line is string => line !== undefined).join('\n')
}

function fallbackToolName(): string {
  const suffix = props.operation.path.replace(/^\/+|\/+$/g, '').replace(/[^a-z0-9]+/gi, '_')
  return `${props.operation.method}_${suffix || 'root'}`.toLowerCase()
}

function resolveParameter(candidate: OperationEntry['parameters'][number]): ParameterObject | undefined {
  if (!isReference(candidate)) {
    return candidate
  }

  const resolved = resolvePointer(props.document, candidate.$ref)
  return resolved && !isReference(resolved) ? (resolved as ParameterObject) : undefined
}

function resolveRequestBody(): RequestBodyObject | undefined {
  const candidate = props.operation.operation.requestBody

  if (!candidate) {
    return undefined
  }
  if (!isReference(candidate)) {
    return candidate
  }

  const resolved = resolvePointer(props.document, candidate.$ref)
  return resolved && !isReference(resolved) ? (resolved as RequestBodyObject) : undefined
}

function resolveResponse(
  candidate: ResponseObject | { $ref: string },
): ResponseObject | undefined {
  if (!isReference(candidate)) {
    return candidate
  }

  const resolved = resolvePointer(props.document, candidate.$ref)
  return resolved && !isReference(resolved) ? (resolved as ResponseObject) : undefined
}

function resolveSchema(candidate?: SchemaObject | { $ref: string }): SchemaObject | undefined {
  if (!candidate) {
    return undefined
  }
  if (!isReference(candidate)) {
    return candidate
  }

  const resolved = resolvePointer(props.document, candidate.$ref)
  return resolved && !isReference(resolved) ? resolved : undefined
}

function uniqueName(
  properties: Readonly<Record<string, SchemaObject>>,
  requested: string,
  source: string,
): string {
  if (!(requested in properties)) {
    return requested
  }

  return `${source}_${requested}`.replace(/[^a-z0-9_]/gi, '_')
}

function appendRequired(required: string[], name: string): void {
  if (!required.includes(name)) {
    required.push(name)
  }
}

function boundedJson(value: unknown): string {
  return prettyJson(value).slice(0, RENDER_LIMIT)
}

function isRenderedJsonTruncated(value: unknown): boolean {
  return prettyJson(value).length > RENDER_LIMIT
}

function prettyJson(value: unknown): string {
  return JSON.stringify(value, null, 2) ?? String(value)
}
</script>

<template>
  <section class="panel ai-panel" data-api-dock-panel="ai">
    <div class="panel-toolbar">
      <button class="copy-button" type="button" @click="copy('prompt', agentPrompt)">
        <i class="pi pi-copy" />
        {{ copied === 'prompt' ? t('ai.promptCopied') : t('ai.copyFullPrompt') }}
      </button>
    </div>

    <blockquote v-if="hint" class="ai-hint">{{ hint }}</blockquote>
    <p v-else class="inline-empty">{{ t('ai.noHint') }}</p>

    <div v-if="pitfalls.length" class="ai-pitfalls">
      <h3>{{ t('ai.pitfalls') }}</h3>
      <ol>
        <li v-for="(pitfall, index) in pitfalls" :key="`${pitfall.order ?? 0}:${index}`">
          {{ pitfall.text }}
        </li>
      </ol>
    </div>

    <div v-if="examples.length" class="ai-examples">
      <details v-for="example in examples" :key="example.name">
        <summary>
          <i class="pi pi-chevron-right" />
          <span>{{ example.name }}</span>
        </summary>
        <div class="ai-example__columns">
          <div class="ai-example__request">
            <span class="panel-label">{{ t('ai.request') }}</span>
            <pre>{{ boundedJson(example.request) }}</pre>
            <p v-if="isRenderedJsonTruncated(example.request)" class="truncation-notice">
              {{ t('ai.renderTruncated', { limit: RENDER_LIMIT }) }}
            </p>
          </div>
          <div class="ai-example__response">
            <span class="panel-label">{{ t('ai.response') }}</span>
            <JsonTree :source="boundedJson(example.response)" />
            <p v-if="isRenderedJsonTruncated(example.response)" class="truncation-notice">
              {{ t('ai.renderTruncated', { limit: RENDER_LIMIT }) }}
            </p>
          </div>
        </div>
      </details>
    </div>

    <div class="artifact-actions">
      <button class="button button--quiet" type="button" @click="copy('mcp', mcpTool)">
        <i class="pi pi-copy" />
        {{ copied === 'mcp' ? t('ai.mcpCopied') : t('ai.copyMcpDefinition') }}
      </button>
      <button class="button button--quiet" type="button" @click="copy('llms', llmsSection)">
        <i class="pi pi-copy" />
        {{ copied === 'llms' ? t('ai.llmsCopied') : t('ai.copyLlmsSection') }}
      </button>
    </div>

    <div v-if="changelog.length" class="operation-changelog">
      <h3>{{ t('ai.operationChangelog') }}</h3>
      <p>{{ t('ai.changelogHint') }}</p>
      <ol>
        <li v-for="(entry, index) in changelog" :key="`${entry.date}:${index}`">
          <time>{{ entry.date }}</time>
          <span>{{ entry.summary }}</span>
          <strong v-if="entry.breaking">{{ t('ai.breaking') }}</strong>
        </li>
      </ol>
    </div>
  </section>
</template>
