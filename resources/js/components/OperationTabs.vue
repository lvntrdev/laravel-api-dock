<script setup lang="ts">
defineProps<{
  tabs: ReadonlyArray<{
    id: string
    label: string
    icon: string
  }>
  ariaLabel: string
}>()

const activeId = defineModel<string>({ required: true })
</script>

<template>
  <div class="operation-tabs" role="tablist" :aria-label="ariaLabel">
    <div v-for="tab in tabs" :key="tab.id" class="operation-tabs__item">
      <button
        :id="`operation-tab-${tab.id}`"
        type="button"
        class="operation-tabs__button"
        :class="{ 'operation-tabs__button--active': activeId === tab.id }"
        role="tab"
        :aria-controls="`operation-panel-${tab.id}`"
        :aria-selected="activeId === tab.id"
        :tabindex="activeId === tab.id ? 0 : -1"
        @click="activeId = tab.id"
      >
        <i :class="tab.icon" aria-hidden="true" />
        <span>{{ tab.label }}</span>
      </button>
    </div>
  </div>
</template>
