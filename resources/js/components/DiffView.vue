<script setup lang="ts">
import { computed, ref } from 'vue'

import { t } from '@/lib/i18n'
import type { SpecDiffChange, SpecDiffResult } from '@/types/diff'
import type { OpenApiDocument, OperationEntry } from '@/types/openapi'

const MAX_CHANGES_PER_GROUP = 500
const SEVERITIES = ['breaking', 'additive', 'cosmetic'] as const

const props = defineProps<{
  document?: OpenApiDocument
  operation?: OperationEntry
  diff?: SpecDiffResult | string
}>()

const pastedJson = ref('')
const parsed = computed<{ result?: SpecDiffResult; error?: string }>(() => {
  const input = pastedJson.value.trim() !== '' ? pastedJson.value : props.diff

  if (input === undefined || input === '') {
    return {}
  }

  try {
    const value: unknown = typeof input === 'string' ? JSON.parse(input) : input
    return { result: validateDiff(value) }
  } catch (reason) {
    return {
      error: reason instanceof SyntaxError
        ? t('diff.invalidJson')
        : reason instanceof Error ? reason.message : t('diff.invalidJson'),
    }
  }
})

const groups = computed(() =>
  SEVERITIES.map((severity) => {
    const changes = parsed.value.result?.changes.filter((change) => change.severity === severity) ?? []
    return {
      severity,
      total: changes.length,
      changes: changes.slice(0, MAX_CHANGES_PER_GROUP),
    }
  }),
)

function validateDiff(value: unknown): SpecDiffResult {
  if (!isRecord(value) || typeof value.has_breaking !== 'boolean' || !Array.isArray(value.changes)) {
    throw new Error(t('diff.invalidShape'))
  }

  return {
    has_breaking: value.has_breaking,
    changes: value.changes.map((change, index) => validateChange(change, index)),
  }
}

function validateChange(value: unknown, index: number): SpecDiffChange {
  if (
    !isRecord(value)
    || !SEVERITIES.includes(value.severity as SpecDiffChange['severity'])
    || typeof value.path !== 'string'
    || (typeof value.operation !== 'string' && value.operation !== null)
    || typeof value.type !== 'string'
    || typeof value.description !== 'string'
  ) {
    throw new Error(t('diff.invalidChange', { index: index + 1 }))
  }

  return {
    severity: value.severity as SpecDiffChange['severity'],
    path: value.path,
    operation: value.operation,
    type: value.type,
    description: value.description,
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
</script>

<template>
  <section class="panel diff-view" data-api-dock-panel="diff">
    <p class="section-description">
      {{ t('diff.description') }}
    </p>

    <label class="field diff-input">
      <span>{{ t('diff.jsonLabel') }}</span>
      <textarea
        v-model="pastedJson"
        data-testid="diff-input"
        rows="7"
        spellcheck="false"
        :placeholder="t('diff.jsonPlaceholder')"
      ></textarea>
    </label>

    <p v-if="parsed.error" class="field-error" role="alert">{{ parsed.error }}</p>
    <p v-else-if="parsed.result && parsed.result.changes.length === 0" class="inline-empty">
      {{ t('diff.noChanges') }}
    </p>

    <div v-else-if="parsed.result" class="diff-groups">
      <section
        v-for="group in groups"
        :key="group.severity"
        class="diff-group"
        :class="`diff-group--${group.severity}`"
        :data-severity="group.severity"
      >
        <header>
          <h3>{{ t(`diff.${group.severity}`) }}</h3>
          <span>{{ group.total }}</span>
        </header>
        <ol v-if="group.changes.length">
          <li v-for="(change, index) in group.changes" :key="`${change.path}:${change.type}:${index}`">
            <div>
              <strong>{{ change.operation || change.path }}</strong>
              <code>{{ change.path }}</code>
            </div>
            <p>{{ change.description }}</p>
            <small>{{ change.type }}</small>
          </li>
        </ol>
        <p v-else class="diff-group__empty">{{ t('diff.noSeverityChanges', { severity: t(`diff.${group.severity}`) }) }}</p>
        <p v-if="group.total > MAX_CHANGES_PER_GROUP" class="truncation-notice">
          {{ t('diff.showingChanges', { limit: MAX_CHANGES_PER_GROUP, total: group.total }) }}
        </p>
      </section>
    </div>
  </section>
</template>
