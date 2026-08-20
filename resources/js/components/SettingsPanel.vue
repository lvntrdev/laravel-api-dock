<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue'

import { t } from '@/lib/i18n'
import { collectOperations } from '@/lib/operations'
import { narrowServerVariables, resolveServerPreview, withCurrentOrigin } from '@/lib/tryIt'
import type { TryItProfile } from '@/lib/tryIt'
import {
  createProfile as createStoredProfile,
  deleteProfile as deleteStoredProfile,
  loadProfiles,
  loadingProfiles,
  profileDenied,
  profileError,
  profiles,
} from '@/lib/tryItProfiles'
import {
  ensureServerVariables,
  plainBaseUrl as storedPlainBaseUrl,
  selectedProfileId as storedSelectedProfileId,
  selectedServer as storedSelectedServer,
  serverVariables,
  setPlainBaseUrl,
  setSelectedProfileId,
  setSelectedServer,
  setServerVariable,
} from '@/lib/tryItSession'
import type { OpenApiDocument, ServerObject, ServerVariableObject } from '@/types/openapi'

const props = defineProps<{
  document: OpenApiDocument
  baseUrl: string
  csrfToken: string
}>()

const ARTISAN_COMMANDS = [
  { command: 'php artisan api-dock:sync', key: 'settings.commandSync' },
  { command: 'php artisan api-dock:diff', key: 'settings.commandDiff' },
  { command: 'php artisan api-dock:export --llms --mcp --openapi', key: 'settings.commandExport' },
  { command: 'php artisan api-dock:agent-guide', key: 'settings.commandAgentGuide' },
] as const

const servers = withCurrentOrigin(props.document.servers ?? [], window.location.origin)
// The panel follows the stored choice so a spec that reorders its servers cannot move
// the request; an unknown value falls back to the first entry.
const documentServer = computed<ServerObject | undefined>(() =>
  servers.find((server) => server.url === storedSelectedServer.value) ?? servers[0],
)
const selectedServerUrl = computed({
  get: () => documentServer.value?.url ?? '',
  set: setSelectedServer,
})

// Every server the document mentions, path- and operation-level ones included: a
// variable declared only on one operation still needs an editor somewhere.
const declaredServers = computed<ServerObject[]>(() => [
  ...servers,
  ...Object.values(props.document.paths ?? {}).flatMap((pathItem) => pathItem.servers ?? []),
  ...collectOperations(props.document).flatMap((entry) => entry.operation.servers ?? []),
])

// Every variable those servers declare, so a profile can carry the tenant slug once
// instead of the reader retyping it per operation.
const serverVariableDefinitions = computed<Record<string, ServerVariableObject>>(() => {
  const definitions: Record<string, ServerVariableObject> = {}

  for (const server of declaredServers.value) {
    for (const [name, definition] of Object.entries(server.variables ?? {})) {
      definitions[name] ??= definition
    }
  }

  return definitions
})

const plainBaseUrl = computed({
  get: () => storedPlainBaseUrl.value,
  set: setPlainBaseUrl,
})

// Same precedence as the try-it panel: a base URL typed here (or carried by a profile)
// is a concrete host and outranks the spec's template, and the template resolves through
// the narrowed values so this preview names the host the request will reach.
const resolvedTarget = computed(() => {
  if (storedPlainBaseUrl.value.trim() !== '' || !documentServer.value) {
    return storedPlainBaseUrl.value
  }

  return resolveServerPreview(
    documentServer.value.url,
    narrowServerVariables(documentServer.value, serverVariables.value),
  )
})

const profileForm = reactive({
  label: '',
  baseUrl: '',
  serverVariables: {} as Record<string, string>,
  scheme: 'bearer' as TryItProfile['scheme'],
  credential: '',
  credentialHeader: '',
})

onMounted(() => {
  ensureServerVariables(defaultServerVariables())
  resetProfileServerVariables()
  void loadProfiles(endpointTarget())
})

async function submitProfile(): Promise<void> {
  const created = await createStoredProfile(endpointTarget(), {
    label: profileForm.label,
    baseUrl: profileForm.baseUrl,
    serverVariables: profileServerVariableValues(),
    scheme: profileForm.scheme,
    credential: profileForm.credential,
    credentialHeader: profileForm.credentialHeader,
  })

  // The write-only value leaves component state whether or not the request landed.
  profileForm.credential = ''

  if (created === null) {
    return
  }

  profileForm.label = ''
  profileForm.baseUrl = ''
  profileForm.credentialHeader = ''
  resetProfileServerVariables()
}

