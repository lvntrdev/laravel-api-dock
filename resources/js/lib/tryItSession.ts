import { ref } from 'vue'

import { hasSecretKey, isSecretKey } from '@/lib/tryItToken'
import type { ParameterObject, SchemaObject } from '@/types/openapi'

const STORAGE_KEY = 'api-dock:try-it'

/**
 * The typed-in drafts live in their own TAB-scoped key, alongside the responses in
 * `tryItResponses`. A draft IS the request the reader composed — a participant id, a
 * device token, a real payload — and there is no reason for it to outlive the tab on
 * disk, where the next person on the same browser profile would find it. Only the UI
 * preferences above stay in `localStorage`.
 */
const DRAFT_KEY = 'api-dock:try-it-draft'

/** What the reader typed into one operation's try-it form, keyed `<in>:<name>`. */
export interface TryItOperationInputs {
  parameters: Record<string, string>
  body: string
}

interface TryItSessionState {
  identity: string
  selectedProfileId: string
  selectedServer: string
  serverVariables: Record<string, string>
  plainBaseUrl: string
}

/** The tab-scoped half: the form values the reader typed, per operation. */
interface TryItDraftState {
  identity: string
  operations: Record<string, TryItOperationInputs>
}

const DEFAULT_STATE: TryItSessionState = {
  identity: '',
  selectedProfileId: '',
  selectedServer: '',
  serverVariables: {},
  plainBaseUrl: '',
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

const initialDrafts = storedDrafts()

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
export const operationInputs = ref<Record<string, TryItOperationInputs>>(initialDrafts.operations)

/**
 * Which account this browser's stored state belongs to. An opaque stamp the server
 * derives per request — never the user id itself — so two accounts sharing a browser
 * cannot read each other's remembered requests. The guest stamp is `''`, and it
 * matches only another guest.
 */
let boundIdentity = ''

/**
 * Binds the store to the account the current request authenticated as, purging the
 * stored state when it belonged to anyone else. An envelope with NO identity is
 * purged too: it predates this check, so there is nothing to prove it was written
 * by whoever is reading now.
 */
export function bindIdentity(identity: string): void {
  boundIdentity = identity

  // Gated per store rather than once for both: the drafts are gone after a tab close
  // by design, and their missing envelope must not take the surviving preferences
  // with it.
  if (storedIdentity(localStore(), STORAGE_KEY) !== identity) {
    resetPreferences()
  } else if (readEnvelope(localStore(), STORAGE_KEY)?.operations !== undefined) {
    // Drafts an older version left on disk. Rewriting the envelope now is what
    // actually removes them: nothing else here writes until a preference changes.
    persistPreferences()
  }

  if (storedIdentity(sessionStore(), DRAFT_KEY) !== identity) {
    resetDrafts()
  }
}

export function setSelectedProfileId(profileId: string): void {
  selectedProfileId.value = profileId
  persistPreferences()
}

export function setSelectedServer(serverUrl: string): void {
  selectedServer.value = serverUrl
  persistPreferences()
}

export function setServerVariables(values: Record<string, string>): void {
  serverVariables.value = {
    ...serverVariables.value,
    ...values,
  }
  persistPreferences()
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
  persistPreferences()
}

export function storedOperationInputs(operationKey: string): TryItOperationInputs {
  const stored = operationInputs.value[operationKey]

  return {
    parameters: { ...(stored?.parameters ?? {}) },
    body: stored?.body ?? '',
  }
}

/**
 * Narrows a form snapshot to the inputs that may outlive the tab. Pure, and applied by
 * the caller BEFORE `setOperationInputs`: the panel keeps showing everything the reader
 * typed, only the copy that survives a reload is cut down.
 *
 * Header parameters go as a class — an api key, a bearer value and a signed hash all
 * arrive that way, and none of them belongs in `localStorage`. The body goes WHOLE
 * rather than field by field: a body with one field masked restores as a request that
 * cannot be sent, which is worse than an empty one the reader retypes.
 */
export function classifyInputs(
  inputs: TryItOperationInputs,
  parameters: readonly ParameterObject[],
  securitySchemes: unknown,
): TryItOperationInputs {
  const apiKeys = apiKeyNames(securitySchemes)
  const secretKeys = new Set(
    parameters
      .filter((parameter) => isSecretParameter(parameter, apiKeys))
      .map((parameter) => `${parameter.in}:${parameter.name}`),
  )

  return {
    // The `header:` prefix is checked on the key itself, not only through the list
    // above: a key whose parameter the current document no longer declares still
    // names its location, and it must not slip through on that gap.
    parameters: Object.fromEntries(
      Object.entries(inputs.parameters)
        .filter(([key]) => !key.startsWith('header:') && !secretKeys.has(key)),
    ),
    body: hasSecretKey(inputs.body) ? '' : inputs.body,
  }
}

function isSecretParameter(parameter: ParameterObject, apiKeys: ReadonlySet<string>): boolean {
  const schema = parameterSchema(parameter)

  return parameter.in === 'header'
    || schema?.format === 'password'
    || schema?.writeOnly === true
    // A declared api key travels as a plain parameter; the scheme is what names it.
    || apiKeys.has(parameter.name)
    || isSecretKey(parameter.name)
}

/** A `$ref` schema is left unresolved on purpose: nothing here needs to follow it, and
 * an unreadable schema only means the name check below decides alone. */
function parameterSchema(parameter: ParameterObject): SchemaObject | undefined {
  return parameter.schema !== undefined && !('$ref' in parameter.schema)
    ? parameter.schema
    : undefined
}

function apiKeyNames(securitySchemes: unknown): ReadonlySet<string> {
  const names = new Set<string>()

  if (!isRecord(securitySchemes)) {
    return names
  }

  for (const scheme of Object.values(securitySchemes)) {
    if (isRecord(scheme) && scheme.type === 'apiKey' && typeof scheme.name === 'string') {
      names.add(scheme.name)
    }
  }

  return names
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
  persistDrafts()
}

export function discardMissingProfile(profileIds: readonly string[]): void {
  if (selectedProfileId.value !== '' && !profileIds.includes(selectedProfileId.value)) {
    setSelectedProfileId('')
  }
}

export function resetTryItSession(): void {
  resetPreferences()
  resetDrafts()
}

function resetPreferences(): void {
  selectedProfileId.value = DEFAULT_STATE.selectedProfileId
  selectedServer.value = DEFAULT_STATE.selectedServer
  serverVariables.value = {}
  plainBaseUrl.value = DEFAULT_STATE.plainBaseUrl
  remove(localStore(), STORAGE_KEY)
}

function resetDrafts(): void {
  operationInputs.value = {}
  remove(sessionStore(), DRAFT_KEY)
}

/**
 * The identity on a stored envelope, or `null` when there is no envelope or it
 * carries none. `null` is deliberately distinct from `''`: a guest must not inherit
 * an unstamped envelope a logged-in reader may have left behind.
 */
function storedIdentity(storage: Storage | undefined, key: string): string | null {
  const envelope = readEnvelope(storage, key)

  return envelope !== undefined && typeof envelope.identity === 'string'
    ? envelope.identity
    : null
}

function storedState(): TryItSessionState {
  const candidate = readEnvelope(localStore(), STORAGE_KEY)

  if (candidate === undefined) {
    return { ...DEFAULT_STATE, serverVariables: {} }
  }

  return {
    identity: typeof candidate.identity === 'string' ? candidate.identity : '',
    selectedProfileId: typeof candidate.selectedProfileId === 'string'
      ? candidate.selectedProfileId
      : '',
    selectedServer: typeof candidate.selectedServer === 'string' ? candidate.selectedServer : '',
    serverVariables: stringRecord(candidate.serverVariables),
    plainBaseUrl: typeof candidate.plainBaseUrl === 'string' ? candidate.plainBaseUrl : '',
  }
}

function storedDrafts(): TryItDraftState {
  const candidate = readEnvelope(sessionStore(), DRAFT_KEY)

  if (candidate === undefined) {
    return { identity: '', operations: {} }
  }

  return {
    identity: typeof candidate.identity === 'string' ? candidate.identity : '',
    operations: operationRecord(candidate.operations),
  }
}

function persistPreferences(): void {
  write(localStore(), STORAGE_KEY, {
    identity: boundIdentity,
    selectedProfileId: selectedProfileId.value,
    selectedServer: selectedServer.value,
    serverVariables: serverVariables.value,
    plainBaseUrl: plainBaseUrl.value,
  } satisfies TryItSessionState)
}

function persistDrafts(): void {
  write(sessionStore(), DRAFT_KEY, {
    identity: boundIdentity,
    operations: operationInputs.value,
  } satisfies TryItDraftState)
}

/** Storage can be unavailable — or throw on access — without making the try-it
 * session unavailable, so every store is reached through these four wrappers. */
function localStore(): Storage | undefined {
  try {
    return typeof localStorage === 'undefined' ? undefined : localStorage
  } catch {
    return undefined
  }
}

function sessionStore(): Storage | undefined {
  try {
    return typeof sessionStorage === 'undefined' ? undefined : sessionStorage
  } catch {
    return undefined
  }
}

function readEnvelope(
  storage: Storage | undefined,
  key: string,
): Record<string, unknown> | undefined {
  try {
    const serialized = storage?.getItem(key) ?? null

    if (serialized === null) {
      return undefined
    }

    const candidate: unknown = JSON.parse(serialized)

    return isRecord(candidate) ? candidate : undefined
  } catch {
    return undefined
  }
}

function write(storage: Storage | undefined, key: string, value: unknown): void {
  try {
    storage?.setItem(key, JSON.stringify(value))
  } catch {
    // A full or blocked quota must not break the panel that is using it.
  }
}

function remove(storage: Storage | undefined, key: string): void {
  try {
    storage?.removeItem(key)
  } catch {
    // Same: an unavailable store is already in the state a purge wants.
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
