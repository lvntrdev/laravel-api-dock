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

  createApp(App, { specUrl, baseUrl, csrfToken }).mount(mountElement)
}
