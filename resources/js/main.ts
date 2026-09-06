import { createApp } from 'vue'

import App from './App.vue'
import 'primeicons/primeicons.css'
import '@fontsource/instrument-sans'
import '@fontsource/instrument-sans/500.css'
import '@fontsource/instrument-sans/600.css'
import '@fontsource/instrument-sans/700.css'
import './style.css'
import { resolveInitialLocale, setLocale } from './lib/i18n'
import { resolveInitialTheme, setTheme } from './lib/theme'
import { DEFAULT_PROFILE_LIFETIME_MINUTES } from './lib/tryItProfiles'
import type { ProfileStorageMode } from './lib/tryItProfiles'

const mountElement = document.querySelector<HTMLElement>('[data-api-dock-app]')

if (mountElement) {
  const specUrl = mountElement.dataset.specUrl
  const baseUrl = mountElement.dataset.baseUrl
  const csrfToken = mountElement.dataset.csrfToken

  if (!specUrl || !baseUrl || !csrfToken) {
    throw new Error(
      'API Dock requires data-spec-url, data-base-url, and data-csrf-token attributes on its mount element.',
    )
  }

  setLocale(resolveInitialLocale(mountElement.dataset.locale))
  setTheme(resolveInitialTheme(mountElement.dataset.theme))

  const version = mountElement.dataset.version || 'dev'

  // How long a stored credential lives, straight from the server that stores it. Only
  // the persistent mode is announced explicitly; anything else — including a view that
  // predates the attribute — reads as the session mode the package defaults to.
  const profileStorage: ProfileStorageMode =
    mountElement.dataset.profileStorage === 'persistent' ? 'persistent' : 'session'
  const lifetimeMinutes = Number(mountElement.dataset.profileLifetimeMinutes)
  const profileLifetimeMinutes = Number.isFinite(lifetimeMinutes) && lifetimeMinutes > 0
    ? lifetimeMinutes
    : DEFAULT_PROFILE_LIFETIME_MINUTES

  createApp(App, {
    specUrl,
    baseUrl,
    csrfToken,
    version,
    profileStorage,
    profileLifetimeMinutes,
  }).mount(mountElement)
}
