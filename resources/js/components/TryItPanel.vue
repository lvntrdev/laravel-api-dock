<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

import JsonTree from '@/components/JsonTree.vue'
import { openSettings } from '@/lib/appView'
import { t } from '@/lib/i18n'
import { isReference, resolvePointer } from '@/lib/schema'
import {
  loadProfiles,
  loadingProfiles,
  profileDenied,
  profileError,
  profiles,
} from '@/lib/tryItProfiles'
import type { StoredTryItProfile } from '@/lib/tryItProfiles'
import {
  ensureServerVariables,
  plainBaseUrl as storedPlainBaseUrl,
  selectedProfileId as storedSelectedProfileId,
  selectedServer as storedSelectedServer,
  serverVariables,
  setPlainBaseUrl,
  setSelectedProfileId,
  setServerVariables,
} from '@/lib/tryItSession'
import {
  buildCurlSample,
  initialServerVariables,
  narrowServerVariables,
  operationParameters,
  operationServers,
  parameterRecord,
  resolveServerPreview,
  substitutePathParameters,
  withCurrentOrigin,
} from '@/lib/tryIt'
import type { EditableParameter } from '@/lib/tryIt'
import type {
  OpenApiDocument,
  OperationEntry,
  RequestBodyObject,
  ServerObject,
} from '@/types/openapi'

const RESPONSE_BODY_LIMIT = 20_000
const RESPONSE_HEADER_LIMIT = 100
const BODYLESS_METHODS = new Set(['GET', 'HEAD'])
// Mirrors OutboundRequestGuard::SUPPORTED_METHODS on the PHP side. TRACE is absent
// there, so offering it here would only produce a 422 from the proxy.
const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD']
const FALLBACK_METHOD = 'GET'
const MODAL_FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'

const props = withDefaults(
  defineProps<{
    document: OpenApiDocument
    operation: OperationEntry
    baseUrl?: string
    csrfToken?: string
  }>(),
  {
    baseUrl: '/api-dock',
    csrfToken: '',
  },
)

interface ProxyResult {
  status: number
  headers: Record<string, string>
  body: string
  truncated: boolean
  host: string
  url: string
}

const method = ref(initialMethod())
const path = ref(props.operation.path)
const parameters = ref<EditableParameter[]>(operationParameters(props.document, props.operation))
// The origin the page was opened on leads the list: on a multi-tenant host the
// spec names the apex, but the reader is demonstrably on a tenant subdomain.
const servers = withCurrentOrigin(
  operationServers(props.document, props.operation),
  window.location.origin,
)
// The target belongs to the settings panel now: it owns the picker, and the panel
// follows whichever server the session holds. An unknown value falls back to the first
// entry rather than sending the request nowhere.
const selectedServer = computed<ServerObject | undefined>(() =>
  servers.find((server) => server.url === storedSelectedServer.value) ?? servers[0],
)
ensureServerVariables(initialServerVariables(selectedServer.value))
const plainBaseUrl = computed({
  get: () => storedPlainBaseUrl.value,
  set: setPlainBaseUrl,
})
const bodyText = ref(initialBodyText())
const selectedProfileId = computed({
  get: () => storedSelectedProfileId.value,
  set: setSelectedProfileId,
})
const sendDenied = ref('')
const sending = ref(false)
const requestError = ref('')
const responseResult = ref<ProxyResult>()
const elapsedMs = ref<number>()
const copied = ref<'curl' | 'body' | ''>('')
const bodyExpanded = ref(false)
const closeModalButton = ref<HTMLButtonElement>()
const expandButton = ref<HTMLButtonElement>()
const modalPanel = ref<HTMLElement>()

