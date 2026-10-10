import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'

// Separate build for the REAL staff app served by the WordPress plugin at
// /club-admin/ (never by Vercel). Hashed assets + manifest; no public/ copy;
// relative base so assets resolve under the plugin's staff-app/ URL.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  base: './',
  publicDir: false,
  build: {
    outDir: 'dist-staff',
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    assetsInlineLimit: 0,
    rollupOptions: {
      input: { staff: fileURLToPath(new URL('./staff.html', import.meta.url)) },
    },
  },
})
