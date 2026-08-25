import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { Toaster } from 'react-hot-toast'
import App from './App.jsx'
import { ThemeProvider } from './context/ThemeContext'
import { isPushSupported, ensureServiceWorkerRegistered } from './utils/pushNotifications'
import './index.css'

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter future={{ v7_relativeSplatPath: true, v7_startTransition: true }}>
      <ThemeProvider>
        <App />
        <Toaster position="top-right" />
      </ThemeProvider>
    </BrowserRouter>
  </React.StrictMode>,
)

// Service worker registration for Web Push attendance reminders.
// Uses the resilient multi-location probe (validates JS content-type,
// cache-busts) and fails silently here - errors are surfaced in the
// Settings > Notifications UI when the employee explicitly enables.
if (isPushSupported()) {
  window.addEventListener('load', () => {
    ensureServiceWorkerRegistered().catch(() => { /* surfaced in Settings */ })
  })
}
