import { StrictMode, Suspense, lazy } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { AppProvider } from './AppContext.tsx'
import { MenuProvider } from './MenuContext.tsx'
import { SettingsProvider } from './SettingsContext.tsx'
import { ThemeProvider } from './ThemeContext.tsx'
import { AdminApp } from './admin/AdminApp.tsx'
import { ClubApp } from './club/ClubApp.tsx'
import { resolveEntry } from './entryRoute.ts'

// UI-01 design preview: only built when __EVC_CLUB_PREVIEW__ is true (never
// in Vercel production builds, see vite.config.ts). With it false this
// lazy import is dead code and the preview chunk is not emitted at all.
const ClubPreviewApp = __EVC_CLUB_PREVIEW__
  ? lazy(() => import('./preview/ClubPreviewApp.tsx').then((m) => ({ default: m.ClubPreviewApp })))
  : null

const entry = resolveEntry(window.location.pathname, __EVC_CLUB_PREVIEW__)

// The preview is light-only: undo index.html's early dark-class script BEFORE
// the first render, so nothing paints (or transitions) from dark tokens.
if (entry === 'club-preview') document.documentElement.classList.remove('dark')

createRoot(document.getElementById('root')!).render(
  entry === 'club-preview' && ClubPreviewApp ? (
    // Light-only brand preview: rendered outside ThemeProvider on purpose.
    <StrictMode>
      <Suspense fallback={null}>
        <ClubPreviewApp />
      </Suspense>
    </StrictMode>
  ) : (
  <StrictMode>
    <ThemeProvider>
      {entry === 'admin' ? (
        <AdminApp />
      ) : entry === 'club' ? (
        <ClubApp />
      ) : (
        <MenuProvider>
          <SettingsProvider>
            <AppProvider>
              <App />
            </AppProvider>
          </SettingsProvider>
        </MenuProvider>
      )}
    </ThemeProvider>
  </StrictMode>
  ),
)