// Either endpoint answering 403 means the same thing: try-it is switched off for this
// reader, so the panel refuses rather than offering a send that cannot land.
const disabledReason = computed(() => sendDenied.value || profileDenied.value)
// A base URL the reader (or their profile) supplied is a concrete host and outranks the
// spec's template: the template would otherwise send the credential to the default
// server while the screen names another one.
const usesBaseUrlOverride = computed(() => plainBaseUrl.value.trim() !== '')
const applicableServerVariables = computed(() =>
  narrowServerVariables(selectedServer.value, serverVariables.value),
)
const resolvedBaseUrl = computed(() => {
  if (usesBaseUrlOverride.value || !selectedServer.value) {
    return plainBaseUrl.value
  }

  // Resolved from the narrowed values, never the raw map: the preview must name the
  // same host the payload below resolves to.
  return resolveServerPreview(selectedServer.value.url, applicableServerVariables.value)
})
const pathParameters = computed(() => parameters.value.filter((item) => item.in === 'path'))
const queryParameters = computed(() => parameters.value.filter((item) => item.in === 'query'))
const headerParameters = computed(() => parameters.value.filter((item) => item.in === 'header'))
// The request area lists the operation's own parameters and nothing else — path first, because that
// is the order they appear in the URL the reader is looking at.
const visibleParameters = computed(() => [
  ...pathParameters.value,
  ...queryParameters.value,
  ...headerParameters.value,
])
const resolvedPath = computed(() => substitutePathParameters(path.value, parameters.value))
const bodyless = computed(() => BODYLESS_METHODS.has(method.value))
const bodyParse = computed<{ value?: unknown; error?: string }>(() => {
  if (bodyless.value) {
    return {}
  }

  try {
    return { value: JSON.parse(bodyText.value) as unknown }
  } catch {
    return { error: t('tryIt.invalidJson') }
  }
})
const selectedProfile = computed(() =>
  profiles.value.find((profile) => profile.id === selectedProfileId.value),
)
const curlSample = computed(() =>
  buildCurlSample({
    method: method.value,
    baseUrl: resolvedBaseUrl.value,
    path: resolvedPath.value,
    query: parameterRecord(parameters.value, 'query'),
    headers: parameterRecord(parameters.value, 'header'),
    body: bodyless.value || bodyParse.value.error ? undefined : bodyParse.value.value,
    profile: selectedProfile.value,
  }),
)
const renderedBody = computed(() => prettyResponseBody(responseResult.value?.body ?? ''))
const bodyWasDomTruncated = computed(() => renderedBody.value.length > RESPONSE_BODY_LIMIT)
const visibleBody = computed(() => renderedBody.value.slice(0, RESPONSE_BODY_LIMIT))
const visibleBodyJson = computed<{ valid: boolean; value?: unknown }>(() => {
  try {
    return { valid: true, value: JSON.parse(visibleBody.value) as unknown }
  } catch {
    return { valid: false }
  }
})
const requestPayload = computed(() => {
  if (!bodyless.value && !bodyParse.value.error) {
    return bodyText.value
  }

  return JSON.stringify({
    ...parameterRecord(parameters.value, 'path'),
    ...parameterRecord(parameters.value, 'query'),
  }, null, 2) ?? '{}'
})
const visibleResponseHeaders = computed(() =>
  Object.entries(responseResult.value?.headers ?? {}).slice(0, RESPONSE_HEADER_LIMIT),
)
watch(selectedProfile, (profile, previous) => {
  // A value the previous profile supplied must not survive the switch: leaving it in place would
  // send the new profile's credential to the previous profile's target without any visible sign.
  // Only untouched values are reverted — a value the user typed over is their own choice.
  if (previous && previous.id !== profile?.id) {
    releaseProfileValues(previous, profile)
  }

  if (!profile) {
    return
  }

  if (profile.server_variables && Object.keys(profile.server_variables).length > 0) {
    setServerVariables(profile.server_variables)
  }

  // A profile's own base URL applies even when the document declares servers, otherwise
  // the environment the credential was saved for would be quietly ignored.
  if (profile.base_url !== '') {
    plainBaseUrl.value = profile.base_url
  }
})

// One fetch per mount and no polling: the list is shared module state, so what the
// settings panel creates is already visible here.
onMounted(() => void loadProfiles({ baseUrl: props.baseUrl, csrfToken: props.csrfToken }))

