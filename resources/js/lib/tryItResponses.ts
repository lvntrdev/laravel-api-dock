import { redactTokens } from '@/lib/tryItToken'

const STORAGE_KEY = 'api-dock:try-it-response'

/**
 * The last proxy result for one operation, so returning to an endpoint still shows
 * what it answered. Tab-scoped on purpose: this is real response data from the
 * reader's own API, and sessionStorage drops it when the tab closes instead of
 * leaving it on disk.
 */
export interface StoredTryItResponse {
  status: number
  headers: Record<string, string>
  body: string
  truncated: boolean
  host: string
  url: string
  elapsedMs: number
}

// A response body is far larger than a form value, so far fewer of them are kept.
const MAX_OPERATIONS = 5

const MAX_BODY_LENGTH = 20_000

/**
 * Which account the stored responses belong to. An opaque stamp the server derives
 * per request — never the user id itself. Response bodies are the reader's own API
 * data, so a second account on the same browser must not see the first one's.
 * The guest stamp is `''`, and it matches only another guest.
 */
let boundIdentity = ''

/**
 * Binds the store to the account the current request authenticated as, dropping
 * stored responses that belonged to anyone else. An envelope with NO identity goes
 * too: it predates this check, so nothing proves who wrote it.
 */
export function bindIdentity(identity: string): void {
  const previous = storedEnvelopeIdentity()
  boundIdentity = identity

  if (previous === identity) {
    return
  }

  try {
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.removeItem(STORAGE_KEY)
    }
  } catch {
    // Storage can be unavailable without making the try-it panel unavailable.
  }
}

// Read from storage on every access rather than cached in the module: one small JSON
// parse per operation mount, and no in-memory copy to keep in step with the tab.
export function storedResponse(operationKey: string): StoredTryItResponse | undefined {
  return readState()[operationKey]
}

export function setStoredResponse(
  operationKey: string,
  response: StoredTryItResponse | undefined,
): void {
  if (operationKey === '') {
    return
  }

  const next = readState()

  // Re-inserted so the operation just used is the newest entry and the eviction
  // below drops the one untouched longest.
  delete next[operationKey]

  const stored = response === undefined ? undefined : redactResponse(response)

  if (stored !== undefined && stored.body.length <= MAX_BODY_LENGTH) {
    next[operationKey] = stored
  }

  const keys = Object.keys(next)

  for (const key of keys.slice(0, Math.max(0, keys.length - MAX_OPERATIONS))) {
    delete next[key]
  }

  persistState(next)
}

/**
 * The copy that reaches storage carries no credential: a login response has its token
 * fields masked and the headers that hand one back are dropped. A NEW object every
 * time — the one the panel is holding is untouched, so the response on screen stays
 * the real one until the panel is remounted.
 */
function redactResponse(response: StoredTryItResponse): StoredTryItResponse {
  return {
    ...response,
    headers: Object.fromEntries(
      Object.entries(response.headers).filter(([name]) => !isSecretHeader(name)),
    ),
    // A truncated body was cut mid-payload by the proxy: it may hold a complete token
    // in front of the cut and cannot be walked, so none of it is stored.
    body: response.truncated ? '' : redactTokens(response.body),
  }
}

// Wide on purpose: the response headers are only kept for reading, and a header whose
// name so much as hints at a credential (`X-Api-Key`, `X-Session-Id`, a refresh cookie)
// is worth less than the risk of writing it.
const SECRET_HEADER_FRAGMENTS = ['auth', 'token', 'key', 'secret', 'cookie', 'session', 'credential']

function isSecretHeader(name: string): boolean {
  const lowered = name.toLowerCase()

  return SECRET_HEADER_FRAGMENTS.some((fragment) => lowered.includes(fragment))
}

/** The identity on the stored envelope, or `null` when there is none to read. */
function storedEnvelopeIdentity(): string | null {
  const envelope = readEnvelope()

  return envelope !== undefined && typeof envelope.identity === 'string'
    ? envelope.identity
    : null
}

function readEnvelope(): Record<string, unknown> | undefined {
  try {
    if (typeof sessionStorage === 'undefined') {
      return undefined
    }

    const serialized = sessionStorage.getItem(STORAGE_KEY)

    if (serialized === null) {
      return undefined
    }

    const candidate: unknown = JSON.parse(serialized)

    return isRecord(candidate) ? candidate : undefined
  } catch {
    return undefined
  }
}

function readState(): Record<string, StoredTryItResponse> {
  const envelope = readEnvelope()

  // The identity gate also rejects an envelope from before identities existed: its
  // responses sit at the top level, so there is nothing here to read them from.
  if (envelope === undefined || envelope.identity !== boundIdentity) {
    return {}
  }

  const responses: Record<string, StoredTryItResponse> = {}

  if (isRecord(envelope.responses)) {
    for (const [key, value] of Object.entries(envelope.responses).slice(-MAX_OPERATIONS)) {
      const response = normalize(value)

      if (response !== undefined) {
        responses[key] = response
      }
    }
  }

  return responses
}

function persistState(responses: Record<string, StoredTryItResponse>): void {
  try {
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ identity: boundIdentity, responses }),
      )
    }
  } catch {
    // Storage can be unavailable without making the try-it panel unavailable.
  }
}

function normalize(candidate: unknown): StoredTryItResponse | undefined {
  if (!isRecord(candidate) || typeof candidate.body !== 'string') {
    return undefined
  }

  if (candidate.body.length > MAX_BODY_LENGTH) {
    return undefined
  }

  return {
    status: typeof candidate.status === 'number' ? candidate.status : 0,
    headers: isRecord(candidate.headers)
      ? Object.fromEntries(
          Object.entries(candidate.headers).map(([name, value]) => [name, String(value)]),
        )
      : {},
    body: candidate.body,
    truncated: candidate.truncated === true,
    host: typeof candidate.host === 'string' ? candidate.host : '',
    url: typeof candidate.url === 'string' ? candidate.url : '',
    elapsedMs: typeof candidate.elapsedMs === 'number' ? candidate.elapsedMs : 0,
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
