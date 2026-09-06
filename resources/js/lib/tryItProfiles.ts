import { ref } from 'vue'

import { t } from '@/lib/i18n'
import type { TryItProfile } from '@/lib/tryIt'
import { discardMissingProfile, setSelectedProfileId } from '@/lib/tryItSession'

export interface StoredTryItProfile extends TryItProfile {
  server_variables?: Record<string, string>
}

/**
 * Where this installation keeps profiles, mirroring `AuthProfileStore::MODE_*`. The
 * panel never chooses it — the server states it on the mount element — and it changes
 * nothing but what the panel PROMISES the reader about a credential's lifetime.
 */
export type ProfileStorageMode = 'session' | 'persistent'

/**
 * Used when the mount element names the persistent mode without a lifetime, which an
 * older published `docs.blade.php` would. It matches the shipped config default
 * (`try_it.profile_persistence.ttl_minutes`), so the sentence keeps a plausible number
 * rather than a hole; the warning itself does not depend on it.
 */
export const DEFAULT_PROFILE_LIFETIME_MINUTES = 60 * 24 * 30

export interface ProfileCredentialInput {
  label: string
  baseUrl: string
  serverVariables: Record<string, string>
  scheme: TryItProfile['scheme']
  credential: string
  credentialHeader: string
}

export interface ProfileEndpoint {
  baseUrl: string
  csrfToken: string
}

/**
 * The profile list is shared: the settings panel manages it and every try-it panel
 * reads it, so one fetch serves the whole page. It is in-memory only — nothing here
 * is written to web storage, and the list never carries a usable credential anyway;
 * the server returns a masked hint.
 */
export const profiles = ref<StoredTryItProfile[]>([])
export const loadingProfiles = ref(false)
export const profileError = ref('')
export const profileDenied = ref('')

// Both panels load on mount, so two requests can be in flight at once. An older answer
// landing last would replace a newer list and then drop the selection through
// discardMissingProfile, so only the newest generation is allowed to write.
let loadGeneration = 0

export async function loadProfiles(target: ProfileEndpoint): Promise<void> {
  const generation = ++loadGeneration
  loadingProfiles.value = true
  profileError.value = ''

  try {
    const response = await fetch(endpoint(target, '/try-it/profiles'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    const payload = await readPayload(response)

    if (generation !== loadGeneration) {
      return
    }

    if (!response.ok) {
      fail(response.status, payload, t('tryIt.profileLoadError'))
      return
    }

    profiles.value = Array.isArray(payload.profiles)
      ? payload.profiles.map(withServerVariableMap).filter(isTryItProfile)
      : []
    discardMissingProfile(profiles.value.map((profile) => profile.id))
  } catch {
    if (generation === loadGeneration) {
      profileError.value = t('tryIt.profileLoadError')
    }
  } finally {
    if (generation === loadGeneration) {
      loadingProfiles.value = false
    }
  }
}

/** Returns the created profile, or null when the request was refused. */
export async function createProfile(
  target: ProfileEndpoint,
  input: ProfileCredentialInput,
): Promise<StoredTryItProfile | null> {
  if (input.credential === '') {
    profileError.value = t('tryIt.credentialRequired')

    return null
  }

  profileError.value = ''
  const requestBody = JSON.stringify({
    label: input.label || undefined,
    base_url: input.baseUrl || undefined,
    server_variables: input.serverVariables,
    scheme: input.scheme,
    credential: input.credential,
    credential_header: input.scheme === 'header' ? input.credentialHeader : undefined,
  })

  try {
    const response = await fetch(endpoint(target, '/try-it/profiles'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: mutationHeaders(target),
      body: requestBody,
    })
    const payload = await readPayload(response)

    if (!response.ok) {
      fail(response.status, payload, t('tryIt.profileCreateError'))

      return null
    }

    const created = withServerVariableMap(payload.profile)

    if (!isTryItProfile(created)) {
      // A profile the server stored but the client cannot read is worse than a
      // failed write: the panel would look empty while the credential is live.
      profileError.value = t('tryIt.profileCreateError')

      return null
    }

    profiles.value = [...profiles.value, created]
    setSelectedProfileId(created.id)

    return created
  } catch {
    profileError.value = t('tryIt.profileCreateError')

    return null
  }
}

export async function deleteProfile(target: ProfileEndpoint, id: string): Promise<void> {
  profileError.value = ''

  try {
    const response = await fetch(endpoint(target, `/try-it/profiles/${encodeURIComponent(id)}`), {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: mutationHeaders(target),
    })

    if (!response.ok) {
      fail(response.status, await readPayload(response), t('tryIt.profileDeleteError'))

      return
    }

    profiles.value = profiles.value.filter((profile) => profile.id !== id)
    // Only the deleted profile loses the selection: clearing it unconditionally would
    // send the next request unauthenticated against the still-selected profile's target.
    discardMissingProfile(profiles.value.map((profile) => profile.id))
  } catch {
    profileError.value = t('tryIt.profileDeleteError')
  }
}

export function resetProfiles(): void {
  // A request still in flight must not repopulate the list it was reset out of.
  loadGeneration += 1
  profiles.value = []
  loadingProfiles.value = false
  profileError.value = ''
  profileDenied.value = ''
}

/**
 * PHP encodes an empty map as a JSON array, so a profile with no server variables
 * used to arrive as `server_variables: []`. The guard below reads that field as an
 * object and dropped the whole profile over it — silently emptying the list on
 * every load, which looked like credentials that would not persist. The server
 * now sends an object; this keeps an older one usable.
 */
function withServerVariableMap(value: unknown): unknown {
  if (!isRecord(value) || !Array.isArray(value.server_variables)) {
    return value
  }

  return { ...value, server_variables: {} }
}

export function isTryItProfile(value: unknown): value is StoredTryItProfile {
  if (!isRecord(value)) {
    return false
  }

  return (
    typeof value.id === 'string'
    && typeof value.label === 'string'
    && typeof value.base_url === 'string'
    && ['bearer', 'basic', 'header'].includes(String(value.scheme))
    && (typeof value.credential_header === 'string' || value.credential_header === null)
    && typeof value.credential_hint === 'string'
    && (value.server_variables === undefined || isStringRecord(value.server_variables))
  )
}

function endpoint(target: ProfileEndpoint, suffix: string): string {
  return `${target.baseUrl.replace(/\/$/, '')}${suffix}`
}

function mutationHeaders(target: ProfileEndpoint): Record<string, string> {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': target.csrfToken,
  }
}

function fail(status: number, payload: Record<string, unknown>, fallback: string): void {
  const message = typeof payload.message === 'string' ? payload.message : fallback

  // A 403 is the feature being switched off, not a bad request: it disables the
  // surface rather than blaming the reader's input.
  if (status === 403) {
    profileDenied.value = message

    return
  }

  profileError.value = message
}

async function readPayload(response: Response): Promise<Record<string, unknown>> {
  try {
    const payload: unknown = await response.json()

    return isRecord(payload) ? payload : {}
  } catch {
    return {}
  }
}

function isStringRecord(value: unknown): value is Record<string, string> {
  return isRecord(value) && Object.values(value).every((entry) => typeof entry === 'string')
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