async function sendRequest(): Promise<void> {
  if (bodyParse.value.error || disabledReason.value) {
    return
  }

  sending.value = true
  requestError.value = ''
  responseResult.value = undefined
  elapsedMs.value = undefined
  const startedAt = performance.now()
  const payload: Record<string, unknown> = {
    method: method.value,
    path: resolvedPath.value,
    query: parameterRecord(parameters.value, 'query'),
    headers: parameterRecord(parameters.value, 'header'),
  }

  // An override is already a concrete host, so the template is not consulted at all —
  // substituting into a server the reader has replaced would send the request somewhere
  // they did not ask for.
  if (usesBaseUrlOverride.value || !selectedServer.value) {
    payload.url = plainBaseUrl.value
  } else {
    payload.server = selectedServer.value.url
    payload.server_variables = applicableServerVariables.value
    payload.server_variable_spec = selectedServer.value.variables ?? {}
  }

  if (!bodyless.value) {
    payload.body = bodyParse.value.value
  }

  // The resolved profile, not the persisted id: while the list is still loading or after a failed
  // load the panel shows no profile at all, and sending the id anyway would apply a credential to a
  // request the reader sees as unauthenticated.
  if (selectedProfile.value) {
    payload.profile = selectedProfile.value.id
  }

  try {
    const response = await fetch(endpoint('/try-it'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: mutationHeaders(),
      body: JSON.stringify(payload),
    })
    const responsePayload = await readPayload(response)
    elapsedMs.value = Math.round((performance.now() - startedAt) * 10) / 10

    if (!response.ok) {
      handleFailure(response.status, responsePayload, t('tryIt.proxyHttpError', { status: response.status }))
      return
    }

    responseResult.value = normalizeProxyResult(responsePayload)
  } catch {
    elapsedMs.value = Math.round((performance.now() - startedAt) * 10) / 10
    requestError.value = t('tryIt.sendError')
  } finally {
    sending.value = false
  }
}

async function copyCurl(): Promise<void> {
  await navigator.clipboard.writeText(curlSample.value)
  copied.value = 'curl'
}

// The clipboard gets the whole body, not the slice the DOM renders: the character limit
// exists to keep the page responsive, and silently copying a cut-off payload would be worse
// than the scroll it saves.
async function copyResponseBody(): Promise<void> {
  await navigator.clipboard.writeText(renderedBody.value)
  copied.value = 'body'
}

function closeBodyModal(): void {
  bodyExpanded.value = false
}

// The overlay hides the page behind it, so a Tab that walked out of the dialog would move
// focus to a control nobody can see. `aria-modal` only tells assistive technology that the
// rest of the page is inert; keeping the tab ring inside is this handler's job.
function handleModalKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    closeBodyModal()
    return
  }

  if (event.key !== 'Tab' || !modalPanel.value) {
    return
  }

  const focusable = Array.from(
    modalPanel.value.querySelectorAll<HTMLElement>(MODAL_FOCUSABLE_SELECTOR),
  ).filter((element) => element.offsetParent !== null || element === document.activeElement)

  if (focusable.length === 0) {
    event.preventDefault()
    return
  }

  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  const active = document.activeElement
  const outside = !(active instanceof HTMLElement) || !modalPanel.value.contains(active)

  if (event.shiftKey && (outside || active === first)) {
    event.preventDefault()
    last.focus()
    return
  }

  if (!event.shiftKey && (outside || active === last)) {
    event.preventDefault()
    first.focus()
  }
}

watch(bodyExpanded, async (open) => {
  if (open) {
    window.addEventListener('keydown', handleModalKeydown)
    // Focus moves into the modal so the keyboard reader lands on the control that closes it
    // instead of continuing through the page behind the overlay.
    await nextTick()
    closeModalButton.value?.focus()
    return
  }

  window.removeEventListener('keydown', handleModalKeydown)
  // Focus returns to the control that opened the dialog. The button can be gone — a new
  // response closes the modal and re-renders this area — so it is only refocused while it
  // is still on the page.
  await nextTick()

  if (expandButton.value?.isConnected) {
    expandButton.value.focus()
  }
})

