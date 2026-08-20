import { ref } from 'vue'

// Which surface the main column shows. The settings view is a peer of the operation
// detail, not a modal: it owns the profile list and the request target, so it stays
// reachable while the reader keeps a profile selected.
export const settingsOpen = ref(false)

export function openSettings(): void {
  settingsOpen.value = true
}

export function closeSettings(): void {
  settingsOpen.value = false
}
