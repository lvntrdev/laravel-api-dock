<script setup lang="ts">
import { computed } from 'vue'

import { t } from '@/lib/i18n'
import {
  isReference,
  mergeAllOf,
  printableValue,
  resolvePointer,
  schemaAllowsNull,
  schemaType,
} from '@/lib/schema'
import type { OpenApiDocument, ReferenceObject, SchemaObject } from '@/types/openapi'

import MarkdownText from './MarkdownText.vue'

const props = defineProps<{
  schema: SchemaObject | ReferenceObject
  document: OpenApiDocument
  name: string
  required: boolean
  depth: number
  maxDepth: number
  maxChildren: number
  visited: string[]
}>()

const referencePointer = computed(() =>
  isReference(props.schema) ? props.schema.$ref : undefined,
)
const isCircular = computed(
  () => !!referencePointer.value && props.visited.includes(referencePointer.value),
)
const isTruncated = computed(() => props.depth >= props.maxDepth || isCircular.value)
const resolvedSchema = computed<SchemaObject | undefined>(() => {
  if (isTruncated.value) {
    return undefined
  }

  const candidate = referencePointer.value
    ? resolvePointer(props.document, referencePointer.value)
    : props.schema

  if (!candidate || isReference(candidate)) {
    return undefined
  }

  return mergeAllOf(candidate, props.document, new Set(props.visited))
})
const nextVisited = computed(() =>
  referencePointer.value ? [...props.visited, referencePointer.value] : props.visited,
)
const requiredProperties = computed(() => new Set(resolvedSchema.value?.required ?? []))
const propertyEntries = computed(() =>
  Object.entries(resolvedSchema.value?.properties ?? {}).slice(0, props.maxChildren),
)
const propertiesTruncated = computed(() =>
  Object.keys(resolvedSchema.value?.properties ?? {}).length > props.maxChildren,
)
const enumValues = computed(() =>
  (resolvedSchema.value?.enum ?? []).slice(0, props.maxChildren),
)
const enumTruncated = computed(() =>
  (resolvedSchema.value?.enum?.length ?? 0) > props.maxChildren,
)
const variants = computed(() => {
  const schema = resolvedSchema.value

  if (schema?.oneOf?.length) {
    return { label: t('schema.oneOf'), schemas: schema.oneOf }
  }

  if (schema?.anyOf?.length) {
    return { label: t('schema.anyOf'), schemas: schema.anyOf }
  }

  return undefined
})
const visibleVariants = computed(() => variants.value?.schemas.slice(0, props.maxChildren) ?? [])
const variantsTruncated = computed(() =>
  (variants.value?.schemas.length ?? 0) > props.maxChildren,
)
const typeLabel = computed(() =>
  resolvedSchema.value ? schemaType(resolvedSchema.value) : t('schema.unresolved'),
)
const nullable = computed(() =>
  resolvedSchema.value ? schemaAllowsNull(resolvedSchema.value) : false,
)
</script>

<template>
  <div class="schema-node">
    <div class="schema-node__line">
      <span class="schema-node__branch" aria-hidden="true">—</span>
      <strong class="schema-node__name">{{ name }}</strong>

      <template v-if="!isTruncated && resolvedSchema">
        <span class="schema-node__type">{{ typeLabel }}</span>
        <span v-if="required" class="schema-node__required">{{ t('schema.required') }}</span>
        <span v-if="nullable" class="schema-node__nullable">{{ t('schema.nullable') }}</span>
        <span v-if="resolvedSchema.format" class="schema-node__format">
          {{ resolvedSchema.format }}
        </span>
        <span v-if="referencePointer" class="schema-node__ref" :title="referencePointer">
          {{ referencePointer.split('/').at(-1) }}
        </span>
      </template>

      <span v-else-if="isTruncated" class="schema-node__truncated" role="note">
        {{ isCircular ? t('schema.circularReference') : t('schema.depthLimit') }}
      </span>
      <span v-else class="schema-node__truncated" role="note">
        {{ t('schema.unresolvedReference') }}
      </span>
    </div>

    <template v-if="resolvedSchema && !isTruncated">
      <MarkdownText :source="resolvedSchema.description" class="schema-node__description" />

      <div v-if="resolvedSchema.enum?.length" class="schema-node__enum">
        <span>{{ t('schema.enum') }}</span>
        <code v-for="value in enumValues" :key="printableValue(value)">
          {{ printableValue(value) }}
        </code>
        <span v-if="enumTruncated" class="schema-node__truncated">
          {{ t('schema.valuesLimit', { limit: maxChildren }) }}
        </span>
      </div>

      <div v-if="variants" class="schema-node__children schema-node__variants">
        <span class="schema-node__combiner">{{ variants.label }}</span>
        <SchemaNode
          v-for="(variant, index) in visibleVariants"
          :key="index"
          :schema="variant"
          :document="document"
          :name="t('schema.variantOption', { variant: variants.label, index: index + 1 })"
          :required="false"
          :depth="depth + 1"
          :max-depth="maxDepth"
          :max-children="maxChildren"
          :visited="nextVisited"
        />
        <span v-if="variantsTruncated" class="schema-node__truncated">
          {{ t('schema.variantsLimit', { limit: maxChildren }) }}
        </span>
      </div>

      <div v-if="resolvedSchema.items" class="schema-node__children">
        <SchemaNode
          :schema="resolvedSchema.items"
          :document="document"
          :name="t('schema.items')"
          :required="true"
          :depth="depth + 1"
          :max-depth="maxDepth"
          :max-children="maxChildren"
          :visited="nextVisited"
        />
      </div>

      <div v-if="resolvedSchema.properties" class="schema-node__children">
        <SchemaNode
          v-for="([propertyName, property]) in propertyEntries"
          :key="propertyName"
          :schema="property"
          :document="document"
          :name="propertyName"
          :required="requiredProperties.has(propertyName)"
          :depth="depth + 1"
          :max-depth="maxDepth"
          :max-children="maxChildren"
          :visited="nextVisited"
        />
        <span v-if="propertiesTruncated" class="schema-node__truncated">
          {{ t('schema.propertiesLimit', { limit: maxChildren }) }}
        </span>
      </div>

      <div
        v-if="resolvedSchema.additionalProperties && typeof resolvedSchema.additionalProperties === 'object'"
        class="schema-node__children"
      >
        <SchemaNode
          :schema="resolvedSchema.additionalProperties"
          :document="document"
          :name="t('schema.additionalProperties')"
          :required="false"
          :depth="depth + 1"
          :max-depth="maxDepth"
          :max-children="maxChildren"
          :visited="nextVisited"
        />
      </div>
    </template>
  </div>
</template>