// A fresh response replaces what the modal is showing, so leaving it open would put the
// previous run's body on screen under the new run's status line.
watch(responseResult, closeBodyModal)

onUnmounted(() => window.removeEventListener('keydown', handleModalKeydown))

function updateParameter(parameter: EditableParameter, event: Event): void {
  parameter.value = (event.target as HTMLInputElement).value
}

function releaseProfileValues(
  previous: StoredTryItProfile,
  next: StoredTryItProfile | undefined,
): void {
  const previousVariables = previous.server_variables ?? {}
  const nextVariables = next?.server_variables ?? {}
  const released = Object.fromEntries(
    Object.entries(previousVariables)
      .filter(([name, value]) => {
        return !Object.hasOwn(nextVariables, name) && serverVariables.value[name] === value
      })
      .map(([name]) => [name, selectedServer.value?.variables?.[name]?.default ?? '']),
  )

  if (Object.keys(released).length > 0) {
    setServerVariables(released)
  }

  const nextBaseUrl = next?.base_url ?? ''

  if (
    previous.base_url !== ''
    && nextBaseUrl === ''
    && plainBaseUrl.value === previous.base_url
  ) {
    plainBaseUrl.value = ''
  }
}

function endpoint(suffix: string): string {
  return `${props.baseUrl.replace(/\/$/, '')}${suffix}`
}

function mutationHeaders(): Record<string, string> {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': props.csrfToken,
  }
}

function handleFailure(status: number, payload: Record<string, unknown>, fallback: string): void {
  const message = typeof payload.message === 'string' ? payload.message : fallback

  if (status === 403) {
    sendDenied.value = message
    return
  }

  if (status >= 400) {
    requestError.value = message
  }
}

async function readPayload(response: Response): Promise<Record<string, unknown>> {
  try {
    const payload: unknown = await response.json()
    return isRecord(payload) ? payload : {}
  } catch {
    return {}
  }
}

function normalizeProxyResult(payload: Record<string, unknown>): ProxyResult {
  return {
    status: typeof payload.status === 'number' ? payload.status : 0,
    headers: isRecord(payload.headers)
      ? Object.fromEntries(
          Object.entries(payload.headers).map(([name, value]) => [name, String(value)]),
        )
      : {},
    body: typeof payload.body === 'string'
      ? payload.body
      : (JSON.stringify(payload.body ?? '') ?? ''),
    truncated: payload.truncated === true,
    host: typeof payload.host === 'string' ? payload.host : '',
    url: typeof payload.url === 'string' ? payload.url : '',
  }
}

function prettyResponseBody(body: string): string {
  try {
    return JSON.stringify(JSON.parse(body) as unknown, null, 2) ?? body
  } catch {
    return body
  }
}

function initialMethod(): string {
  const candidate = props.operation.method.toUpperCase()

  // A spec operation may declare a method the proxy cannot send (trace); the select
  // must never start on a value that is missing from METHODS.
  return METHODS.includes(candidate) ? candidate : FALLBACK_METHOD
}

