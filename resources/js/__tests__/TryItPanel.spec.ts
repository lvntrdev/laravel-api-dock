import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import TryItPanel from '@/components/TryItPanel.vue'
import { resetProfiles } from '@/lib/tryItProfiles'
import {
  operationInputs,
  plainBaseUrl,
  resetTryItSession,
  serverVariables,
  setServerVariable,
} from '@/lib/tryItSession'
import type { OpenApiDocument, OperationEntry } from '@/types/openapi'

const document: OpenApiDocument = {
  openapi: '3.1.0',
  servers: [
    {
      url: 'https://{tenant}.example.com/api',
      variables: {
        tenant: { default: 'acme', enum: ['acme', 'globex'] },
        region: { default: 'us-east' },
      },
    },
  ],
  paths: {},
}

const operation: OperationEntry = {
  key: 'post:/users/{id}',
  method: 'post',
  path: '/users/{id}',
  operation: {
    operationId: 'updateUser',
    requestBody: {
      content: {
        'application/json': {
          example: { active: true },
        },
      },
    },
    responses: { '200': { description: 'OK' } },
  },
  parameters: [
    { name: 'id', in: 'path', required: true, schema: { type: 'string', default: '42' } },
    { name: 'sort', in: 'query', schema: { type: 'string', default: 'name' } },
    { name: 'Accept', in: 'header', schema: { type: 'string', default: 'application/json' } },
  ],
}

beforeEach(() => {
  resetTryItSession()
  resetProfiles()
  window.localStorage.clear()
  window.sessionStorage.clear()
})

