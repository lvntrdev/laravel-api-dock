import { afterEach, describe, expect, it, vi } from 'vitest'

const STORAGE_KEY = 'api-dock:try-it'

afterEach(() => {
  vi.resetModules()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('try-it session persistence', () => {
  it('restores persisted state after a simulated reload', async () => {
    const storage = storageStub()
    vi.stubGlobal('localStorage', storage)

    const session = await import('@/lib/tryItSession')
    session.setSelectedProfileId('profile-1')
    session.setSelectedServer('https://{tenant}.example.com/api')
    session.setServerVariables({ tenant: 'globex', region: 'eu-west' })
    session.setPlainBaseUrl('https://staging.example.com/api')

    vi.resetModules()
    const reloaded = await import('@/lib/tryItSession')

    expect(reloaded.selectedProfileId.value).toBe('profile-1')
    expect(reloaded.selectedServer.value).toBe('https://{tenant}.example.com/api')
    expect(reloaded.serverVariables.value).toEqual({ tenant: 'globex', region: 'eu-west' })
    expect(reloaded.plainBaseUrl.value).toBe('https://staging.example.com/api')
  })

  it('uses defaults when storage is missing', async () => {
    vi.stubGlobal('localStorage', storageStub())

    const session = await import('@/lib/tryItSession')

    expect(session.selectedProfileId.value).toBe('')
    expect(session.selectedServer.value).toBe('')
    expect(session.serverVariables.value).toEqual({})
    expect(session.plainBaseUrl.value).toBe('')
  })

  it('uses defaults without throwing when storage is malformed', async () => {
    vi.stubGlobal('localStorage', storageStub('{not-json'))

    const session = await import('@/lib/tryItSession')

    expect(session.selectedProfileId.value).toBe('')
    expect(session.selectedServer.value).toBe('')
    expect(session.serverVariables.value).toEqual({})
    expect(session.plainBaseUrl.value).toBe('')
  })

  it('drops a persisted profile id that is absent from the available profiles', async () => {
    const storage = storageStub(JSON.stringify({
      selectedProfileId: 'deleted-profile',
      serverVariables: { tenant: 'acme' },
      plainBaseUrl: 'https://staging.example.com/api',
    }))
    vi.stubGlobal('localStorage', storage)
    const session = await import('@/lib/tryItSession')

    session.discardMissingProfile(['profile-1', 'profile-2'])

    expect(session.selectedProfileId.value).toBe('')
    expect(JSON.parse(lastStoredValue(storage))).toMatchObject({ selectedProfileId: '' })
  })

  it('never writes credentials, credential header values, or credential hints to storage', async () => {
    const rawCredential = 'raw-super-secret'
    const credentialHeaderValue = 'X-Api-Key raw-header-secret'
    const credentialHint = '****ab12'
    const storage = storageStub(JSON.stringify({
      selectedProfileId: 'profile-1',
      selectedServer: 'https://{tenant}.example.com/api',
      serverVariables: { tenant: 'acme' },
      plainBaseUrl: 'https://staging.example.com/api',
      credential: rawCredential,
      credential_header: credentialHeaderValue,
      credential_hint: credentialHint,
    }))
    vi.stubGlobal('localStorage', storage)
    const session = await import('@/lib/tryItSession')

    session.setSelectedProfileId('profile-2')

    const serialized = lastStoredValue(storage)
    expect(JSON.parse(serialized)).toEqual({
      selectedProfileId: 'profile-2',
      selectedServer: 'https://{tenant}.example.com/api',
      serverVariables: { tenant: 'acme' },
      plainBaseUrl: 'https://staging.example.com/api',
    })
    expect(serialized).not.toContain(rawCredential)
    expect(serialized).not.toContain(credentialHeaderValue)
    expect(serialized).not.toContain(credentialHint)
    expect(serialized).not.toContain('credential')
    expect(serialized).not.toContain('credential_hint')
  })
})

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

function lastStoredValue(storage: Storage): string {
  const setItem = vi.mocked(storage.setItem)
  const call = setItem.mock.calls.at(-1)

  expect(call?.[0]).toBe(STORAGE_KEY)

  return String(call?.[1])
}
