import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  resolveInitialTheme,
  setTheme,
  toggleTheme,
} from '@/lib/theme'

describe('theme', () => {
  beforeEach(() => {
    localStorage.clear()
    setTheme('light')
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('prefers a stored theme over the server default', () => {
    localStorage.setItem('api-dock:theme', 'dark')

    expect(resolveInitialTheme('light')).toBe('dark')
  })

  it('toggles the document class and persists each new theme', () => {
    toggleTheme()

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(localStorage.getItem('api-dock:theme')).toBe('dark')

    toggleTheme()

    expect(document.documentElement.classList.contains('dark')).toBe(false)
    expect(localStorage.getItem('api-dock:theme')).toBe('light')
  })

  it('falls back to the server default for an unrecognised stored value', () => {
    localStorage.setItem('api-dock:theme', 'sepia')

    expect(resolveInitialTheme('dark')).toBe('dark')
  })

  it('keeps theme switching available when storage writes fail', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('Storage is disabled.')
    })

    expect(() => setTheme('dark')).not.toThrow()
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })

  it('falls back to light when matchMedia is unavailable', () => {
    localStorage.clear()
    vi.stubGlobal('matchMedia', undefined)

    expect(resolveInitialTheme()).toBe('light')
  })
})