function initialBodyText(): string {
  const candidate = props.operation.operation.requestBody
  const resolved = candidate && isReference(candidate)
    ? resolvePointer(props.document, candidate.$ref)
    : candidate
  const requestBody = resolved && !isReference(resolved)
    ? (resolved as RequestBodyObject)
    : undefined
  const media = requestBody?.content?.['application/json']
    ?? Object.values(requestBody?.content ?? {})[0]
  const schema = media?.schema && !isReference(media.schema) ? media.schema : undefined
  const example = media?.example ?? schema?.example ?? schema?.default ?? {}

  return JSON.stringify(example, null, 2) ?? '{}'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
</script>

<template>
  <section class="panel try-it-panel" data-api-dock-panel="try-it">
    <span v-if="loadingProfiles" class="panel-status">{{ t('tryIt.loadingProfiles') }}</span>

    <div v-if="disabledReason" class="panel-disabled" role="status" data-testid="try-it-disabled">
      <strong>{{ t('tryIt.disabled') }}</strong>
      <p>{{ disabledReason }}</p>
    </div>

    <template v-else>
      <div class="try-it-card">
        <div class="try-it-request-bar">
          <label class="try-it-control try-it-control--method">
            <span class="sr-only">{{ t('tryIt.method') }}</span>
            <select v-model="method" data-testid="method-input">
              <option v-for="candidate in METHODS" :key="candidate">{{ candidate }}</option>
            </select>
          </label>
          <label class="try-it-control try-it-control--path">
            <span class="sr-only">{{ t('tryIt.path') }}</span>
            <input v-model="path" data-testid="path-input" type="text">
          </label>
          <label class="try-it-control try-it-control--profile">
            <span class="sr-only">{{ t('tryIt.authenticationProfile') }}</span>
            <select v-model="selectedProfileId" data-testid="profile-select">
              <option value="">{{ t('tryIt.noProfile') }}</option>
              <option v-for="profile in profiles" :key="profile.id" :value="profile.id">
                {{ profile.label }} · {{ profile.credential_hint }}
              </option>
            </select>
          </label>
          <button
            class="try-it-profile-add"
            data-testid="toggle-profile-form"
            type="button"
            :title="t('tryIt.createShortLivedProfile')"
            :aria-label="t('tryIt.createShortLivedProfile')"
            @click="openSettings"
          ><i class="pi pi-plus" /></button>
          <button
            class="button button--primary try-it-send"
            data-testid="send-request"
            type="button"
            :disabled="sending || !!bodyParse.error"
            @click="sendRequest"
          >{{ sending ? t('tryIt.sending') : t('tryIt.send') }}</button>
        </div>

        <p
          v-if="requestError || profileError"
          class="field-error try-it-request-error"
          role="alert"
        >{{ requestError || profileError }}</p>

        <div class="try-it-options">
          <div v-if="visibleParameters.length" class="try-it-parameters">
            <label v-for="parameter in visibleParameters" :key="`${parameter.in}:${parameter.name}`" class="try-it-parameter">
              <span class="try-it-parameter__label">{{ parameter.name }}<em v-if="parameter.required" class="try-it-parameter__required" :title="t('operation.required')">*</em></span>
              <input :value="parameter.value" :data-testid="`parameter-${parameter.in}-${parameter.name}`" type="text" @input="updateParameter(parameter, $event)">
            </label>
          </div>

          <label v-if="!bodyless" class="field body-editor try-it-body-editor">
            <span>{{ t('tryIt.jsonBody') }}</span>
            <textarea v-model="bodyText" data-testid="body-editor" rows="10" spellcheck="false"></textarea>
            <small v-if="bodyParse.error" class="field-error" role="alert" data-testid="body-error">{{ bodyParse.error }}</small>
          </label>
        </div>

        <div class="code-sample">
          <strong class="sr-only">{{ t('tryIt.currentCurl') }}</strong>
          <pre data-testid="curl-sample">{{ curlSample }}</pre>
          <button type="button" class="code-copy-button" :title="copied === 'curl' ? t('tryIt.copied') : t('tryIt.copyCurl')" :aria-label="copied === 'curl' ? t('tryIt.copied') : t('tryIt.copyCurl')" @click="copyCurl">
            <i :class="copied === 'curl' ? 'pi pi-check' : 'pi pi-copy'" />
          </button>
          <p v-if="selectedProfile" class="credential-note">{{ t('tryIt.credentialNote', { hint: selectedProfile.credential_hint }) }}</p>
        </div>
      </div>

      <div v-if="responseResult" class="proxy-response" aria-live="polite">
        <div class="proxy-response__summary">
          <strong>{{ t('operation.response') }}</strong>
          <span class="status-code" :data-success="responseResult.status >= 200 && responseResult.status < 300">{{ responseResult.status }}</span>
          <code>{{ responseResult.url }}</code>
          <span class="proxy-response__timing">{{ elapsedMs }} ms</span>
        </div>
        <p v-if="responseResult.truncated" class="truncation-notice">{{ t('tryIt.proxyTruncated') }}</p>
        <div class="proxy-response__columns">
          <div class="proxy-response__request">
            <span v-if="visibleResponseHeaders.length" class="panel-label">{{ t('tryIt.responseHeaders') }}</span>
            <div v-if="visibleResponseHeaders.length" class="response-headers">
              <div v-for="([name, value]) in visibleResponseHeaders" :key="name"><code>{{ name }}</code><span>{{ value }}</span></div>
            </div>
            <p v-if="Object.keys(responseResult.headers).length > RESPONSE_HEADER_LIMIT" class="truncation-notice">{{ t('tryIt.responseHeadersTruncated', { limit: RESPONSE_HEADER_LIMIT }) }}</p>
            <span class="panel-label proxy-response__request-label">{{ t('tryIt.request') }}</span>
            <pre>{{ requestPayload }}</pre>
          </div>
          <div class="proxy-response__result">
            <div class="proxy-response__result-header">
              <span class="panel-label">{{ t('tryIt.responseBody') }}</span>
              <div class="proxy-response__result-actions">
                <button type="button" class="code-copy-button" :title="copied === 'body' ? t('tryIt.copied') : t('tryIt.copyResponseBody')" :aria-label="copied === 'body' ? t('tryIt.copied') : t('tryIt.copyResponseBody')" data-testid="copy-response-body" @click="copyResponseBody">
                  <i :class="copied === 'body' ? 'pi pi-check' : 'pi pi-copy'" />
                </button>
                <button type="button" class="code-copy-button" :title="t('tryIt.expandResponseBody')" :aria-label="t('tryIt.expandResponseBody')" ref="expandButton" data-testid="expand-response-body" @click="bodyExpanded = true">
                  <i class="pi pi-window-maximize" />
                </button>
              </div>
            </div>
            <JsonTree v-if="visibleBodyJson.valid" class="proxy-response__json" data-testid="response-body" :value="visibleBodyJson.value" />
            <pre v-else data-testid="response-body">{{ visibleBody }}</pre>
            <p v-if="bodyWasDomTruncated" class="truncation-notice">{{ t('tryIt.responseBodyTruncated', { limit: RESPONSE_BODY_LIMIT }) }}</p>
          </div>
        </div>
      </div>

      <Teleport to="body">
        <div v-if="bodyExpanded" class="body-modal" data-testid="response-body-modal" @click.self="closeBodyModal">
          <div ref="modalPanel" class="body-modal__panel" role="dialog" aria-modal="true" :aria-label="t('tryIt.responseBody')">
            <header class="body-modal__header">
              <span class="panel-label">{{ t('tryIt.responseBody') }}</span>
              <div class="proxy-response__result-actions">
                <button type="button" class="code-copy-button" :title="copied === 'body' ? t('tryIt.copied') : t('tryIt.copyResponseBody')" :aria-label="copied === 'body' ? t('tryIt.copied') : t('tryIt.copyResponseBody')" data-testid="copy-response-body-modal" @click="copyResponseBody">
                  <i :class="copied === 'body' ? 'pi pi-check' : 'pi pi-copy'" />
                </button>
                <button type="button" class="code-copy-button" :title="t('common.close')" :aria-label="t('common.close')" ref="closeModalButton" data-testid="close-response-body-modal" @click="closeBodyModal">
                  <i class="pi pi-times" />
                </button>
              </div>
            </header>
            <div class="body-modal__content">
              <JsonTree v-if="visibleBodyJson.valid" data-testid="response-body-expanded" :value="visibleBodyJson.value" />
              <pre v-else data-testid="response-body-expanded">{{ visibleBody }}</pre>
            </div>
            <p v-if="bodyWasDomTruncated" class="truncation-notice body-modal__notice">{{ t('tryIt.responseBodyTruncated', { limit: RESPONSE_BODY_LIMIT }) }}</p>
          </div>
        </div>
      </Teleport>
    </template>
  </section>
</template>
