<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { t } from '@/lib/i18n'
import { isReference, resolvePointer } from '@/lib/schema'
import {
  initialServerVariables,
  operationServers,
  resolveServerPreview,
  withCurrentOrigin,
} from '@/lib/tryIt'
import type {
  MediaTypeObject,
  OpenApiDocument,
  OperationEntry,
  ParameterObject,
  ReferenceObject,
  RequestBodyObject,
  ResponseObject,
} from '@/types/openapi'

import AiPanel from './AiPanel.vue'
import DiffView from './DiffView.vue'
import MarkdownText from './MarkdownText.vue'
import OperationTabs from './OperationTabs.vue'
import SchemaTree from './SchemaTree.vue'
import TryItPanel from './TryItPanel.vue'

type TabId = 'parameters' | 'request-body' | 'responses' | 'try-it' | 'ai-prompt' | 'spec-diff'

interface OperationTab {
  id: TabId
  label: string
  icon: string
}

interface FeatureItem {
  id: string
  label: string
  value: string
  warning?: boolean
}

const props = defineProps<{
  document: OpenApiDocument
  entry: OperationEntry
  baseUrl: string
  csrfToken: string
}>()

const activeTab = ref<string>('responses')
const features = computed(() => props.entry.operation['x-api-dock-features'])
const featureItems = computed<FeatureItem[]>(() => {
  const declared = features.value

  if (!declared) {
    return []
  }

  const items: FeatureItem[] = []

  if (declared.auth) {
    items.push({ id: 'auth', label: t('operation.auth'), value: declared.auth })
  }

  if (declared.scopes?.length) {
    items.push({ id: 'scopes', label: t('operation.scopes'), value: declared.scopes.join(', ') })
  }

  if (declared.rate_limit) {
    items.push({
      id: 'rate-limit',
      label: t('operation.rateLimit'),
      value: `${declared.rate_limit.limit} / ${declared.rate_limit.per}`,
    })
  }

  if (declared.stability) {
    items.push({ id: 'stability', label: t('operation.stability'), value: declared.stability })
  }

  if (declared.deprecated) {
    items.push({
      id: 'deprecated',
      label: t('operation.deprecated'),
      value: t('common.yes'),
      warning: true,
    })
  }

  return items
})
const parameters = computed(() =>
  props.entry.parameters
    .map((parameter) => resolveObject<ParameterObject>(parameter))
    .filter((parameter): parameter is ParameterObject => !!parameter),
)
const requestBody = computed(() =>
  props.entry.operation.requestBody
    ? resolveObject<RequestBodyObject>(props.entry.operation.requestBody)
    : undefined,
)
const requestSchemas = computed(() => mediaSchemas(requestBody.value?.content))
const responses = computed(() =>
  Object.entries(props.entry.operation.responses ?? {}).map(([status, candidate]) => {
    const response = resolveObject<ResponseObject>(candidate)

    return { status, response, schemas: responseSchemas(response) }
  }),
)
const hasAiMetadata = computed(() => {
  const operation = props.entry.operation

  return Boolean(
    operation['x-ai-hint']
      || operation['x-ai-pitfalls']?.length
      || operation['x-ai-examples']?.length
      || operation['x-ai-tool']
      || operation['x-api-dock-changelog']?.length,
  )
})
const tabs = computed<OperationTab[]>(() => [
  ...(parameters.value.length
    ? [{ id: 'parameters' as const, label: t('tabs.parameters'), icon: 'pi pi-sliders-h' }]
    : []),
  ...(requestBody.value
    ? [{ id: 'request-body' as const, label: t('tabs.requestBody'), icon: 'pi pi-file-edit' }]
    : []),
  { id: 'responses', label: t('tabs.responses'), icon: 'pi pi-server' },
  { id: 'try-it', label: t('tabs.tryIt'), icon: 'pi pi-play' },
  ...(hasAiMetadata.value
    ? [{ id: 'ai-prompt' as const, label: t('tabs.aiPrompt'), icon: 'pi pi-sparkles' }]
    : []),
  { id: 'spec-diff', label: t('tabs.specDiff'), icon: 'pi pi-code' },
])
const resolvedServerUrl = computed(() => {
  const configuredServers = operationServers(props.document, props.entry)
  const servers = typeof window === 'undefined'
    ? configuredServers
    : withCurrentOrigin(configuredServers, window.location.origin)
  const server = servers[0]

  if (!server) {
    return typeof window === 'undefined' ? '' : window.location.origin
  }

  return resolveServerPreview(server.url, initialServerVariables(server)).replace(/\/$/, '')
})

watch(tabs, (availableTabs) => {
  if (!availableTabs.some((tab) => tab.id === activeTab.value)) {
    activeTab.value = 'responses'
  }
}, { flush: 'sync' })

function resolveObject<T extends object>(value: T | ReferenceObject): T | undefined {
  if (!isReference(value)) {
    return value
  }

  return resolvePointer(props.document, value.$ref) as unknown as T | undefined
}

function mediaSchemas(content?: Record<string, MediaTypeObject>) {
  return Object.entries(content ?? {})
    .filter((entry): entry is [string, MediaTypeObject & { schema: NonNullable<MediaTypeObject['schema']> }] =>
      !!entry[1].schema,
    )
    .map(([mediaType, media]) => ({ mediaType, schema: media.schema }))
}

function responseSchemas(response?: ResponseObject) {
  return mediaSchemas(response?.content)
}
</script>

