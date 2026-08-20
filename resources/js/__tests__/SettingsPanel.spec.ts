import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import SettingsPanel from '@/components/SettingsPanel.vue'
import TryItPanel from '@/components/TryItPanel.vue'
import { loadProfiles, profiles, resetProfiles } from '@/lib/tryItProfiles'
import { resetTryItSession, selectedProfileId } from '@/lib/tryItSession'
import type { OpenApiDocument, OperationEntry } from '@/types/openapi'

const apiDocument: OpenApiDocument = {
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

const serverlessDocument: OpenApiDocument = { ...apiDocument, servers: [] }

const operation: OperationEntry = {
  key: 'post:/users/{id}',
  method: 'post',
  path: '/users/{id}',
  operation: {
    operationId: 'updateUser',
    responses: { '200': { description: 'OK' } },
  },
  parameters: [],
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

describe('SettingsPanel', () => {
  it('keeps credentials out of web storage and clears the input after submit', async () => {
    const storageWrite = vi.spyOn(Storage.prototype, 'setItem')
    const rawCredential = 'raw-super-secret'
    const profile = storedProfile({ id: 'profile-1', base_url: 'https://staging.example.com' })
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [] }))
      .mockResolvedValueOnce(jsonResponse({ profile }, 201))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountSettings()
    await flushPromises()

    await wrapper.get('[data-testid="credential-input"]').setValue(rawCredential)
    await wrapper.get('.profile-form').trigger('submit')
    await flushPromises()

    // The write-only value leaves component state whether or not the request landed.
    expect(wrapper.get<HTMLInputElement>('[data-testid="credential-input"]').element.value).toBe('')
    for (const [, value] of storageWrite.mock.calls) {
      expect(String(value)).not.toContain(rawCredential)
      expect(String(value)).not.toContain(profile.credential_hint)
    }
    expect(window.localStorage.getItem(rawCredential)).toBeNull()
    expect(window.sessionStorage.getItem(rawCredential)).toBeNull()

    const createRequest = fetchMock.mock.calls[1]
    expect(createRequest[0]).toBe('/api-dock/try-it/profiles')
    expect(createRequest[1]?.headers).toMatchObject({ 'X-CSRF-TOKEN': 'csrf-token' })
    wrapper.unmount()
  })

  it('posts the server variables alongside the base URL and lists the created profile', async () => {
    const profile = storedProfile({
      id: 'profile-1',
      label: 'Globex',
      base_url: 'https://globex.example.com/api',
      server_variables: { tenant: 'globex' },
    })
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [] }))
      .mockResolvedValueOnce(jsonResponse({ profile }, 201))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountSettings()
    await flushPromises()

    await wrapper.get('[data-testid="profile-base-url"]').setValue('https://globex.example.com/api')
    await wrapper.get('[data-testid="profile-server-variable-tenant"]').setValue('globex')
    await wrapper.get('[data-testid="credential-input"]').setValue('raw-super-secret')
    await wrapper.get('.profile-form').trigger('submit')
    await flushPromises()

    const sent = JSON.parse(String(fetchMock.mock.calls[1][1]?.body)) as Record<string, unknown>

    expect(sent.base_url).toBe('https://globex.example.com/api')
    expect(sent.server_variables).toMatchObject({ tenant: 'globex', region: 'us-east' })
    expect(wrapper.get('[data-testid="profile-list"]').text()).toContain(profile.label)
    expect(wrapper.get('[data-testid="profile-list"]').text()).toContain(profile.credential_hint)
    wrapper.unmount()
  })

  it('offers the base URL override even when the document declares servers', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [] })))
    const wrapper = mountSettings()
    await flushPromises()

    expect(wrapper.get<HTMLInputElement>('[data-testid="resolved-base-url"]').element.value)
      .toBe('https://acme.example.com/api')

    await wrapper.get('[data-testid="base-url-input"]').setValue('https://test-kurum.example.com/api')

    // The override outranks the template, and the read-only preview says so.
    expect(wrapper.get<HTMLInputElement>('[data-testid="resolved-base-url"]').element.value)
      .toBe('https://test-kurum.example.com/api')
    wrapper.unmount()
  })

  it('keeps the selection when another profile is deleted', async () => {
    const kept = storedProfile({ id: 'profile-1', label: 'Staging' })
    const removed = storedProfile({ id: 'profile-2', label: 'Sandbox' })
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ profiles: [kept, removed] }))
      .mockResolvedValueOnce(jsonResponse({ deleted: true }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountSettings()
    await flushPromises()

    await wrapper.get('[data-testid="select-profile-profile-1"]').trigger('click')
    expect(selectedProfileId.value).toBe(kept.id)

    await wrapper.get('[data-testid="delete-profile-profile-2"]').trigger('click')
    await flushPromises()

    // Deleting B must not silently deselect A: the next request would go out
    // unauthenticated against A's target.
    expect(selectedProfileId.value).toBe(kept.id)
    expect(profiles.value.map((profile) => profile.id)).toEqual([kept.id])
    wrapper.unmount()
  })

  it('ignores a profile list response that a newer request has already replaced', async () => {
    const stale = storedProfile({ id: 'profile-stale', label: 'Stale' })
    const fresh = storedProfile({ id: 'profile-fresh', label: 'Fresh' })
    let releaseStale = (): void => {}
    const staleResponse = new Promise<Response>((resolve) => {
      releaseStale = () => resolve(jsonResponse({ profiles: [stale] }))
    })
    vi.stubGlobal('fetch', vi.fn()
      .mockReturnValueOnce(staleResponse)
      .mockResolvedValueOnce(jsonResponse({ profiles: [fresh] })))
    const target = { baseUrl: '/api-dock', csrfToken: 'csrf-token' }

    const first = loadProfiles(target)
    const second = loadProfiles(target)
    await second
    releaseStale()
    await first

    expect(profiles.value.map((profile) => profile.id)).toEqual([fresh.id])
  })

  it('shows the request target the selected profile filled in', async () => {
    const profile = storedProfile({
      id: 'profile-1',
      base_url: 'https://staging.example.com/api',
    })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ profiles: [profile] })))
    // The try-it panel owns the selection; the target it produces is read here, which
    // is the whole point of the shared session.
    const panel = mount(TryItPanel, {
      props: {
        document: serverlessDocument,
        operation,
        baseUrl: '/api-dock',
        csrfToken: 'csrf-token',
      },
    })
    await flushPromises()
    await panel.get('[data-testid="profile-select"]').setValue(profile.id)
    panel.unmount()

    const wrapper = mountSettings(serverlessDocument)
    await flushPromises()

    expect(wrapper.get<HTMLInputElement>('[data-testid="base-url-input"]').element.value)
      .toBe(profile.base_url)
    expect(wrapper.get<HTMLInputElement>('[data-testid="resolved-base-url"]').element.value)
      .toBe(profile.base_url)
    wrapper.unmount()
  })
})

function mountSettings(panelDocument: OpenApiDocument = apiDocument) {
  return mount(SettingsPanel, {
    props: {
      document: panelDocument,
      baseUrl: '/api-dock',
      csrfToken: 'csrf-token',
    },
  })
}

function storedProfile(overrides: Record<string, unknown> = {}) {
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