afterEach(() => {
  resetTryItSession()
  resetProfiles()
  window.localStorage.clear()
  window.sessionStorage.clear()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('TryItPanel', () => {
  it('blocks sending and surfaces the JSON parse error for an invalid body', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ profiles: [] }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="body-editor"]').setValue('{invalid')

    expect(wrapper.get('[data-testid="body-error"]').text()).not.toBe('')
    expect(wrapper.get<HTMLButtonElement>('[data-testid="send-request"]').element.disabled).toBe(true)
    await wrapper.get('[data-testid="send-request"]').trigger('click')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('offers only the methods the proxy supports and falls back for an unsupported one', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const wrapper = mount(TryItPanel, {
      props: {
        document,
        operation: { ...operation, method: 'trace' },
        baseUrl: '/api-dock',
        csrfToken: 'csrf-token',
      },
    })
    await flushPromises()

    const select = wrapper.get<HTMLSelectElement>('[data-testid="method-input"]')
    const offered = select.findAll('option').map((option) => option.text())

    expect(offered).toEqual(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'])
    expect(offered).not.toContain('TRACE')
    expect(select.element.value).toBe('GET')
    wrapper.unmount()
  })

  it('renders the proxy refusal and removes every send action when try-it is disabled', async () => {
    const reason = 'Try-it is disabled for this installation.'
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ message: reason }, 403)))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-testid="try-it-disabled"]').text()).toContain(reason)
    expect(wrapper.find('[data-testid="send-request"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('regenerates curl with only the masked hint of the selected profile', async () => {
    // The panel never sees a raw credential any more: the profile list is the only
    // profile data it holds, and the server masks the credential before sending it.
    const rawCredential = 'raw-super-secret'
    const profile = tryItProfile({ id: 'profile-1' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [profile] })))
    const wrapper = mountPanel()
    await flushPromises()

    const before = wrapper.get('[data-testid="curl-sample"]').text()
    await wrapper.get('[data-testid="profile-select"]').setValue(profile.id)
    await wrapper.get('[data-testid="parameter-path-id"]').setValue('84')
    await wrapper.get('[data-testid="parameter-query-sort"]').setValue('created_at')
    await wrapper.vm.$nextTick()

    const sample = wrapper.get('[data-testid="curl-sample"]').text()
    expect(sample).not.toBe(before)
    expect(sample).toContain('https://acme.example.com/api/users/84?sort=created_at')
    expect(sample).toContain('Bearer ****ab12')
    expect(sample).not.toContain(rawCredential)
    wrapper.unmount()
  })

  it('fills declared server variables from a profile and leaves undeclared variables alone', async () => {
    const profile = tryItProfile({
      id: 'profile-1',
      server_variables: { tenant: 'globex' },
    })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [profile] })))
    const wrapper = mountPanel()
    await flushPromises()

    // The target fields live in the settings panel now, so the reader's own value is
    // asserted through the session the two panels share.
    setServerVariable('region', 'eu-west')
    await wrapper.get('[data-testid="profile-select"]').setValue(profile.id)
    await wrapper.vm.$nextTick()

    expect(serverVariables.value.tenant).toBe('globex')
    expect(serverVariables.value.region).toBe('eu-west')
    expect(wrapper.get('[data-testid="curl-sample"]').text())
      .toContain('https://globex.example.com/api')
    wrapper.unmount()
  })

  it('releases the previous profile values when switching to a profile that stores none', async () => {
    const first = tryItProfile({
      id: 'profile-1',
      base_url: 'https://staging.example.com/api',
      server_variables: { tenant: 'globex' },
    })
    const second = tryItProfile({ id: 'profile-2', label: 'Sandbox' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [first, second] })))
    const wrapper = mountPanel({ ...document, servers: [] })
    await flushPromises()

    await wrapper.get('[data-testid="profile-select"]').setValue(first.id)
    expect(plainBaseUrl.value).toBe(first.base_url)
    expect(serverVariables.value.tenant).toBe('globex')

    await wrapper.get('[data-testid="profile-select"]').setValue(second.id)

    expect(plainBaseUrl.value).toBe('')
    expect(serverVariables.value.tenant).toBe('')
    expect(JSON.parse(window.localStorage.getItem('api-dock:try-it') ?? '{}')).toMatchObject({
      selectedProfileId: second.id,
      plainBaseUrl: '',
    })
    wrapper.unmount()
  })

  it('previews the same target it sends when a stored value is outside the enum', async () => {
    // A tenant the selected server does not declare is narrowed away before the request
    // goes out; the curl sample must not keep advertising the value that was dropped, or
    // the screen names one tenant while the credential reaches another.
    setServerVariable('tenant', 'initech')
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [] }))
      .mockResolvedValueOnce(jsonResponse({ status: 200, headers: {}, body: '{}' }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPanel()
    await flushPromises()

    const sample = wrapper.get('[data-testid="curl-sample"]').text()
    expect(sample).toContain('https://acme.example.com/api')
    expect(sample).not.toContain('initech')

    await wrapper.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()

    const sent = JSON.parse(String(fetchMock.mock.calls.at(-1)?.[1]?.body)) as Record<string, unknown>

    expect(sent.server_variables).toMatchObject({ tenant: 'acme' })
    wrapper.unmount()
  })

  it('sends a profile base URL as a plain target even when the spec declares servers', async () => {
    const profile = tryItProfile({
      id: 'profile-1',
      base_url: 'https://test-kurum.example.com/api',
    })
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [profile] }))
      .mockResolvedValueOnce(jsonResponse({ status: 200, headers: {}, body: '{}' }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="profile-select"]').setValue(profile.id)
    await wrapper.vm.$nextTick()

    expect(plainBaseUrl.value).toBe(profile.base_url)
    expect(wrapper.get('[data-testid="curl-sample"]').text()).toContain(profile.base_url)

    await wrapper.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()

    const sent = JSON.parse(String(fetchMock.mock.calls.at(-1)?.[1]?.body)) as Record<string, unknown>

    // The override is a concrete host: the template must not travel with it.
    expect(sent.url).toBe(profile.base_url)
    expect(sent.server).toBeUndefined()
    expect(sent.server_variables).toBeUndefined()
    expect(sent.server_variable_spec).toBeUndefined()
    wrapper.unmount()
  })

  it('never sends a persisted profile id the panel could not load', async () => {
    window.localStorage.setItem('api-dock:try-it', JSON.stringify({
      selectedProfileId: 'profile-1',
      serverVariables: {},
      plainBaseUrl: '',
    }))
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ message: 'boom' }, 500))
      .mockResolvedValueOnce(jsonResponse({ status: 200, headers: {}, body: '{}' }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()

    const sendCall = fetchMock.mock.calls.at(-1)
    expect(sendCall?.[0]).toBe('/api-dock/try-it')
    expect(JSON.parse(String(sendCall?.[1]?.body))).not.toHaveProperty('profile')
    wrapper.unmount()
  })

  it('remembers the parameter values and body a reader typed for this operation', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const first = mountPanel()
    await flushPromises()

    await first.get('[data-testid="parameter-path-id"]').setValue('91')
    await first.get('[data-testid="body-editor"]').setValue('{"active":false}')
    first.unmount()

    // A remount is what leaving the endpoint and coming back does — the detail view
    // keys the panel on the operation — and it is also what a page reload does.
    const second = mountPanel()
    await flushPromises()

    expect(second.get<HTMLInputElement>('[data-testid="parameter-path-id"]').element.value).toBe('91')
    expect(second.get<HTMLTextAreaElement>('[data-testid="body-editor"]').element.value)
      .toBe('{"active":false}')
    expect(operationInputs.value[operation.key]?.parameters['path:id']).toBe('91')
    second.unmount()
  })

  it('shows the last response again after the panel is remounted', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [] }))
      .mockResolvedValueOnce(jsonResponse({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: '{"ok":true}',
        url: 'https://acme.example.com/api/users/42',
      }))
    vi.stubGlobal('fetch', fetchMock)
    const first = mountPanel()
    await flushPromises()

    await first.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()

    expect(first.find('.proxy-response').exists()).toBe(true)
    first.unmount()

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const second = mountPanel()
    await flushPromises()

    const response = second.get('.proxy-response')
    expect(response.text()).toContain('200')
    expect(response.text()).toContain('https://acme.example.com/api/users/42')
    second.unmount()
  })

  it('shows the response headers in a dialog instead of beside the request', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [] }))
      .mockResolvedValueOnce(jsonResponse({
        status: 200,
        headers: { 'content-type': 'application/json' },
        body: '{"ok":true}',
      }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('.proxy-response__columns .response-headers').exists()).toBe(false)
    // The request moved into a dialog of its own too.
    expect(wrapper.find('[data-testid="expand-request"]').exists()).toBe(true)

    await wrapper.get('[data-testid="expand-response-headers"]').trigger('click')
    await flushPromises()

    // The dialog is teleported to the document body, so it is queried there.
    const dialog = window.document.querySelector('[data-testid="response-headers-expanded"]')
    expect(dialog?.textContent).toContain('content-type')
    expect(dialog?.textContent).toContain('application/json')
    // The body copy action belongs to the body dialog only.
    expect(window.document.querySelector('[data-testid="copy-response-body-modal"]')).toBeNull()

    window.document.querySelector<HTMLButtonElement>('[data-testid="close-response-body-modal"]')?.click()
    await flushPromises()

    expect(window.document.querySelector('[data-testid="response-headers-expanded"]')).toBeNull()
    wrapper.unmount()
  })

  it('labels an icon-only action on hover outside every clipping card', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [] }))
      .mockResolvedValueOnce(jsonResponse({ status: 200, headers: {}, body: '{"ok":true}' }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPanel({ ...document }, operation)
    await flushPromises()

    await wrapper.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="expand-request"]').trigger('mouseenter')

    // On the document body, not inside the response card: that card clips its overflow.
    const tooltip = window.document.body.querySelector('.app-tooltip')
    expect(tooltip?.classList.contains('app-tooltip--visible')).toBe(true)
    expect(tooltip?.textContent).not.toBe('')
    expect(tooltip?.closest('.proxy-response')).toBeNull()

    await wrapper.get('[data-testid="expand-request"]').trigger('mouseleave')
    expect(tooltip?.classList.contains('app-tooltip--visible')).toBe(false)
    wrapper.unmount()
  })

  it('opens the request payload in its own dialog', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [] }))
      .mockResolvedValueOnce(jsonResponse({ status: 200, headers: {}, body: '{"ok":true}' }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="expand-request"]').trigger('click')
    await flushPromises()

    const dialog = window.document.querySelector('[data-testid="request-expanded"]')
    expect(dialog?.textContent).toContain('"active"')
    expect(window.document.querySelector('[data-testid="response-body-expanded"]')).toBeNull()
    wrapper.unmount()
  })

  it('keeps one operation response out of another operation panel', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [] }))
      .mockResolvedValueOnce(jsonResponse({ status: 200, headers: {}, body: '{"ok":true}' }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()
    wrapper.unmount()

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const other = mountPanel(document, { ...operation, key: 'post:/users/{id}:other' })
    await flushPromises()

    expect(other.find('.proxy-response').exists()).toBe(false)
    other.unmount()
  })

  it('keeps one operation form out of another operation form', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="parameter-path-id"]').setValue('91')
    wrapper.unmount()

    const other = mountPanel(document, {
      ...operation,
      key: 'post:/users/{id}:other',
      parameters: [{ name: 'id', in: 'path', required: true, schema: { type: 'string' } }],
    })
    await flushPromises()

    expect(other.get<HTMLInputElement>('[data-testid="parameter-path-id"]').element.value).toBe('')
    other.unmount()
  })

  it('keeps credential-bearing parameters out of web storage while the form still shows them', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const wrapper = mountPanel(secureDocument, secureOperation)
    await flushPromises()

    await wrapper.get('[data-testid="parameter-query-sort"]').setValue('created_at')
    await wrapper.get('[data-testid="parameter-header-X-Api-Key"]').setValue('header-secret')
    await wrapper.get('[data-testid="parameter-query-signature"]').setValue('schema-secret')
    await wrapper.get('[data-testid="parameter-query-api_token"]').setValue('scheme-secret')
    await wrapper.vm.$nextTick()

    // Still on screen: the reader is composing this request, only the copy is narrowed.
    expect(wrapper.get<HTMLInputElement>('[data-testid="parameter-header-X-Api-Key"]').element.value)
      .toBe('header-secret')

    const persisted = window.localStorage.getItem('api-dock:try-it') ?? ''

    expect(Object.keys(operationInputs.value[secureOperation.key]?.parameters ?? {}))
      .toEqual(['query:sort'])
    expect(persisted).toContain('created_at')
    expect(persisted).not.toContain('header-secret')
    expect(persisted).not.toContain('schema-secret')
    expect(persisted).not.toContain('scheme-secret')
    wrapper.unmount()
  })

  it('drops a whole body that names a credential and stores an ordinary one verbatim', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="body-editor"]').setValue('{"email":"a@b.test","password":"hunter2"}')

    // Half a body would restore as a request that cannot be sent, so none of it is kept.
    expect(operationInputs.value[operation.key]?.body).toBe('')
    expect(window.localStorage.getItem('api-dock:try-it') ?? '').not.toContain('hunter2')

    await wrapper.get('[data-testid="body-editor"]').setValue('{"q":"laptop"}')

    expect(operationInputs.value[operation.key]?.body).toBe('{"q":"laptop"}')
    wrapper.unmount()
  })

  it('stores a masked copy of a response that returned a token and shows the real one', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [] }))
      .mockResolvedValueOnce(jsonResponse({
        status: 200,
        headers: { 'content-type': 'application/json', 'set-cookie': 'session=abc', 'x-refresh-token': 'cookie-secret' },
        body: '{"access_token":"real.token.value","user":{"id":7}}',
      }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()

    // On screen it is the live response: the capture action needs the real credential.
    expect(wrapper.get('[data-testid="response-body"]').text()).toContain('real.token.value')
    expect(wrapper.find('[data-testid="use-token-as-profile"]').exists()).toBe(true)

    const persisted = window.sessionStorage.getItem('api-dock:try-it-response') ?? ''

    expect(persisted).not.toContain('real.token.value')
    expect(persisted).not.toContain('cookie-secret')
    expect(persisted).not.toContain('session=abc')
    expect(persisted).toContain('***')
    expect(persisted).toContain('content-type')
    wrapper.unmount()

    // Remounting reads the masked copy back, and the mask is not offered as a token.
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const second = mountPanel()
    await flushPromises()

    expect(second.get('[data-testid="response-body"]').text()).not.toContain('real.token.value')
    expect(second.find('[data-testid="use-token-as-profile"]').exists()).toBe(false)
    second.unmount()
  })

  it('prefills the body from a referenced schema when the spec ships no example', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const wrapper = mountPanel(schemaDocument, referencedBodyOperation())
    await flushPromises()

    expect(JSON.parse(wrapper.get<HTMLTextAreaElement>('[data-testid="body-editor"]').element.value))
      .toEqual({ phone: '', password: '' })
    wrapper.unmount()
  })

  it('prefers a hand-written AI example over the generated skeleton', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const withExample = referencedBodyOperation()
    withExample.operation['x-ai-examples'] = [
      { name: 'Unlock', request: { phone: '+905551112233' }, response: {} },
    ]
    const wrapper = mountPanel(schemaDocument, withExample)
    await flushPromises()

    expect(JSON.parse(wrapper.get<HTMLTextAreaElement>('[data-testid="body-editor"]').element.value))
      .toEqual({ phone: '+905551112233' })
    wrapper.unmount()
  })
})

