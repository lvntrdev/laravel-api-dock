import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import AiPanel from '@/components/AiPanel.vue'
import { t } from '@/lib/i18n'
import type { OpenApiDocument, OperationEntry } from '@/types/openapi'

const document: OpenApiDocument = {
  openapi: '3.1.0',
  paths: {},
  components: {
    schemas: {
      UserPayload: {
        type: 'object',
        required: ['email'],
        properties: {
          email: { type: 'string' },
          id: { type: 'integer' },
        },
      },
    },
  },
}

function entry(operation: OperationEntry['operation'], parameters: OperationEntry['parameters'] = []): OperationEntry {
  return {
    key: 'post:/users/{id}',
    method: 'post',
    path: '/users/{id}',
    operation,
    parameters,
  }
}

async function copiedText(operation: OperationEntry['operation'], button: string, parameters: OperationEntry['parameters'] = []): Promise<string> {
  const writeText = vi.fn().mockResolvedValue(undefined)
  vi.stubGlobal('navigator', { clipboard: { writeText } })
  const wrapper = mount(AiPanel, { props: { document, operation: entry(operation, parameters) } })

  await wrapper.get(button).trigger('click')

  return String(writeText.mock.calls[0]?.[0] ?? '')
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('AiPanel agent prompt', () => {
  it('carries the summary, feature requirements, schema and dated changelog', async () => {
    const prompt = await copiedText(
      {
        summary: 'Update a user',
        description: 'Replaces the mutable fields of one user.',
        'x-ai-hint': 'Send the whole record.',
        'x-api-dock-features': {
          auth: 'sanctum',
          scopes: ['users:write'],
          rate_limit: { limit: 60, per: 'minute' },
          stability: 'beta',
          deprecated: true,
        },
        requestBody: {
          required: true,
          content: { 'application/json': { schema: { $ref: '#/components/schemas/UserPayload' } } },
        },
        responses: { '200': { description: 'OK' } },
        'x-api-dock-changelog': [
          { date: '2026-02-01', summary: 'Scope users:write became mandatory.', breaking: true },
          { date: '2025-11-03', summary: 'Added the email field.', breaking: false },
        ],
      },
      '.copy-button',
      [{ name: 'id', in: 'path', required: true, schema: { type: 'string' } }],
    )

    expect(prompt).toContain('# POST /users/{id}')
    expect(prompt).toContain('Update a user')
    expect(prompt).toContain('Replaces the mutable fields of one user.')
    expect(prompt).toContain('Send the whole record.')
    expect(prompt).toContain(`- ${t('ai.authenticationRequired', { guard: 'sanctum' })}`)
    expect(prompt).toContain(`- ${t('ai.scopes')}: users:write`)
    expect(prompt).toContain(`- ${t('ai.rateLimit')}: 60 ${t('ai.per')} minute`)
    expect(prompt).toContain(`- ${t('ai.stability')}: beta`)
    expect(prompt).toContain(`- ${t('ai.deprecated')}: ${t('common.yes').toLocaleLowerCase()}`)
    expect(prompt).toContain(`- id (path, ${t('operation.required')}, string)`)
    expect(prompt).toContain(`${t('ai.requestBody')} (${t('operation.required')}):`)
    expect(prompt).toContain('"email"')
    expect(prompt).toContain(`${t('ai.changelog')}:`)
    expect(prompt).toContain(`- 2026-02-01 — Scope users:write became mandatory. (${t('diff.breaking')})`)
    expect(prompt.indexOf('2026-02-01')).toBeLessThan(prompt.indexOf('2025-11-03'))
  })

  it('reports a null auth guard as not required and omits every empty section', async () => {
    const prompt = await copiedText(
      { 'x-ai-hint': 'No credentials needed.', 'x-api-dock-features': { auth: undefined, scopes: [] } },
      '.copy-button',
    )

    expect(prompt).toContain(`- ${t('ai.authenticationNotRequired')}`)
    expect(prompt).not.toContain(`${t('ai.changelog')}:`)
    expect(prompt).not.toContain(`${t('ai.parameters')}:`)
    expect(prompt).not.toContain(t('ai.requestBody'))
  })
})

describe('AiPanel MCP tool', () => {
  it('spreads the body schema properties and renames a parameter collision', async () => {
    const definition = JSON.parse(await copiedText(
      {
        operationId: 'updateUser',
        requestBody: {
          required: true,
          content: { 'application/json': { schema: { $ref: '#/components/schemas/UserPayload' } } },
        },
      },
      '.artifact-actions button:first-child',
      [{ name: 'id', in: 'path', required: true, schema: { type: 'string' } }],
    )) as {
      inputSchema: { properties: Record<string, { type?: string }>; required: string[] }
    }

    expect(Object.keys(definition.inputSchema.properties)).toEqual(['id', 'email', 'body_id'])
    expect(definition.inputSchema.properties.email).toEqual({ type: 'string' })
    expect(definition.inputSchema.properties.body_id).toEqual({ type: 'integer' })
    expect(definition.inputSchema.properties.body).toBeUndefined()
    expect(definition.inputSchema.required).toEqual(['id', 'email'])
  })

  it('falls back to a single body argument when the body schema has no properties', async () => {
    const definition = JSON.parse(await copiedText(
      {
        operationId: 'importUsers',
        requestBody: {
          required: true,
          content: { 'application/json': { schema: { type: 'array', items: { type: 'string' } } } },
        },
      },
      '.artifact-actions button:first-child',
    )) as {
      inputSchema: { properties: Record<string, { type?: string }>; required: string[] }
    }

    expect(definition.inputSchema.properties.body).toEqual({ type: 'array', items: { type: 'string' } })
    expect(definition.inputSchema.required).toEqual(['body'])
  })
})
