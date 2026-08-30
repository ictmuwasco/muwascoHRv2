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

// ---------------------------------------------------------------------------
// Global client error capture - registers EVERY uncaught error with System
// Monitoring: unhandled promise rejections + window.onerror style errors.
// React render crashes are covered by ErrorBoundary's componentDidCatch;
// failed API calls by the axios client + utils/api.js fetch wrappers.
import('./utils/errorReporting').then(({ reportClientError }) => {
  window.addEventListener('unhandledrejection', (event) => {
    const reason = event?.reason
    reportClientError({
      kind: 'unhandled_rejection',
      message: `Unhandled promise rejection: ${reason?.message || String(reason ?? '')}`.slice(0, 1000),
      stack: typeof reason?.stack === 'string' ? reason.stack : undefined,
      severity: 'MEDIUM',
    })
  })

  window.addEventListener('error', (event) => {
    // Resource-load failures on <script>/<img> have no error object detail
    // beyond the target; still worth a LOW-severity breadcrumb.
    const isResource = !event?.message && event?.target && event.target !== window
    reportClientError({
      kind: 'uncaught',
      message: isResource
        ? `Resource failed to load: ${event.target?.tagName} ${event.target?.src || event.target?.href || ''}`
        : String(event?.message ?? 'Unknown window error'),
      stack: typeof event?.error?.stack === 'string' ? event.error.stack : undefined,
      severity: isResource ? 'LOW' : 'HIGH',
    })
  }, true) // capture phase so resource errors on media/script tags are caught too
}).catch(() => { /* reporter unavailable - nothing else we can do */ })