const secureDocument: OpenApiDocument = {
  openapi: '3.1.0',
  servers: [{ url: 'https://acme.example.com/api' }],
  paths: {},
  components: {
    securitySchemes: {
      queryKey: { type: 'apiKey', in: 'query', name: 'api_token' },
    },
  },
}

const secureOperation: OperationEntry = {
  key: 'get:/reports',
  method: 'get',
  path: '/reports',
  operation: { operationId: 'listReports', responses: { '200': { description: 'OK' } } },
  parameters: [
    { name: 'sort', in: 'query', schema: { type: 'string', default: 'name' } },
    { name: 'X-Api-Key', in: 'header', schema: { type: 'string' } },
    { name: 'signature', in: 'query', schema: { type: 'string', format: 'password' } },
    { name: 'api_token', in: 'query', schema: { type: 'string' } },
  ],
}

const schemaDocument: OpenApiDocument = {
  openapi: '3.1.0',
  paths: {},
  components: {
    schemas: {
      ParticipantUnlockRequest: {
        type: 'object',
        properties: {
          phone: { type: 'string', maxLength: 50 },
          password: { type: ['string', 'null'], maxLength: 255 },
        },
        required: ['phone'],
      },
    },
  },
}

function referencedBodyOperation(): OperationEntry {
  return {
    key: 'post:/participants/unlock',
    method: 'post',
    path: '/participants/unlock',
    operation: {
      operationId: 'unlockParticipant',
      requestBody: {
        content: {
          'application/json': {
            schema: { $ref: '#/components/schemas/ParticipantUnlockRequest' },
          },
        },
      },
      responses: { '200': { description: 'OK' } },
    },
    parameters: [],
  }
}

function mountPanel(
  panelDocument: OpenApiDocument = document,
  panelOperation: OperationEntry = operation,
) {
  return mount(TryItPanel, {
    props: {
      document: panelDocument,
      operation: panelOperation,
      baseUrl: '/api-dock',
      csrfToken: 'csrf-token',
    },
  })
}

function tryItProfile(overrides: Record<string, unknown> = {}) {
  return {
    id: 'profile-1',
    label: 'Staging',
    base_url: '',
    scheme: 'bearer',
    credential_header: null,
    credential_hint: '****ab12',
    ...overrides,
  }
}

function jsonResponse(payload: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: vi.fn().mockResolvedValue(payload),
  } as unknown as Response
}
