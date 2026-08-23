import { ref } from 'vue'

const STORAGE_KEY = 'api-dock:try-it'

/** What the reader typed into one operation's try-it form, keyed `<in>:<name>`. */
export interface TryItOperationInputs {
  parameters: Record<string, string>
  body: string
}

interface TryItSessionState {
  selectedProfileId: string
  selectedServer: string
  serverVariables: Record<string, string>
  plainBaseUrl: string
  operations: Record<string, TryItOperationInputs>
}

const DEFAULT_STATE: TryItSessionState = {
  selectedProfileId: '',
  selectedServer: '',
  serverVariables: {},
  plainBaseUrl: '',
  operations: {},
}

/**
 * Bounds on the remembered form values. A reader who walks a large specification
 * would otherwise fill web storage one operation at a time, and a single pasted
 * body could take the whole quota by itself — losing every other stored value
 * with it, since the quota error drops the entire write.
 */
const MAX_OPERATIONS = 25

const MAX_BODY_LENGTH = 8_000

const MAX_PARAMETER_LENGTH = 2_000

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
// The form values the reader typed per operation, so re-opening an endpoint does not
// start from an empty form again. Same boundary as everything else here: this map is
// the request the reader composed, never a credential — the credential lives in the
// server-side profile and only its masked hint is ever visible to this module.
export const operationInputs = ref<Record<string, TryItOperationInputs>>(initialState.operations)

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

export function storedOperationInputs(operationKey: string): TryItOperationInputs {
  const stored = operationInputs.value[operationKey]

  return {
    parameters: { ...(stored?.parameters ?? {}) },
    body: stored?.body ?? '',
  }
}

/**
 * Replaces one operation's remembered form values. The whole entry is rewritten
 * rather than merged: a parameter the reader cleared has to disappear, and a
 * merge would keep resurrecting it.
 */
export function setOperationInputs(operationKey: string, inputs: TryItOperationInputs): void {
  if (operationKey === '') {
    return
  }

  // An empty value is KEPT: clearing a prefilled parameter is how a reader omits
  // it, and dropping the entry would restore the generated value on the next mount.
  const parameters = Object.fromEntries(
    Object.entries(inputs.parameters)
      .filter(([name, value]) => name !== '' && value.length <= MAX_PARAMETER_LENGTH),
  )
  const body = inputs.body.length <= MAX_BODY_LENGTH ? inputs.body : ''
  const next = { ...operationInputs.value }

  if (Object.keys(parameters).length === 0 && body === '') {
    delete next[operationKey]
  } else {
    // Re-inserted, so the operation the reader is on is always the newest entry
    // and the eviction below drops the one untouched longest.
    delete next[operationKey]
    next[operationKey] = { parameters, body }
  }

  const keys = Object.keys(next)

  for (const key of keys.slice(0, Math.max(0, keys.length - MAX_OPERATIONS))) {
    delete next[key]
  }

  operationInputs.value = next
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
  operationInputs.value = {}

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
      return { ...DEFAULT_STATE, serverVariables: {}, operations: {} }
    }

    const serialized = localStorage.getItem(STORAGE_KEY)

    if (serialized === null) {
      return { ...DEFAULT_STATE, serverVariables: {}, operations: {} }
    }

    const candidate: unknown = JSON.parse(serialized)

    if (!isRecord(candidate)) {
      return { ...DEFAULT_STATE, serverVariables: {}, operations: {} }
    }

    return {
      selectedProfileId: typeof candidate.selectedProfileId === 'string'
        ? candidate.selectedProfileId
        : '',
      selectedServer: typeof candidate.selectedServer === 'string' ? candidate.selectedServer : '',
      serverVariables: stringRecord(candidate.serverVariables),
      plainBaseUrl: typeof candidate.plainBaseUrl === 'string' ? candidate.plainBaseUrl : '',
      operations: operationRecord(candidate.operations),
    }
  } catch {
    return { ...DEFAULT_STATE, serverVariables: {}, operations: {} }
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
        operations: operationInputs.value,
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

function operationRecord(candidate: unknown): Record<string, TryItOperationInputs> {
  if (!isRecord(candidate)) {
    return {}
  }

  const operations: Record<string, TryItOperationInputs> = {}

  for (const [key, value] of Object.entries(candidate).slice(-MAX_OPERATIONS)) {
    if (!isRecord(value)) {
      continue
    }

    const body = typeof value.body === 'string' && value.body.length <= MAX_BODY_LENGTH
      ? value.body
      : ''
    const parameters = stringRecord(value.parameters)

    if (Object.keys(parameters).length === 0 && body === '') {
      continue
    }

    operations[key] = { parameters, body }
  }

  return operations
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
