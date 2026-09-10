import { afterEach, describe, expect, it, vi } from 'vitest'

import type { StoredTryItResponse } from '@/lib/tryItResponses'

const STORAGE_KEY = 'api-dock:try-it-response'

afterEach(() => {
  vi.resetModules()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('try-it response persistence', () => {
  it('drops responses another account left in this tab', async () => {
    const storage = storageStub(JSON.stringify({
      identity: 'account-a',
      responses: { 'get:/orders': response() },
    }))
    vi.stubGlobal('sessionStorage', storage)
    const responses = await import('@/lib/tryItResponses')

    responses.bindIdentity('account-b')

    expect(storage.getItem(STORAGE_KEY)).toBeNull()
    expect(responses.storedResponse('get:/orders')).toBeUndefined()
  })

  it('drops an envelope written before identities were stamped', async () => {
    // The legacy shape kept responses at the top level with no stamp; a guest binding
    // '' must not inherit what a logged-in reader left behind.
    const storage = storageStub(JSON.stringify({ 'get:/orders': response() }))
    vi.stubGlobal('sessionStorage', storage)
    const responses = await import('@/lib/tryItResponses')

    responses.bindIdentity('')

    expect(storage.getItem(STORAGE_KEY)).toBeNull()
    expect(responses.storedResponse('get:/orders')).toBeUndefined()
  })

  it('keeps and stamps what the bound account itself stored', async () => {
    const storage = storageStub(JSON.stringify({
      identity: 'account-a',
      responses: { 'get:/orders': response() },
    }))
    vi.stubGlobal('sessionStorage', storage)
    const responses = await import('@/lib/tryItResponses')

    responses.bindIdentity('account-a')

    expect(responses.storedResponse('get:/orders')?.body).toBe('{"ok":true}')

    responses.setStoredResponse('get:/invoices', response())

    expect(JSON.parse(String(storage.getItem(STORAGE_KEY)))).toMatchObject({
      identity: 'account-a',
    })
    expect(responses.storedResponse('get:/invoices')?.status).toBe(200)
  })

  it('stores no body of a truncated response and no credential-bearing header', async () => {
    const storage = storageStub(null)
    vi.stubGlobal('sessionStorage', storage)
    const responses = await import('@/lib/tryItResponses')

    responses.bindIdentity('')
    responses.setStoredResponse('post:/login', {
      ...response(),
      headers: {
        'content-type': 'application/json',
        'x-api-key': 'live-key',
        'x-session-id': 'sess',
        'set-cookie': 'refresh=abc',
      },
      body: '{"access_token":"secret-cut","profile":{"na',
      truncated: true,
    })

    const stored = responses.storedResponse('post:/login')

    expect(stored?.body).toBe('')
    expect(stored?.headers).toEqual({ 'content-type': 'application/json' })
    expect(String(storage.getItem(STORAGE_KEY))).not.toContain('live-key')
    expect(String(storage.getItem(STORAGE_KEY))).not.toContain('secret-cut')
  })
})

function response(): StoredTryItResponse {
  return {
    status: 200,
    headers: { 'content-type': 'application/json' },
    body: '{"ok":true}',
    truncated: false,
    host: 'api.example.com',
    url: 'https://api.example.com/orders',
    elapsedMs: 12,
  }
}

function storageStub(initialValue: string | null = null): Storage {
  let value = initialValue

  return {
    get length() {
      return value === null ? 0 : 1
    },
    clear: vi.fn(() => {
      value = null
    }),
    getItem: vi.fn((key: string) => key === STORAGE_KEY ? value : null),
    key: vi.fn((index: number) => index === 0 && value !== null ? STORAGE_KEY : null),
    removeItem: vi.fn((key: string) => {
      if (key === STORAGE_KEY) {
        value = null
      }
    }),
    setItem: vi.fn((key: string, nextValue: string) => {
      if (key === STORAGE_KEY) {
        value = nextValue
      }
    }),
  }
}
