import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { Toaster } from 'react-hot-toast'
import App from './App.jsx'
import { ThemeProvider } from './context/ThemeContext'
import { isPushSupported } from './utils/pushNotifications'
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
// Registered eagerly so a subscription survives page reloads; actual
// permission is requested ONLY from the explicit settings UI.
if (isPushSupported()) {
  window.addEventListener('load', () => {
    const swUrl = new URL('sw.js', document.baseURI).href
    navigator.serviceWorker.register(swUrl).catch(() => {
      // Non-fatal: push simply stays unavailable; attendance keeps working.
    })
  })
}
