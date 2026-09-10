import { computed, ref } from 'vue'

// Which surface the main column shows. Settings and the changelog are peers of the
// operation detail, not modals: both own their own scroll position and stay
// reachable while the reader keeps an operation selected.
export type MainView = 'operation' | 'settings' | 'changelog'

export const mainView = ref<MainView>('operation')

export const settingsOpen = computed(() => mainView.value === 'settings')
export const changelogOpen = computed(() => mainView.value === 'changelog')

export function openSettings(): void {
  mainView.value = 'settings'
}

export function openChangelog(): void {
  mainView.value = 'changelog'
}

export function closeSettings(): void {
  mainView.value = 'operation'
}
