<script setup lang="ts">
import { computed, ref } from 'vue'

import { t } from '@/lib/i18n'

const MAX_DEPTH = 20
const MAX_ROWS = 2000

type JsonTone = 'boolean' | 'null' | 'number' | 'notice' | 'punctuation' | 'string'

interface JsonRow {
  collapsed: boolean
  depth: number
  hasToggle: boolean
  id: number
  key: string | null
  path: string
  tone: JsonTone
  value: string
}

type ParsedInput =
  | { valid: true; value: unknown }
  | { source: string; valid: false }

const props = defineProps<{
  source?: string
  value?: unknown
}>()

const collapsedPaths = ref<Record<string, boolean>>({})

const parsedInput = computed<ParsedInput>(() => {
  if (props.source === undefined) {
    return { valid: true, value: props.value }
  }

  try {
    return { valid: true, value: JSON.parse(props.source) as unknown }
  } catch {
    return { source: props.source, valid: false }
  }
})

function leafTone(value: unknown): JsonTone {
  if (value === null) {
    return 'null'
  }

  switch (typeof value) {
    case 'string':
      return 'string'
    case 'number':
      return 'number'
    case 'boolean':
      return 'boolean'
    default:
      return 'punctuation'
  }
}

function serializedLeaf(value: unknown): string {
  const serialized = JSON.stringify(value)

  return serialized === undefined ? String(value) : serialized
}

function flattenJson(value: unknown): JsonRow[] {
  const rows: JsonRow[] = []
  let nextRowId = 0
  let rowLimitReached = false

  function pushRow(row: Omit<JsonRow, 'id'>): boolean {
    if (rows.length >= MAX_ROWS) {
      rowLimitReached = true
      return false
    }

    rows.push({ ...row, id: nextRowId })
    nextRowId += 1
    return true
  }

  function flattenNode(
    node: unknown,
    keyLabel: string | null,
    path: string,
    depth: number,
    trailing: boolean,
  ): void {
    if (rowLimitReached) {
      return
    }

    const isContainer = node !== null && typeof node === 'object'

    if (!isContainer) {
      pushRow({
        collapsed: false,
        depth,
        hasToggle: false,
        key: keyLabel,
        path,
        tone: leafTone(node),
        value: `${serializedLeaf(node)}${trailing ? ',' : ''}`,
      })
      return
    }

    if (depth >= MAX_DEPTH) {
      pushRow({
        collapsed: false,
        depth,
        hasToggle: false,
        key: keyLabel,
        path,
        tone: 'notice',
        value: `${t('json.depthLimit')}${trailing ? ',' : ''}`,
      })
      return
    }

    const isArray = Array.isArray(node)
    const open = isArray ? '[' : '{'
    const close = isArray ? ']' : '}'
    const collapsed = collapsedPaths.value[path] === true
    const entries: Array<[string | null, unknown]> = isArray
      ? node.map((entry) => [null, entry])
      : Object.entries(node as Record<string, unknown>)

    if (!pushRow({
      collapsed,
      depth,
      hasToggle: true,
      key: keyLabel,
      path,
      tone: 'punctuation',
      value: collapsed ? `${open} … ${close}${trailing ? ',' : ''}` : open,
    })) {
      return
    }

    if (collapsed) {
      return
    }

    entries.forEach(([entryKey, entry], index) => {
      const pathPart = entryKey === null ? String(index) : entryKey
      flattenNode(
        entry,
        entryKey,
        `${path}/${pathPart}`,
        depth + 1,
        index < entries.length - 1,
      )
    })

    pushRow({
      collapsed: false,
      depth,
      hasToggle: false,
      key: null,
      path: `${path}/close`,
      tone: 'punctuation',
      value: `${close}${trailing ? ',' : ''}`,
    })
  }

  flattenNode(value, null, '$', 0, false)

  if (rowLimitReached) {
    rows.splice(MAX_ROWS - 1, 1, {
      collapsed: false,
      depth: 0,
      hasToggle: false,
      id: nextRowId,
      key: null,
      path: '$/row-limit',
      tone: 'notice',
      value: t('common.showingFirst', { limit: MAX_ROWS }),
    })
  }

  return rows
}

const rows = computed(() => parsedInput.value.valid ? flattenJson(parsedInput.value.value) : [])

function toggle(path: string): void {
  collapsedPaths.value[path] = collapsedPaths.value[path] !== true
}
</script>

<template>
  <pre
    v-if="!parsedInput.valid"
    class="json-tree json-tree--fallback"
  >{{ parsedInput.source }}</pre>
  <div
    v-else
    class="json-tree"
  >
    <div
      v-for="row in rows"
      :key="row.id"
      class="json-tree__row"
      :data-depth="row.depth"
      :data-path="row.path"
      :style="{ marginLeft: `${row.depth * 16}px` }"
    >
      <button
        v-if="row.hasToggle"
        type="button"
        class="json-tree__toggle"
        :aria-expanded="!row.collapsed"
        @click="toggle(row.path)"
      >
        <i :class="row.collapsed ? 'pi pi-chevron-right' : 'pi pi-chevron-down'" />
      </button>
      <span
        v-else
        class="json-tree__spacer"
      />
      <span
        v-if="row.key !== null"
        class="json-tree__label"
      >
        <span class="json-tree__key">{{ JSON.stringify(row.key) }}</span><span class="json-tree__punctuation">:</span>
      </span>
      <span
        class="json-tree__value"
        :class="`json-tree__value--${row.tone}`"
      >{{ row.value }}</span>
    </div>
  </div>
</template>
