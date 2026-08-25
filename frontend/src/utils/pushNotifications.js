/**
 * Web Push client utilities (Push API + Notifications API).
 *
 * Handles the full employee-facing lifecycle:
 *   capability detection -> permission request (never re-prompting
 *   after denial) -> service-worker registration -> push subscription
 *   -> backend registration keyed to the authenticated session.
 *
 * The API service module is loaded lazily so these utilities stay a
 * dependency-free, purely-testable unit (no network module is pulled
 * into unit-test graphs).
 */

const DENIED_FLAG = 'hr_push_permission_denied'
const SW_PATH = 'sw.js' // resolved against document.baseURI at runtime

// Lazily-resolved API surface (avoids a static import cycle/graph cost).
let apiPromise = null
function api() {
  if (!apiPromise) {
    apiPromise = import('../api/services/notificationService')
      .then((m) => m.notificationService)
  }
  return apiPromise
}

/**
 * @typedef {'granted'|'denied'|'default'|'unsupported'} PermissionState
 */


export function isPushSupported() {
  return (
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window
  )
}

export function getPermissionState() {
  if (!isPushSupported()) return 'unsupported'
  return Notification.permission
}

/** True when the browser was permanently denied before - never re-prompt. */
export function wasPermissionDenied() {
  try {
    return localStorage.getItem(DENIED_FLAG) === '1'
  } catch {
    return false
  }
}

/**
 * Ask the user for notification permission exactly once.
 * Returns the resulting state without throwing.
 * @returns {Promise<PermissionState>}
 */
export async function ensurePermission() {
  if (!isPushSupported()) return 'unsupported'
  if (Notification.permission !== 'default') return Notification.permission

  try {
    const result = await Notification.requestPermission()
    if (result === 'denied') {
      try { localStorage.setItem(DENIED_FLAG, '1') } catch { /* storage unavailable */ }
    }
    return result
  } catch {
    return 'denied'
  }
}

/** Standard VAPID key conversion for subscribe(). */
export function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = window.atob(base64)
  const output = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i += 1) {
    output[i] = raw.charCodeAt(i)
  }
  return output
}

async function registerServiceWorker() {
  const swUrl = new URL(SW_PATH, document.baseURI).href
  return navigator.serviceWorker.register(swUrl)
}

/** Current subscription endpoint, if this browser is subscribed. */
export async function getExistingEndpoint() {
  if (!isPushSupported()) return null
  try {
    const registration = await navigator.serviceWorker.getRegistration()
    if (!registration) return null
    const sub = await registration.pushManager.getSubscription()
    return sub ? sub.endpoint : null
  } catch {
    return null
  }
}

/**
 * @typedef {{ok: boolean, message: string}} SubscribeOutcome
 */

/**
 * Full enable flow: permission -> SW -> pushManager.subscribe ->
 * POST to backend. Outcome object is the friendly contract for UI.
 * @returns {Promise<SubscribeOutcome>}
 */
export async function enablePushForThisDevice(deviceName) {
  if (!isPushSupported()) {
    return { ok: false, message: 'This browser does not support push notifications.' }
  }

  const permission = await ensurePermission()
  if (permission === 'denied') {
    return {
      ok: false,
      message: 'Notifications are blocked. Enable them in your browser site settings.',
    }
  }

  let vapidKey = ''
  try {
    const service = await api()
    const response = await service.getVapidPublicKey()
    vapidKey = response?.data?.public_key ?? ''
  } catch {
    return { ok: false, message: 'Server push configuration unavailable.' }
  }
  if (!vapidKey) {
    return { ok: false, message: 'Web Push is not configured on the server.' }
  }

  const registration = await registerServiceWorker()

  // Reuse an existing subscription when present; subscribing again with a
  // different applicationServerKey throws on some browsers.
  let subscription = await registration.pushManager.getSubscription()
  if (!subscription) {
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(vapidKey),
    })
  }

  const json = subscription.toJSON()
  const service = await api()
  const response = await service.subscribe({
    endpoint: json.endpoint,
    keys: {
      p256dh: json.keys?.p256dh ?? '',
      auth: json.keys?.auth ?? '',
    },
    device_name: deviceName ?? guessDeviceName(),
    platform: navigator.platform || undefined,
  })

  return {
    ok: response.success,
    message: response.message || (response.success ? 'Notifications enabled.' : 'Could not save the subscription.'),
  }
}

/** Disable for THIS browser only (other devices stay subscribed). */
export async function disablePushForThisDevice() {
  if (!isPushSupported()) return { ok: false, message: 'Push not supported here.' }

  const registration = await navigator.serviceWorker.getRegistration()
  const subscription = await registration?.pushManager.getSubscription()
  if (!subscription) return { ok: true, message: 'Already disabled on this device.' }

  const endpoint = subscription.endpoint
  await subscription.unsubscribe()

  const service = await api()
  const response = await service.unsubscribe(endpoint)
  return { ok: response.success, message: response.message || 'Notifications disabled.' }
}

function guessDeviceName() {
  const ua = navigator.userAgent
  if (/android/i.test(ua)) return 'Android phone'
  if (/iphone|ipad|ipod/i.test(ua)) return 'iPhone/iPad'
  if (/windows/i.test(ua)) return 'Windows PC'
  if (/macintosh/i.test(ua)) return 'Mac'
  if (/linux/i.test(ua)) return 'Linux PC'
  return 'Browser'
}

