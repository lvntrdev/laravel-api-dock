/**
 * Finds the credential a login response handed back, so the reader can turn it into a
 * bearer profile without writing a Postman-style capture script. This is a HEURISTIC on
 * purpose: it reads only field names an API commonly uses for the token itself, never a
 * value that merely looks token-shaped, so a body with no such field yields nothing
 * rather than a guess the reader would have to notice was wrong.
 */

/** Field names that hold the credential, in the order they are preferred. */
const TOKEN_KEYS = [
  'access_token',
  'accessToken',
  'token',
  'id_token',
  'idToken',
  'jwt',
  'bearer',
  'plainTextToken', // Sanctum's NewAccessToken shape.
]

/** A nested `data`/`result` envelope is the common wrapper; deeper than this is guesswork. */
const MAX_DEPTH = 4

const MAX_TOKEN_LENGTH = 4_000

export function extractToken(body: string): string | null {
  let parsed: unknown

  try {
    parsed = JSON.parse(body) as unknown
  } catch {
    return null
  }

  return search(parsed, 0)
}

function search(value: unknown, depth: number): string | null {
  if (depth > MAX_DEPTH || !isRecord(value)) {
    return null
  }

  for (const key of TOKEN_KEYS) {
    const candidate = value[key]

    if (typeof candidate === 'string' && candidate !== '' && candidate.length <= MAX_TOKEN_LENGTH) {
      return candidate
    }

    // `access_token: { token: '…' }` — the named field wins over a sibling envelope, so
    // it is followed before the generic walk below.
    if (isRecord(candidate)) {
      const nested = search(candidate, depth + 1)

      if (nested !== null) {
        return nested
      }
    }
  }

  for (const nested of Object.values(value)) {
    const found = search(nested, depth + 1)

    if (found !== null) {
      return found
    }
  }

  return null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** What a masked value reads as in the stored copy of a response. */
export const REDACTED = '***'

/**
 * Field names that carry a credential rather than data — wider than `TOKEN_KEYS`,
 * because this list guards what is WRITTEN to web storage rather than what is offered
 * as a profile: a request body's `password` is a secret even though it is never a
 * bearer token.
 *
 * ponytail: name matching only. A password field the schema does not mark and this
 * list does not name is stored as typed; the upgrade path is the schema itself
 * (`format: password`, `writeOnly`), which `classifyInputs` consults first.
 */
const SECRET_KEYS = new Set([
  ...TOKEN_KEYS,
  'password',
  'passwd',
  'secret',
  'client_secret',
  'api_key',
  'apiKey',
  'refresh_token',
  'private_key',
].map((name) => name.toLowerCase()))

export function isSecretKey(name: string): boolean {
  return SECRET_KEYS.has(name.toLowerCase())
}

/**
 * True when the JSON body names a credential anywhere the walk reaches. Used on the
 * REQUEST side, where the answer is all-or-nothing: a body with a masked field would
 * restore as a request that cannot be sent, so the caller drops the whole body.
 */
export function hasSecretKey(body: string): boolean {
  let parsed: unknown

  try {
    parsed = JSON.parse(body) as unknown
  } catch {
    return false
  }

  try {
    return containsSecret(parsed)
  } catch {
    // The walk ran out of stack on a deeply nested body. Unwalked means unknown, and
    // unknown is treated as a credential: the caller drops the body rather than
    // storing one it could not read.
    return true
  }
}

/**
 * The same body with every credential field masked, for the RESPONSE side: the rest of
 * the payload is what makes a stored response worth keeping, and only the credential
 * has to go. A body that is not JSON is returned untouched — there is nothing to walk,
 * and rewriting it would corrupt what the reader saw.
 */
export function redactTokens(body: string): string {
  let parsed: unknown

  try {
    parsed = JSON.parse(body) as unknown
  } catch {
    // A body that LOOKS like JSON but does not parse — a truncated payload, most
    // often — can still carry a whole token before the cut. It cannot be walked, so
    // nothing of it is kept. Plain text and HTML have no structure to mask and are
    // returned as they were.
    return looksLikeJson(body) ? '' : body
  }

  try {
    return JSON.stringify(redactValue(parsed)) ?? body
  } catch {
    // Same stack exhaustion as above, on the response side: a body that could not be
    // masked is not stored at all.
    return ''
  }
}

function looksLikeJson(body: string): boolean {
  const first = body.trimStart().charAt(0)

  return first === '{' || first === '['
}

// Neither walk below is depth-limited, unlike the token DISCOVERY above: a credential
// four levels down is offered as a profile no more, but it must still never reach
// storage. The bodies these run on are already capped by their callers.
//
// ponytail: recursive, so a body nested deeper than the JS stack allows (a few
// thousand levels — well inside the size cap) raises `RangeError`. Both callers catch
// it and discard the body, which is the safe direction; an iterative walk is the
// upgrade path if a real payload ever nests that deep.
function containsSecret(value: unknown): boolean {
  // Arrays are walked too: a list of accounts hides the same field one level deeper.
  if (Array.isArray(value)) {
    return value.some((item) => containsSecret(item))
  }

  if (!isRecord(value)) {
    return false
  }

  return Object.entries(value).some(
    ([key, nested]) => isSecretKey(key) || containsSecret(nested),
  )
}

function redactValue(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => redactValue(item))
  }

  if (!isRecord(value)) {
    return value
  }

  return Object.fromEntries(Object.entries(value).map(([key, nested]) => {
    // A numeric credential (a PIN, a one-time code) is masked as well; an object under
    // a credential name is walked instead, so `access_token: { token }` still loses the
    // string inside it.
    const scalar = typeof nested === 'string' || typeof nested === 'number'

    return [key, isSecretKey(key) && scalar ? REDACTED : redactValue(nested)]
  }))
}
