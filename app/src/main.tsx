import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { AppProvider } from './AppContext.tsx'
import { MenuProvider } from './MenuContext.tsx'
import { SettingsProvider } from './SettingsContext.tsx'
import { ThemeProvider } from './ThemeContext.tsx'
import { AdminApp } from './admin/AdminApp.tsx'

const isAdmin = window.location.pathname.startsWith('/admin')

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider>
      {isAdmin ? (
        <AdminApp />
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
