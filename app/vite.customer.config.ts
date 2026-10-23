import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'

// STAGE-01: separate build for the REAL customer app served by the WordPress
// plugin at /club-member/ (never by Vercel). Hashed assets + manifest; no
// public/ copy; relative base so assets resolve under the plugin's
// customer-app/ URL. The design preview flag is irrelevant here: the app
// imports the approved customer components directly.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  base: './',
  publicDir: false,
  build: {
    outDir: 'dist-customer',
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    assetsInlineLimit: 0,
    rollupOptions: {
      input: { customer: fileURLToPath(new URL('./customer.html', import.meta.url)) },
    },
  },
})
