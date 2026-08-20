import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  en,
  resolveInitialLocale,
  setLocale,
  t,
  tr,
} from '@/lib/i18n'

describe('i18n', () => {
  beforeEach(() => {
    localStorage.clear()
    setLocale('en')
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('keeps the Turkish and English key sets identical', () => {
    expect(Object.keys(tr).sort()).toEqual(Object.keys(en).sort())
  })

  it('interpolates supplied parameters and preserves unknown placeholders', () => {
    expect(t('common.showingFirstOf', { limit: 10, total: 24 })).toBe(
      'Showing the first 10 of 24 items.',
    )
    expect(t('shell.loadingDescription', {})).toBe('Fetching {url}')
    expect(t('shell.loadingDescription')).toBe('Fetching {url}')
  })

  it('collapses an unsupported stored locale to English', () => {
    localStorage.setItem('api-dock:locale', 'de')

    expect(resolveInitialLocale('tr')).toBe('en')
  })

  it('persists the locale and updates the document language', () => {
    setLocale('tr')

    expect(localStorage.getItem('api-dock:locale')).toBe('tr')
    expect(document.documentElement.lang).toBe('tr')
  })

  it('keeps localization available when storage writes fail', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('Storage is disabled.')
    })

    expect(() => setLocale('tr')).not.toThrow()
    expect(document.documentElement.lang).toBe('tr')
    expect(t('common.yes')).toBe('Evet')
  })
})
