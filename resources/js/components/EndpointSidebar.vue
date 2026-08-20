<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { openSettings, settingsOpen } from '@/lib/appView'
import { t } from '@/lib/i18n'
import { groupOperations } from '@/lib/operations'
import type { OpenApiDocument, OperationEntry } from '@/types/openapi'

const props = defineProps<{
  document: OpenApiDocument
  selectedKey?: string
}>()

const emit = defineEmits<{
  select: [operation: OperationEntry]
}>()

const query = ref('')
const searchInput = ref<HTMLInputElement>()
// Groups start closed. A spec with a dozen tags otherwise opens as a wall of
// endpoints the reader has to scroll past to reach the tag they came for.
const expandedTags = ref(new Set<string>())
const groups = computed(() => groupOperations(props.document, query.value))

onMounted(() => window.addEventListener('keydown', focusSearch))
onBeforeUnmount(() => window.removeEventListener('keydown', focusSearch))

function focusSearch(event: KeyboardEvent): void {
  if (event.key !== '/' || event.altKey || event.ctrlKey || event.metaKey) {
    return
  }

  const activeElement = document.activeElement
  if (
    activeElement instanceof HTMLElement
    && ['INPUT', 'TEXTAREA', 'SELECT'].includes(activeElement.tagName)
  ) {
    return
  }

  event.preventDefault()
  searchInput.value?.focus()
}

function isGroupOpen(tag: string): boolean {
  // A search still forces every matching group open: the reader is looking at results,
  // not at the collapsed state they left behind.
  return query.value.trim() !== '' || expandedTags.value.has(tag)
}

function toggleGroup(tag: string): void {
  if (expandedTags.value.has(tag)) {
    expandedTags.value.delete(tag)
    return
  }

  expandedTags.value.add(tag)
}

function groupLabel(tag: string): string {
  return tag === 'Untagged' ? t('sidebar.untagged') : tag
}
</script>

<template>
  <aside class="endpoint-sidebar" :aria-label="t('sidebar.ariaLabel')">
    <div class="endpoint-sidebar__top">
      <a class="brand" href="#" :aria-label="t('sidebar.homeAriaLabel')">
        <span class="brand__mark" aria-hidden="true"><i class="pi pi-box" /></span>
        <span class="brand__copy">
          <strong>{{ t('sidebar.brandName') }}</strong>
          <small>{{ t('sidebar.brandSubtitle') }}</small>
        </span>
      </a>

      <button
        type="button"
        class="sidebar-pinned"
        data-testid="open-settings"
        :class="{ 'sidebar-pinned--active': settingsOpen }"
        :aria-current="settingsOpen ? 'page' : undefined"
        @click="openSettings"
      >
        <i class="pi pi-cog" aria-hidden="true" />
        <span>{{ t('settings.title') }}</span>
      </button>

      <label class="search-field">
        <span class="sr-only">{{ t('sidebar.searchLabel') }}</span>
        <i class="pi pi-search" aria-hidden="true" />
        <input
          ref="searchInput"
          v-model="query"
          type="search"
          :placeholder="t('sidebar.searchPlaceholder')"
        />
        <kbd>/</kbd>
      </label>
    </div>

    <nav class="endpoint-sidebar__nav">
      <section v-for="group in groups" :key="group.tag" class="operation-group">
        <button
          type="button"
          class="operation-group__toggle"
          :aria-expanded="isGroupOpen(group.tag)"
          :aria-label="t(isGroupOpen(group.tag) ? 'sidebar.collapseGroup' : 'sidebar.expandGroup', { tag: groupLabel(group.tag) })"
          @click="toggleGroup(group.tag)"
        >
          <i
            class="pi"
            :class="isGroupOpen(group.tag) ? 'pi-chevron-down' : 'pi-chevron-right'"
            aria-hidden="true"
          />
          <span>{{ groupLabel(group.tag) }}</span>
          <small>{{ group.operations.length }}</small>
        </button>

        <template v-if="isGroupOpen(group.tag)">
          <button
            v-for="entry in group.operations"
            :key="entry.key"
            type="button"
            class="operation-link"
            :class="{ 'operation-link--active': entry.key === selectedKey }"
            :aria-current="entry.key === selectedKey ? 'page' : undefined"
            @click="emit('select', entry)"
          >
            <span class="operation-link__primary">
              <span class="method-chip" :data-method="entry.method">{{ entry.method }}</span>
              <strong>{{ entry.operation.summary || entry.path }}</strong>
            </span>
            <small>{{ entry.path }}</small>
          </button>
        </template>
      </section>

      <div v-if="groups.length === 0" class="sidebar-empty">
        <i class="pi pi-search" aria-hidden="true" />
        <p>{{ t('sidebar.noMatches', { query }) }}</p>
      </div>
    </nav>

    <footer class="endpoint-sidebar__footer">
      <span class="endpoint-sidebar__status">
        <span class="status-dot" aria-hidden="true" />
        {{ t('sidebar.openapiVersion', { version: document.openapi || '3.x' }) }}
      </span>
      <span>{{ document.info?.version || t('sidebar.unversioned') }}</span>
    </footer>
  </aside>
</template>
