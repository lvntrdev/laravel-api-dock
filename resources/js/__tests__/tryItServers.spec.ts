import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import TryItPanel from '@/components/TryItPanel.vue'
import { withCurrentOrigin } from '@/lib/tryIt'
import { resetProfiles } from '@/lib/tryItProfiles'
import { resetTryItSession } from '@/lib/tryItSession'
import type { OpenApiDocument, OperationEntry } from '@/types/openapi'

describe('withCurrentOrigin', () => {
  it('leads with the tenant subdomain the docs were opened on', () => {
    // The spec is built once from the app URL, so it names the apex even when the
    // reader is on a tenant host. Sending the request to the apex asks the wrong
    // server, which is the whole bug this closes.
    const servers = withCurrentOrigin(
      [{ url: 'https://congress-app.test/api' }],
      'https://test-kurum.congress-app.test',
    )

    expect(servers[0].url).toBe('https://test-kurum.congress-app.test/api')
    expect(servers[0].description).toBe('This site')
    expect(servers[1].url).toBe('https://congress-app.test/api')
  })

  it('keeps an unrelated host untouched', () => {
    const servers = withCurrentOrigin(
      [{ url: 'https://api.stripe.com/v1' }],
      'https://congress-app.test',
    )

    expect(servers).toHaveLength(1)
    expect(servers[0].url).toBe('https://api.stripe.com/v1')
  })

  it('leaves a templated authority to its own variables', () => {
    const servers = withCurrentOrigin(
      [{ url: 'https://{tenant}.example.com/api', variables: { tenant: { default: 'acme' } } }],
      'https://acme.example.com',
    )

    expect(servers).toHaveLength(1)
    expect(servers[0].url).toBe('https://{tenant}.example.com/api')
  })

  it('makes a relative server absolute against the current origin', () => {
    const servers = withCurrentOrigin([{ url: '/api' }], 'https://test-kurum.congress-app.test')

    expect(servers[0].url).toBe('https://test-kurum.congress-app.test/api')
  })

  it('does not duplicate a server that already names this origin', () => {
    const servers = withCurrentOrigin(
      [{ url: 'https://congress-app.test/api' }],
      'https://congress-app.test',
    )

    expect(servers).toHaveLength(1)
  })
})

const document: OpenApiDocument = {
  openapi: '3.1.0',
  servers: [{ url: 'https://congress-app.test/api' }],
  paths: {},
}

const operation: OperationEntry = {
  key: 'get:/things',
  method: 'get',
  path: '/things',
  operation: { operationId: 'listThings', responses: { '200': { description: 'OK' } } },
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

describe('request target', () => {
  it('sends the spec server and its variables instead of a resolved plain URL', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ profiles: [] }))
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(TryItPanel, {
      props: { document, operation, baseUrl: '/api-dock', csrfToken: 'csrf-token' },
    })
    await flushPromises()

    // The curl sample shows the resolved target the reader is about to hit.
    expect(wrapper.get('[data-testid="curl-sample"]').text())
      .toContain('https://congress-app.test/api/things')

    await wrapper.get('[data-testid="send-request"]').trigger('click')
    await flushPromises()

    const sent = JSON.parse(String(fetchMock.mock.calls[1][1]?.body)) as Record<string, unknown>

    // The template travels with its spec, so the proxy re-resolves and re-validates
    // it instead of trusting a URL the browser assembled.
    expect(sent.server).toBe('https://congress-app.test/api')
    expect(sent.server_variables).toEqual({})
    expect(sent.url).toBeUndefined()
    wrapper.unmount()
  })
})

function jsonResponse(payload: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: vi.fn().mockResolvedValue(payload),
  } as unknown as Response
}
