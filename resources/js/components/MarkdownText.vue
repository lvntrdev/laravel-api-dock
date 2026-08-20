<script setup lang="ts">
import { computed } from 'vue'
import { renderMarkdown } from '../lib/markdown'

const props = defineProps<{
  source?: string | null
  tag?: string
}>()

// The single place any description in this viewer becomes HTML. Keeping it one
// component means no caller can reach `v-html` with unsanitised markup.
const html = computed(() => renderMarkdown(props.source ?? ''))
</script>

<template>
  <component :is="tag ?? 'div'" v-if="html" class="markdown" v-html="html" />
</template>