async function removeProfile(id: string): Promise<void> {
  await deleteStoredProfile(endpointTarget(), id)
}

function endpointTarget(): { baseUrl: string; csrfToken: string } {
  return { baseUrl: props.baseUrl, csrfToken: props.csrfToken }
}

function defaultServerVariables(): Record<string, string> {
  return Object.fromEntries(
    Object.entries(serverVariableDefinitions.value).map(([name, definition]) => [
      name,
      definition.default ?? '',
    ]),
  )
}

function resetProfileServerVariables(): void {
  profileForm.serverVariables = defaultServerVariables()
}

function profileServerVariableValues(): Record<string, string> {
  return Object.fromEntries(
    Object.keys(serverVariableDefinitions.value)
      .map((name) => [name, profileForm.serverVariables[name] ?? ''] as const)
      // An empty value is dropped, not stored: the server reads '' as absent and falls
      // back to the spec default, which would diverge from what is shown here.
      .filter(([, value]) => value !== ''),
  )
}

function updateProfileServerVariable(name: string, event: Event): void {
  const target = event.target

  if (target instanceof HTMLInputElement || target instanceof HTMLSelectElement) {
    profileForm.serverVariables = { ...profileForm.serverVariables, [name]: target.value }
  }
}

function updateServerVariable(name: string, event: Event): void {
  const target = event.target

  if (target instanceof HTMLInputElement || target instanceof HTMLSelectElement) {
    setServerVariable(name, target.value)
  }
}

function isSelected(id: string): boolean {
  return storedSelectedProfileId.value === id
}

function selectProfile(id: string): void {
  setSelectedProfileId(id)
}
</script>

