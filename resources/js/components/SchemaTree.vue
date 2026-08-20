<script setup lang="ts">
import type { OpenApiDocument, ReferenceObject, SchemaObject } from '@/types/openapi'

import SchemaNode from './SchemaNode.vue'

withDefaults(
  defineProps<{
    schema: SchemaObject | ReferenceObject
    document: OpenApiDocument
    name?: string
    maxDepth?: number
    maxChildren?: number
  }>(),
  {
    name: 'schema',
    maxDepth: 8,
    maxChildren: 100,
  },
)
</script>

<template>
  <div class="schema-tree">
    <SchemaNode
      :schema="schema"
      :document="document"
      :name="name"
      :depth="0"
      :max-depth="maxDepth"
      :max-children="maxChildren"
      :required="false"
      :visited="[]"
    />
  </div>
</template>
