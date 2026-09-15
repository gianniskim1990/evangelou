import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { AppProvider } from './AppContext.tsx'
import { MenuProvider } from './MenuContext.tsx'
import { SettingsProvider } from './SettingsContext.tsx'
import { ThemeProvider } from './ThemeContext.tsx'
import { AdminApp } from './admin/AdminApp.tsx'
import { ClubApp } from './club/ClubApp.tsx'

const path = window.location.pathname
const isAdmin = path.startsWith('/admin')
const isClub = path.startsWith('/club')

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider>
      {isAdmin ? (
        <AdminApp />
      ) : isClub ? (
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
  </StrictMode>,
)
