import { ref } from 'vue'

const STORAGE_KEY = 'api-dock:try-it'

interface TryItSessionState {
  selectedProfileId: string
  selectedServer: string
  serverVariables: Record<string, string>
  plainBaseUrl: string
}

const DEFAULT_STATE: TryItSessionState = {
  selectedProfileId: '',
  selectedServer: '',
  serverVariables: {},
  plainBaseUrl: '',
}

// Security boundary: persist only the profile id, the selected server template, server
// variables, and the plain base URL — all of them non-secret. Credentials, credential
// header values, and credential_hint must never enter this module.
const initialState = storedState()

export const selectedProfileId = ref(initialState.selectedProfileId)
// The server template the reader picked, stored as its own url so a spec that reorders
// its servers cannot silently move the request to a different host.
export const selectedServer = ref(initialState.selectedServer)
export const serverVariables = ref<Record<string, string>>(initialState.serverVariables)
export const plainBaseUrl = ref(initialState.plainBaseUrl)

export function setSelectedProfileId(profileId: string): void {
  selectedProfileId.value = profileId
  persistState()
}

export function setSelectedServer(serverUrl: string): void {
  selectedServer.value = serverUrl
  persistState()
}

export function setServerVariables(values: Record<string, string>): void {
  serverVariables.value = {
    ...serverVariables.value,
    ...values,
  }
  persistState()
}

export function ensureServerVariables(defaults: Record<string, string>): void {
  const missing = Object.fromEntries(
    Object.entries(defaults).filter(([name]) => !Object.hasOwn(serverVariables.value, name)),
  )

  if (Object.keys(missing).length > 0) {
    setServerVariables(missing)
  }
}

export function setServerVariable(name: string, value: string): void {
  setServerVariables({ [name]: value })
}

export function setPlainBaseUrl(baseUrl: string): void {
  plainBaseUrl.value = baseUrl
  persistState()
}

export function discardMissingProfile(profileIds: readonly string[]): void {
  if (selectedProfileId.value !== '' && !profileIds.includes(selectedProfileId.value)) {
    setSelectedProfileId('')
  }
}

export function resetTryItSession(): void {
  selectedProfileId.value = DEFAULT_STATE.selectedProfileId
  selectedServer.value = DEFAULT_STATE.selectedServer
  serverVariables.value = {}
  plainBaseUrl.value = DEFAULT_STATE.plainBaseUrl

  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.removeItem(STORAGE_KEY)
    }
  } catch {
    // Storage can be unavailable without making the try-it session unavailable.
  }
}

function storedState(): TryItSessionState {
  try {
    if (typeof localStorage === 'undefined') {
      return { ...DEFAULT_STATE, serverVariables: {} }
    }

    const serialized = localStorage.getItem(STORAGE_KEY)

    if (serialized === null) {
      return { ...DEFAULT_STATE, serverVariables: {} }
    }

    const candidate: unknown = JSON.parse(serialized)

    if (!isRecord(candidate)) {
      return { ...DEFAULT_STATE, serverVariables: {} }
    }

    return {
      selectedProfileId: typeof candidate.selectedProfileId === 'string'
        ? candidate.selectedProfileId
        : '',
      selectedServer: typeof candidate.selectedServer === 'string' ? candidate.selectedServer : '',
      serverVariables: stringRecord(candidate.serverVariables),
      plainBaseUrl: typeof candidate.plainBaseUrl === 'string' ? candidate.plainBaseUrl : '',
    }
  } catch {
    return { ...DEFAULT_STATE, serverVariables: {} }
  }
}

function persistState(): void {
  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        selectedProfileId: selectedProfileId.value,
        selectedServer: selectedServer.value,
        serverVariables: serverVariables.value,
        plainBaseUrl: plainBaseUrl.value,
      } satisfies TryItSessionState))
    }
  } catch {
    // Storage can be unavailable without making the try-it session unavailable.
  }
}

function stringRecord(candidate: unknown): Record<string, string> {
  if (!isRecord(candidate)) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(candidate).filter((entry): entry is [string, string] => {
      return typeof entry[1] === 'string'
    }),
  )
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
