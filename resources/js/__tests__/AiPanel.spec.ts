import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

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
    expect(definition.inputSchema.properties.body_id).toEqual({
      type: 'integer',
      description: '(request body property "id")',
    })
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

describe('AiPanel MCP tool reference resolution and naming', () => {
  type Tool = { inputSchema: { properties: Record<string, Record<string, unknown>>; required: string[] } }

  async function toolFor(
    schemas: Record<string, unknown>,
    operation: OperationEntry['operation'],
    parameters: OperationEntry['parameters'] = [],
  ): Promise<Tool> {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const wrapper = mount(AiPanel, {
      props: {
        document: { openapi: '3.1.0', paths: {}, components: { schemas } } as OpenApiDocument,
        operation: entry(operation, parameters),
      },
    })

    await wrapper.get('.artifact-actions button:first-child').trigger('click')

    return JSON.parse(String(writeText.mock.calls[0]?.[0] ?? '')) as Tool
  }

  it('inlines nested references so the copied schema carries no $ref', async () => {
    const definition = await toolFor(
      {
        User: { type: 'object', properties: { name: { type: 'string' } } },
        Team: { type: 'object', properties: { owner: { $ref: '#/components/schemas/User' } } },
      },
      {
        operationId: 'createTeam',
        requestBody: {
          content: {
            'application/json': {
              schema: {
                type: 'object',
                properties: {
                  items: { $ref: '#/components/schemas/User' },
                  list: { type: 'array', items: { $ref: '#/components/schemas/Team' } },
                  note: { $ref: '#/components/schemas/User', description: 'Override' },
                },
              },
            },
          },
        },
      },
    )

    expect(JSON.stringify(definition)).not.toContain('$ref')
    expect(definition.inputSchema.properties.items).toEqual({ type: 'object', properties: { name: { type: 'string' } } })
    expect(definition.inputSchema.properties.list).toEqual({
      type: 'array',
      items: { type: 'object', properties: { owner: { type: 'object', properties: { name: { type: 'string' } } } } },
    })
    expect(definition.inputSchema.properties.note.description).toBe('Override')
  })

  it('terminates on a self-referencing component and turns a dangling reference into an empty schema', async () => {
    const definition = await toolFor(
      { Node: { type: 'object', properties: { child: { $ref: '#/components/schemas/Node' } } } },
      {
        operationId: 'tree',
        requestBody: {
          content: {
            'application/json': {
              schema: {
                type: 'object',
                properties: {
                  root: { $ref: '#/components/schemas/Node' },
                  missing: { $ref: '#/components/schemas/Gone' },
                },
              },
            },
          },
        },
      },
    )

    expect(JSON.stringify(definition)).not.toContain('$ref')
    expect(definition.inputSchema.properties.root).toEqual({
      type: 'object',
      properties: { child: {} },
    })
    expect(definition.inputSchema.properties.missing).toEqual({})
  })

  it('keeps path.id, path.query_id and query.id as three distinct properties', async () => {
    const definition = await toolFor(
      {},
      { operationId: 'search' },
      [
        { name: 'query_id', in: 'path', required: true, schema: { type: 'integer' } },
        { name: 'id', in: 'path', required: true, schema: { type: 'string' } },
        { name: 'id', in: 'query', schema: { type: 'boolean' } },
      ],
    )

    expect(Object.keys(definition.inputSchema.properties)).toEqual(['query_id', 'id', 'query_id_2'])
    expect(definition.inputSchema.properties.query_id).toEqual({ type: 'integer' })
    expect(definition.inputSchema.properties.id).toEqual({ type: 'string' })
    expect(definition.inputSchema.properties.query_id_2).toEqual({
      type: 'boolean',
      description: '(query parameter "id")',
    })
  })
})

describe('AiPanel example dialog', () => {
  it('moves focus onto the close control, keeps Tab inside and returns focus on close', async () => {
    const wrapper = mount(AiPanel, {
      attachTo: window.document.body,
      props: {
        document,
        operation: entry({
          responses: {},
          'x-ai-examples': [{ name: 'create', request: { email: 'a@b.c' }, response: { id: 1 } }],
        }),
      },
    })

    const trigger = wrapper.get('[data-testid="expand-example-request"]')
    await trigger.trigger('click')
    await nextTick()

    const close = window.document.querySelector<HTMLElement>('[data-testid="close-ai-example-modal"]')
    expect(window.document.activeElement).toBe(close)

    // The dialog holds one focusable control, so a Tab in either direction stays on it.
    const tab = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true })
    window.document.dispatchEvent(tab)
    expect(tab.defaultPrevented).toBe(true)
    expect(window.document.activeElement).toBe(close)

    window.document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()
    await nextTick()

    expect(window.document.querySelector('[data-testid="ai-example-modal"]')).toBeNull()
    expect(window.document.activeElement).toBe(trigger.element)

    wrapper.unmount()
  })
})
