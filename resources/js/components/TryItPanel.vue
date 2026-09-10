<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

import JsonTree from '@/components/JsonTree.vue'
import { openSettings } from '@/lib/appView'
import { t } from '@/lib/i18n'
import { keepTabInside } from '@/lib/modalFocus'
import { isReference, resolvePointer, resolveSchema, sampleFromSchema } from '@/lib/schema'
import {
  createProfile,
  loadProfiles,
  loadingProfiles,
  profileDenied,
  profileError,
  profiles,
} from '@/lib/tryItProfiles'
import { REDACTED, extractToken } from '@/lib/tryItToken'
import type { StoredTryItProfile } from '@/lib/tryItProfiles'
import { vTooltip } from '@/lib/tooltip'
import { setStoredResponse, storedResponse } from '@/lib/tryItResponses'
import {
  classifyInputs,
  ensureServerVariables,
  plainBaseUrl as storedPlainBaseUrl,
  selectedProfileId as storedSelectedProfileId,
  selectedServer as storedSelectedServer,
  serverVariables,
  setOperationInputs,
  setPlainBaseUrl,
  setSelectedProfileId,
  setServerVariables,
  storedOperationInputs,
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

// What this reader last typed into THIS operation's form. Read once, at setup: the
// panel is remounted per operation (the detail view keys it on the operation), so
// there is no second operation to reload for.
const remembered = storedOperationInputs(props.operation.key)
const method = ref(initialMethod())
const path = ref(props.operation.path)
const parameters = ref<EditableParameter[]>(
  operationParameters(props.document, props.operation).map((parameter) => ({
    ...parameter,
    value: remembered.parameters[parameterMemoryKey(parameter)] ?? parameter.value,
  })),
)
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
// A remembered body outranks the generated one: it is what the reader last sent.
const bodyText = ref(remembered.body !== '' ? remembered.body : initialBodyText())
const selectedProfileId = computed({
  get: () => storedSelectedProfileId.value,
  set: setSelectedProfileId,
})
const sendDenied = ref('')
const sending = ref(false)
const requestError = ref('')
// The last response this operation gave, so coming back to the endpoint does not
// look like the request was never sent. Same read-once reasoning as the form values.
const rememberedResponse = storedResponse(props.operation.key)
const responseResult = ref<ProxyResult | undefined>(rememberedResponse)
const elapsedMs = ref<number | undefined>(rememberedResponse?.elapsedMs)
const copied = ref<'curl' | 'body' | ''>('')
const savingToken = ref(false)
// Reset with every send below, so the confirmation belongs to the response on screen
// rather than to a profile made from an earlier one.
const tokenSaved = ref(false)
// Which dialog is open, if any. One at a time by construction: both use the same overlay,
// so the focus trap below has a single panel to keep the tab ring inside.
const expandedPanel = ref<'body' | 'headers' | 'request' | ''>('')
const closeModalButton = ref<HTMLButtonElement>()
// The control that opened the dialog, captured on click rather than held as a ref per
// button: focus has to return to whichever one was used.
const modalTrigger = ref<HTMLElement>()
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
// Offered on a successful response only: a 401 body naming a `token` field is describing
// what it wanted, not handing one over.
const capturedToken = computed(() => {
  const result = responseResult.value

  if (!result || result.status < 200 || result.status >= 300 || result.truncated) {
    return null
  }

  const token = extractToken(result.body)

  // A response restored from storage is the masked copy, so its "token" is the mask:
  // offering it would create a profile whose credential is three asterisks.
  return token === REDACTED ? null : token
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
const modalTitle = computed(() => {
  if (expandedPanel.value === 'headers') {
    return t('tryIt.responseHeaders')
  }

  return expandedPanel.value === 'request' ? t('tryIt.request') : t('tryIt.responseBody')
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

// Remember the composed request as it is typed, so leaving the endpoint and coming
// back — or reloading the page — does not hand the reader an empty form again.
// `deep` because the values live inside the parameter objects, not in the array.
// Everything goes through `classifyInputs` first: the form on screen keeps the
// credential the reader typed, the copy that outlives the tab never receives it.
watch([parameters, bodyText], () => {
  setOperationInputs(props.operation.key, classifyInputs(
    {
      parameters: Object.fromEntries(
        parameters.value.map((parameter) => [parameterMemoryKey(parameter), parameter.value]),
      ),
      body: bodyText.value,
    },
    parameters.value,
    props.document.components?.securitySchemes,
  ))
}, { deep: true })

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
  tokenSaved.value = false
  // Dropped before the request goes out: a failed send must not leave the previous
  // response behind to be shown again on the next visit as if it were this one's.
  setStoredResponse(props.operation.key, undefined)
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
    setStoredResponse(props.operation.key, {
      ...responseResult.value,
      elapsedMs: elapsedMs.value ?? 0,
    })
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

/**
 * Turns the token this response returned into a bearer profile and selects it, so every
 * following request carries it. The credential goes straight to the profile endpoint —
 * it is never written to web storage, and the panel only ever sees the masked hint back.
 */
async function useTokenAsProfile(): Promise<void> {
  if (capturedToken.value === null || savingToken.value) {
    return
  }

  savingToken.value = true

  try {
    const created = await createProfile({ baseUrl: props.baseUrl, csrfToken: props.csrfToken }, {
      // The profile endpoint caps a label at 64 characters and this action has no label
      // field to shorten it in, so a long path is cut here rather than answered with 422.
      label: t('tryIt.tokenProfileLabel', { path: props.operation.path }).slice(0, 64),
      baseUrl: resolvedBaseUrl.value,
      serverVariables: applicableServerVariables.value,
      scheme: 'bearer',
      credential: capturedToken.value,
      credentialHeader: '',
    })

    tokenSaved.value = created !== null
  } finally {
    savingToken.value = false
  }
}

function openModal(panel: 'body' | 'headers' | 'request', event: MouseEvent): void {
  modalTrigger.value = event.currentTarget as HTMLElement
  expandedPanel.value = panel
}

function closeBodyModal(): void {
  expandedPanel.value = ''
}

function handleModalKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    closeBodyModal()
    return
  }

  if (event.key === 'Tab' && modalPanel.value) {
    keepTabInside(event, modalPanel.value)
  }
}

watch(expandedPanel, async (panel) => {
  if (panel !== '') {
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

  if (modalTrigger.value?.isConnected) {
    modalTrigger.value.focus()
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

/**
 * Two parameters may share a name across locations — a `page` query and a `page`
 * header are different inputs — so the remembered value is keyed by both.
 */
function parameterMemoryKey(parameter: { in: string; name: string }): string {
  return `${parameter.in}:${parameter.name}`
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
  // Scramble writes the body schema as a `$ref` and never fills the media type's own
  // `example`, so the declared example almost always lives one hop away.
  const schema = resolveSchema(media?.schema, props.document)
  const declared = media?.example ?? schema?.example ?? schema?.default

  if (declared !== undefined) {
    return JSON.stringify(declared, null, 2) ?? '{}'
  }

  // Hand-written AI examples carry a real payload; they beat a generated skeleton.
  const aiRequest = props.operation.operation['x-ai-examples']?.[0]?.request

  // Same reasoning as the sample below: an array or scalar example is a body too.
  if (aiRequest !== undefined && aiRequest !== null) {
    return JSON.stringify(aiRequest, null, 2) ?? '{}'
  }

  // A top-level array or scalar is a valid JSON body: taking only objects would
  // hand the reader `{}` for a schema that generated the right shape.
  const sample = schema ? sampleFromSchema(media?.schema, props.document) : undefined

  return JSON.stringify(sample ?? {}, null, 2) ?? '{}'
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
          <button v-if="capturedToken" type="button" class="code-copy-button" :disabled="savingToken" v-tooltip="tokenSaved ? t('tryIt.tokenProfileCreated') : t('tryIt.useTokenAsProfile')" :aria-label="tokenSaved ? t('tryIt.tokenProfileCreated') : t('tryIt.useTokenAsProfile')" data-testid="use-token-as-profile" @click="useTokenAsProfile">
            <i :class="tokenSaved ? 'pi pi-check' : 'pi pi-key'" />
          </button>
          <button type="button" class="code-copy-button" v-tooltip="t('tryIt.request')" :aria-label="t('tryIt.request')" data-testid="expand-request" @click="openModal('request', $event)">
            <i class="pi pi-send" />
          </button>
          <button v-if="visibleResponseHeaders.length" type="button" class="code-copy-button" v-tooltip="t('tryIt.responseHeaders')" :aria-label="t('tryIt.responseHeaders')" data-testid="expand-response-headers" @click="openModal('headers', $event)">
            <i class="pi pi-list" />
          </button>
        </div>
        <p v-if="responseResult.truncated" class="truncation-notice">{{ t('tryIt.proxyTruncated') }}</p>
        <div class="proxy-response__columns">
          <div class="proxy-response__result">
            <div class="proxy-response__result-header">
              <span class="panel-label">{{ t('tryIt.responseBody') }}</span>
              <div class="proxy-response__result-actions">
                <button type="button" class="code-copy-button" v-tooltip="copied === 'body' ? t('tryIt.copied') : t('tryIt.copyResponseBody')" :aria-label="copied === 'body' ? t('tryIt.copied') : t('tryIt.copyResponseBody')" data-testid="copy-response-body" @click="copyResponseBody">
                  <i :class="copied === 'body' ? 'pi pi-check' : 'pi pi-copy'" />
                </button>
                <button type="button" class="code-copy-button" v-tooltip="t('tryIt.expandResponseBody')" :aria-label="t('tryIt.expandResponseBody')" data-testid="expand-response-body" @click="openModal('body', $event)">
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
        <div v-if="expandedPanel" class="body-modal" data-testid="response-body-modal" @click.self="closeBodyModal">
          <div ref="modalPanel" class="body-modal__panel" role="dialog" aria-modal="true" :aria-label="modalTitle">
            <header class="body-modal__header">
              <span class="panel-label">{{ modalTitle }}</span>
              <div class="proxy-response__result-actions">
                <button v-if="expandedPanel === 'body'" type="button" class="code-copy-button" v-tooltip="copied === 'body' ? t('tryIt.copied') : t('tryIt.copyResponseBody')" :aria-label="copied === 'body' ? t('tryIt.copied') : t('tryIt.copyResponseBody')" data-testid="copy-response-body-modal" @click="copyResponseBody">
                  <i :class="copied === 'body' ? 'pi pi-check' : 'pi pi-copy'" />
                </button>
                <button type="button" class="code-copy-button" v-tooltip="t('common.close')" :aria-label="t('common.close')" ref="closeModalButton" data-testid="close-response-body-modal" @click="closeBodyModal">
                  <i class="pi pi-times" />
                </button>
              </div>
            </header>
            <div class="body-modal__content">
              <template v-if="expandedPanel === 'headers'">
                <div class="response-headers" data-testid="response-headers-expanded">
                  <div v-for="([name, value]) in visibleResponseHeaders" :key="name"><code>{{ name }}</code><span>{{ value }}</span></div>
                </div>
                <p v-if="Object.keys(responseResult?.headers ?? {}).length > RESPONSE_HEADER_LIMIT" class="truncation-notice">{{ t('tryIt.responseHeadersTruncated', { limit: RESPONSE_HEADER_LIMIT }) }}</p>
              </template>
              <template v-else-if="expandedPanel === 'request'">
                <pre data-testid="request-expanded">{{ requestPayload }}</pre>
              </template>
              <template v-else>
                <JsonTree v-if="visibleBodyJson.valid" data-testid="response-body-expanded" :value="visibleBodyJson.value" />
                <pre v-else data-testid="response-body-expanded">{{ visibleBody }}</pre>
              </template>
            </div>
            <p v-if="expandedPanel === 'body' && bodyWasDomTruncated" class="truncation-notice body-modal__notice">{{ t('tryIt.responseBodyTruncated', { limit: RESPONSE_BODY_LIMIT }) }}</p>
          </div>
        </div>
      </Teleport>
    </template>
  </section>
</template>
