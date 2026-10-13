import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'
import { clubPreviewEnabled } from './src/preview/buildFlag.ts'

// UI-01: the /club-preview design preview is a development-only visual
// prototype. It is compiled in for local dev and Vercel PREVIEW deployments,
// and compiled OUT (dead code, no chunk) for Vercel production builds or when
// EVC_CLUB_PREVIEW=off is set explicitly.

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  define: {
    __EVC_CLUB_PREVIEW__: JSON.stringify(clubPreviewEnabled(process.env)),
  },
})
