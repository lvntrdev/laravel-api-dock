import { ref } from 'vue'

const STORAGE_KEY = 'api-dock:theme'

export type Theme = 'light' | 'dark'

function isTheme(candidate: string | undefined): candidate is Theme {
  return candidate === 'light' || candidate === 'dark'
}

function storedTheme(): string | undefined {
  try {
    return typeof localStorage === 'undefined'
      ? undefined
      : localStorage.getItem(STORAGE_KEY) ?? undefined
  } catch {
    return undefined
  }
}

export function resolveInitialTheme(fallback?: string): Theme {
  const stored = storedTheme()

  if (isTheme(stored)) {
    return stored
  }

  if (isTheme(fallback)) {
    return fallback
  }

  const prefersDark = typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-color-scheme: dark)').matches

  return prefersDark ? 'dark' : 'light'
}

export const theme = ref<Theme>(resolveInitialTheme())

export function setTheme(next: Theme): void {
  theme.value = next

  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(STORAGE_KEY, next)
    }
  } catch {
    // Storage can be unavailable without making theme switching unavailable.
  }

  if (typeof document !== 'undefined') {
    document.documentElement.classList.toggle('dark', next === 'dark')
  }
}

export function toggleTheme(): void {
  setTheme(theme.value === 'dark' ? 'light' : 'dark')
}