<template>
  <article class="operation-detail">
    <header class="operation-hero">
      <div class="operation-hero__heading">
        <p class="section-kicker">{{ t('operation.kicker') }}</p>
        <h1>{{ entry.operation.summary || entry.operation.operationId || entry.path }}</h1>
      </div>

      <div class="operation-url">
        <span class="method-chip method-chip--large" :data-method="entry.method">
          {{ entry.method }}
        </span>
        <code>
          <span>{{ resolvedServerUrl }}</span><strong>{{ entry.path }}</strong>
        </code>
      </div>

      <div class="operation-description-card">
        <MarkdownText :source="entry.operation.description" class="operation-description" />

        <div
          v-if="featureItems.length"
          class="feature-strip"
          role="group"
          :aria-label="t('operation.featuresLabel')"
        >
          <div
            v-for="feature in featureItems"
            :key="feature.id"
            class="feature-strip__cell"
            :class="{ 'feature-strip__cell--warning': feature.warning }"
          >
            <span>{{ feature.label }}</span>
            <strong>{{ feature.value }}</strong>
          </div>
        </div>
      </div>
    </header>

    <div class="operation-tabs-shell">
      <OperationTabs
        v-model="activeTab"
        :tabs="tabs"
        :aria-label="t('operation.tabsLabel')"
      />

      <div class="operation-tab-panels">
        <section
          id="operation-panel-parameters"
          v-show="activeTab === 'parameters'"
          class="detail-section"
          role="tabpanel"
          aria-labelledby="operation-tab-parameters"
        >
          <div class="detail-section__heading">
            <h2>{{ t('operation.parameters') }}</h2>
            <span class="count-badge">{{ parameters.length }}</span>
          </div>

          <div class="parameter-list">
            <div
              v-for="parameter in parameters"
              :key="`${parameter.in}:${parameter.name}`"
              class="parameter-row"
            >
              <div class="parameter-row__identity">
                <code>{{ parameter.name }}</code>
                <span>{{ parameter.in }}</span>
                <strong v-if="parameter.required">{{ t('operation.required') }}</strong>
              </div>
              <MarkdownText :source="parameter.description" />
              <SchemaTree
                v-if="parameter.schema"
                :schema="parameter.schema"
                :document="document"
                :name="parameter.name"
              />
            </div>
          </div>
        </section>

        <section
          v-if="requestBody"
          id="operation-panel-request-body"
          v-show="activeTab === 'request-body'"
          class="detail-section"
          role="tabpanel"
          aria-labelledby="operation-tab-request-body"
        >
          <div class="detail-section__heading">
            <h2>{{ t('operation.requestBody') }}</h2>
            <span v-if="requestBody.required" class="required-badge">
              {{ t('operation.required') }}
            </span>
          </div>
          <MarkdownText :source="requestBody.description" class="section-description" />

          <div v-if="requestSchemas.length" class="media-stack">
            <div v-for="item in requestSchemas" :key="item.mediaType" class="schema-card">
              <div class="schema-card__bar">
                <span>{{ t('operation.contentType') }}</span>
                <code>{{ item.mediaType }}</code>
              </div>
              <SchemaTree :schema="item.schema" :document="document" :name="t('operation.body')" />
            </div>
          </div>
          <p v-else class="inline-empty">{{ t('operation.noRequestSchema') }}</p>
        </section>

        <section
          id="operation-panel-responses"
          v-show="activeTab === 'responses'"
          class="detail-section"
          role="tabpanel"
          aria-labelledby="operation-tab-responses"
        >
          <div class="detail-section__heading">
            <h2>{{ t('operation.responses') }}</h2>
            <span class="count-badge">{{ responses.length }}</span>
          </div>

          <div v-if="responses.length" class="response-list">
            <details v-for="item in responses" :key="item.status" class="response-card" open>
              <summary>
                <span class="status-code" :data-success="item.status.startsWith('2')">
                  {{ item.status }}
                </span>
                <strong>{{ item.response?.description || t('operation.response') }}</strong>
                <i class="response-card__chevron pi pi-chevron-right" aria-hidden="true" />
              </summary>
              <div class="response-card__body">
                <div
                  v-for="schema in item.schemas"
                  :key="schema.mediaType"
                  class="schema-card schema-card--nested"
                >
                  <div class="schema-card__bar">
                    <span>{{ t('operation.contentType') }}</span>
                    <code>{{ schema.mediaType }}</code>
                  </div>
                  <SchemaTree
                    :schema="schema.schema"
                    :document="document"
                    :name="t('operation.response')"
                  />
                </div>
                <p v-if="item.schemas.length === 0" class="inline-empty">
                  {{ t('operation.noResponseSchema') }}
                </p>
              </div>
            </details>
          </div>
          <p v-else class="inline-empty">{{ t('operation.noResponses') }}</p>
        </section>

        <section
          id="operation-panel-try-it"
          v-show="activeTab === 'try-it'"
          role="tabpanel"
          aria-labelledby="operation-tab-try-it"
        >
          <TryItPanel
            :document="document"
            :operation="entry"
            :base-url="baseUrl"
            :csrf-token="csrfToken"
          />
        </section>

        <section
          id="operation-panel-ai-prompt"
          v-show="activeTab === 'ai-prompt'"
          role="tabpanel"
          aria-labelledby="operation-tab-ai-prompt"
        >
          <AiPanel :document="document" :operation="entry" />
        </section>

        <section
          id="operation-panel-spec-diff"
          v-show="activeTab === 'spec-diff'"
          role="tabpanel"
          aria-labelledby="operation-tab-spec-diff"
        >
          <DiffView :document="document" :operation="entry" />
        </section>
      </div>
    </div>
  </article>
</template>
