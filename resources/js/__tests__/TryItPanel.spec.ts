import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import TryItPanel from '@/components/TryItPanel.vue'
import { resetProfiles } from '@/lib/tryItProfiles'
import {
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
})

afterEach(() => {
  resetTryItSession()
  resetProfiles()
  window.localStorage.clear()
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
})

function mountPanel(panelDocument: OpenApiDocument = document) {
  return mount(TryItPanel, {
    props: {
      document: panelDocument,
      operation,
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