<template>
  <section class="panel settings-panel" data-api-dock-panel="settings">
    <header class="settings-panel__header">
      <h1>{{ t('settings.title') }}</h1>
      <p>{{ t('settings.intro') }}</p>
    </header>

    <section class="settings-section">
      <h2>{{ t('settings.profilesTitle') }}</h2>
      <p class="settings-section__hint">{{ t('settings.profilesHint') }}</p>

      <p v-if="profileDenied" class="panel-disabled" role="status" data-testid="settings-disabled">{{ profileDenied }}</p>

      <template v-else>
        <span v-if="loadingProfiles" class="panel-status">{{ t('tryIt.loadingProfiles') }}</span>

        <ul v-if="profiles.length" class="profile-list" data-testid="profile-list">
          <li v-for="profile in profiles" :key="profile.id" class="profile-list__item">
            <span class="profile-list__label">
              {{ profile.label }}
              <em v-if="isSelected(profile.id)">{{ t('settings.profileActive') }}</em>
            </span>
            <code>{{ profile.credential_hint }}</code>
            <code v-if="profile.base_url">{{ profile.base_url }}</code>
            <span class="profile-list__actions">
              <button
                class="button"
                :class="isSelected(profile.id) ? 'button--primary' : 'button--quiet'"
                type="button"
                :aria-pressed="isSelected(profile.id)"
                :disabled="isSelected(profile.id)"
                :data-testid="`select-profile-${profile.id}`"
                @click="selectProfile(profile.id)"
              >{{ t('settings.selectProfile') }}</button>
              <button
                class="button button--quiet"
                type="button"
                :data-testid="`delete-profile-${profile.id}`"
                @click="removeProfile(profile.id)"
              >{{ t('tryIt.deleteProfile') }}</button>
            </span>
          </li>
        </ul>
        <p v-else-if="!loadingProfiles" class="settings-empty">{{ t('settings.noProfiles') }}</p>

        <form class="profile-form" @submit.prevent="submitProfile">
          <h3>{{ t('settings.newProfile') }}</h3>
          <label class="field"><span>{{ t('tryIt.label') }}</span><input v-model="profileForm.label" type="text"></label>
          <label class="field">
            <span>{{ t('tryIt.baseUrlOptional') }}</span>
            <input v-model="profileForm.baseUrl" data-testid="profile-base-url" type="text" :placeholder="t('tryIt.baseUrlOptionalPlaceholder')">
          </label>
          <div v-if="Object.keys(serverVariableDefinitions).length" class="profile-server-variables">
            <span class="profile-server-variables__label">{{ t('tryIt.serverVariables') }}</span>
            <div class="field-grid">
              <label v-for="(variable, name) in serverVariableDefinitions" :key="name" class="field">
                <span>{{ name }}</span>
                <select
                  v-if="variable.enum"
                  :value="profileForm.serverVariables[name]"
                  :data-testid="`profile-server-variable-${name}`"
                  @change="updateProfileServerVariable(name, $event)"
                >
                  <option v-for="value in variable.enum" :key="value" :value="value">{{ value }}</option>
                </select>
                <input
                  v-else
                  :value="profileForm.serverVariables[name]"
                  :data-testid="`profile-server-variable-${name}`"
                  type="text"
                  @input="updateProfileServerVariable(name, $event)"
                >
                <small v-if="variable.description">{{ variable.description }}</small>
              </label>
            </div>
          </div>
          <label class="field">
            <span>{{ t('tryIt.scheme') }}</span>
            <select v-model="profileForm.scheme">
              <option value="bearer">{{ t('tryIt.bearer') }}</option>
              <option value="basic">{{ t('tryIt.basic') }}</option>
              <option value="header">{{ t('tryIt.customHeader') }}</option>
            </select>
          </label>
          <label v-if="profileForm.scheme === 'header'" class="field">
            <span>{{ t('tryIt.credentialHeader') }}</span>
            <input v-model="profileForm.credentialHeader" type="text" required>
          </label>
          <label class="field">
            <span>{{ t('tryIt.credentialWriteOnly') }}</span>
            <input v-model="profileForm.credential" data-testid="credential-input" type="password" required autocomplete="off">
          </label>
          <button class="button button--primary" type="submit">{{ t('tryIt.saveProfile') }}</button>
          <p v-if="profileError" class="field-error" role="alert" data-testid="settings-profile-error">{{ profileError }}</p>
        </form>
      </template>
    </section>

    <section class="settings-section">
      <h2>{{ t('settings.targetTitle') }}</h2>
      <p class="settings-section__hint">{{ t('settings.targetHint') }}</p>

      <div class="field-grid">
        <label v-if="servers.length > 1" class="field">
          <span>{{ t('tryIt.server') }}</span>
          <select v-model="selectedServerUrl" data-testid="server-select">
            <option v-for="(server, index) in servers" :key="`${server.url}:${index}`" :value="server.url">
              {{ server.description || server.url }}
            </option>
          </select>
        </label>

        <label v-for="(variable, name) in serverVariableDefinitions" :key="name" class="field">
          <span>{{ name }}</span>
          <select v-if="variable.enum" :value="serverVariables[name]" :data-testid="`server-variable-${name}`" @change="updateServerVariable(name, $event)">
            <option v-for="value in variable.enum" :key="value" :value="value">{{ value }}</option>
          </select>
          <input v-else :value="serverVariables[name]" :data-testid="`server-variable-${name}`" type="text" @input="updateServerVariable(name, $event)">
          <small v-if="variable.description">{{ variable.description }}</small>
        </label>

        <label class="field">
          <span>{{ t('settings.baseUrlOverride') }}</span>
          <input v-model="plainBaseUrl" data-testid="base-url-input" type="url" :placeholder="t('tryIt.baseUrlPlaceholder')">
          <small>{{ t('settings.baseUrlOverrideHint') }}</small>
        </label>

        <label class="field">
          <span>{{ t('tryIt.resolvedBaseUrl') }}</span>
          <input :value="resolvedTarget" data-testid="resolved-base-url" type="text" readonly>
        </label>
      </div>
    </section>

    <section class="settings-section">
      <h2>{{ t('settings.usageTitle') }}</h2>
      <p class="settings-section__hint">{{ t('settings.usageIntro') }}</p>
      <ol class="settings-steps">
        <li>{{ t('settings.usageStep1') }}</li>
        <li>{{ t('settings.usageStep2') }}</li>
        <li>{{ t('settings.usageStep3') }}</li>
        <li>{{ t('settings.usageStep4') }}</li>
      </ol>
    </section>

    <section class="settings-section">
      <h2>{{ t('settings.commandsTitle') }}</h2>
      <p class="settings-section__hint">{{ t('settings.commandsIntro') }}</p>
      <dl class="settings-commands" data-testid="settings-commands">
        <template v-for="entry in ARTISAN_COMMANDS" :key="entry.command">
          <dt><code>{{ entry.command }}</code></dt>
          <dd>{{ t(entry.key) }}</dd>
        </template>
      </dl>
    </section>
  </section>
</template>
