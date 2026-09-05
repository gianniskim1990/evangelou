import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { AppProvider } from './AppContext.tsx'
import { MenuProvider } from './MenuContext.tsx'
import { SettingsProvider } from './SettingsContext.tsx'
import { AdminApp } from './admin/AdminApp.tsx'

const isAdmin = window.location.pathname.startsWith('/admin')

createRoot(document.getElementById('root')!).render(
  <StrictMode>
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
  </StrictMode>,
)
