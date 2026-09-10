<script setup lang="ts">
import { computed } from 'vue'

import { collectChangelog, countChangelogEntries } from '@/lib/changelog'
import { t } from '@/lib/i18n'
import type { OpenApiDocument, OperationEntry } from '@/types/openapi'

const props = defineProps<{
  document: OpenApiDocument
}>()

const emit = defineEmits<{
  select: [operation: OperationEntry]
}>()

const releases = computed(() => collectChangelog(props.document))
const entryCount = computed(() => countChangelogEntries(releases.value))
const breakingCount = computed(() =>
  releases.value.reduce(
    (total, release) => total + release.entries.filter((entry) => entry.breaking).length,
    0,
  ),
)
</script>

<template>
  <section class="api-changelog" data-testid="api-changelog">
    <header class="api-changelog__header">
      <p class="section-kicker">{{ t('changelog.kicker') }}</p>
      <h1>{{ t('changelog.title') }}</h1>
      <p>{{ t('changelog.description') }}</p>
      <p class="api-changelog__counts">
        {{ t('changelog.summary', { entries: entryCount, breaking: breakingCount }) }}
      </p>
    </header>

    <p v-if="releases.length === 0" class="api-changelog__empty">
      {{ t('changelog.empty') }}
    </p>

    <ol v-else class="api-changelog__releases">
      <li v-for="release in releases" :key="release.date" class="api-changelog__release">
        <h2><time>{{ release.date }}</time></h2>
        <ul>
          <li v-for="(entry, index) in release.entries" :key="`${entry.operation.key}:${index}`">
            <button
              type="button"
              class="api-changelog__entry"
              @click="emit('select', entry.operation)"
            >
              <span class="method-chip" :data-method="entry.operation.method">
                {{ entry.operation.method }}
              </span>
              <span class="api-changelog__entry-body">
                <strong>{{ entry.summary }}</strong>
                <small>{{ entry.operation.path }}</small>
              </span>
              <span v-if="entry.breaking" class="api-changelog__breaking">
                {{ t('ai.breaking') }}
              </span>
            </button>
          </li>
        </ul>
      </li>
    </ol>
  </section>
</template>
