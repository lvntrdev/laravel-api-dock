import { fileURLToPath, URL } from 'node:url'

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  base: './',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  build: {
    outDir: 'resources/dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: fileURLToPath(new URL('./resources/js/main.ts', import.meta.url)),
      output: {
        entryFileNames: 'api-dock.js',
        chunkFileNames: 'api-dock-[name].js',
        assetFileNames: (assetInfo) =>
          assetInfo.names?.some((name) => name.endsWith('.css'))
            ? 'api-dock.css'
            : 'api-dock-[name][extname]',
        inlineDynamicImports: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
  },
})
