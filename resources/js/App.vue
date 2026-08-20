<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import EndpointSidebar from '@/components/EndpointSidebar.vue'
import OperationDetail from '@/components/OperationDetail.vue'
import SettingsPanel from '@/components/SettingsPanel.vue'
import { closeSettings, settingsOpen } from '@/lib/appView'
import { LOCALE_LABELS, locale, setLocale, SUPPORTED_LOCALES, t } from '@/lib/i18n'
import {
  collectOperations,
  encodeOperationHash,
  findOperation,
  operationKeyFromHash,
} from '@/lib/operations'
import { theme, toggleTheme } from '@/lib/theme'
import type { OpenApiDocument, OperationEntry } from '@/types/openapi'

const props = defineProps<{
  specUrl: string
  baseUrl: string
  csrfToken: string
}>()

const document = ref<OpenApiDocument>()
const loading = ref(true)
const error = ref<string>()
const selectedKey = ref<string>()

const operationCount = computed(() =>
  document.value ? collectOperations(document.value).length : 0,
)
const selectedOperation = computed(() =>
  document.value ? findOperation(document.value, selectedKey.value) : undefined,
)
const themeToggleLabel = computed(() =>
  theme.value === 'dark' ? t('shell.lightTheme') : t('shell.darkTheme'),
)

onMounted(() => {
  window.addEventListener('hashchange', syncHash)
  syncHash()
  void loadSpec()
})

onBeforeUnmount(() => window.removeEventListener('hashchange', syncHash))

async function loadSpec(): Promise<void> {
  loading.value = true
  error.value = undefined

  try {
    const response = await fetch(props.specUrl, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })

    if (!response.ok) {
      error.value = t('shell.loadError')
      return
    }

    const payload: unknown = await response.json()

    if (!isOpenApiDocument(payload)) {
      error.value = t('shell.invalidSpec')
      return
    }

    document.value = payload

    const firstOperation = findOperation(payload, selectedKey.value)
    if (firstOperation) {
      selectOperation(firstOperation, true)
    }
  } catch {
    error.value = t('shell.loadError')
  } finally {
    loading.value = false
  }
}

function selectOperation(entry: OperationEntry, replace = false): void {
  // Picking an operation leaves the settings view: the reader asked for that endpoint,
  // not for the panel they opened settings from.
  closeSettings()
  selectedKey.value = entry.key
  const hash = encodeOperationHash(entry.key)

  if (replace) {
    window.history.replaceState(null, '', hash)
    return
  }

  window.location.hash = hash
}

function syncHash(): void {
  selectedKey.value = operationKeyFromHash(window.location.hash)
}

function isOpenApiDocument(payload: unknown): payload is OpenApiDocument {
  return (
    typeof payload === 'object' &&
    payload !== null &&
    'paths' in payload &&
    typeof payload.paths === 'object' &&
    payload.paths !== null
  )
}
</script>

<template>
  <div class="app-shell">
    <div v-if="loading" class="state-screen" aria-live="polite">
      <div class="loading-orbit" aria-hidden="true"><span></span></div>
      <p class="section-kicker">{{ t('shell.loadingKicker') }}</p>
      <h1>{{ t('shell.loadingTitle') }}</h1>
      <p><code>{{ t('shell.loadingDescription', { url: specUrl }) }}</code></p>
    </div>

    <div v-else-if="error" class="state-screen state-screen--error" role="alert">
      <span class="state-screen__symbol" aria-hidden="true">!</span>
      <p class="section-kicker">{{ t('shell.errorKicker') }}</p>
      <h1>{{ t('shell.errorTitle') }}</h1>
      <p>{{ error }}</p>
      <button type="button" @click="loadSpec">{{ t('shell.retry') }}</button>
    </div>

    <div v-else-if="document && operationCount === 0" class="state-screen">
      <span class="state-screen__symbol" aria-hidden="true">∅</span>
      <p class="section-kicker">{{ t('shell.emptyKicker') }}</p>
      <h1>{{ t('shell.emptyTitle') }}</h1>
      <p>{{ t('shell.emptyDescription') }}</p>
    </div>

    <template v-else-if="document && selectedOperation">
      <EndpointSidebar
        :document="document"
        :selected-key="selectedOperation.key"
        @select="selectOperation"
      />
      <main class="app-main">
        <div class="app-main__content">
          <div class="shell-controls">
            <div class="locale-switch" role="group" :aria-label="t('shell.language')">
              <button
                v-for="supportedLocale in SUPPORTED_LOCALES"
                :key="supportedLocale"
                type="button"
                class="locale-switch__button"
                :class="{ 'locale-switch__button--active': locale === supportedLocale }"
                :aria-label="`${t('shell.language')}: ${LOCALE_LABELS[supportedLocale]}`"
                :aria-pressed="locale === supportedLocale"
                :title="LOCALE_LABELS[supportedLocale]"
                @click="setLocale(supportedLocale)"
              >
                {{ supportedLocale.toUpperCase() }}
              </button>
            </div>

            <button
              type="button"
              class="theme-toggle"
              :aria-label="themeToggleLabel"
              :title="themeToggleLabel"
              @click="toggleTheme"
            >
              <i
                class="pi"
                :class="theme === 'dark' ? 'pi-sun' : 'pi-moon'"
                aria-hidden="true"
              />
            </button>
          </div>

          <SettingsPanel
            v-if="settingsOpen"
            :document="document"
            :base-url="baseUrl"
            :csrf-token="csrfToken"
          />
          <OperationDetail
            v-else
            :key="selectedOperation.key"
            :document="document"
            :entry="selectedOperation"
            :base-url="baseUrl"
            :csrf-token="csrfToken"
          />
        </div>
      </main>
    </template>
  </div>
</template>
