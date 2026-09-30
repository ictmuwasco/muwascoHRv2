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

const DENIED_FLAG = 'hr_push_permission_denied';
const SW_PATH = 'sw.js'; // resolved against document.baseURI at runtime

/**
 * Local record of which VAPID key created the current browser subscription.
 *
 * A PushSubscription's `applicationServerKey` is IMMUTABLE: you cannot re-key an
 * existing subscription, only throw it away and subscribe again. So if the
 * server's VAPID keys are ever regenerated, a subscription left in place stays
 * bound to the OLD key and every single push is rejected by the push service
 * with 401/403. Nothing server-side can diagnose that, because the stored
 * endpoint looks perfectly valid.
 *
 * Recording the key locally is what lets us detect the mismatch and re-bind,
 * instead of leaving the employee permanently "enabled" and silently deaf.
 */
const VAPID_KEY_RECORD = 'hr_push_vapid_key';

// Lazily-resolved API surface (avoids a static import cycle/graph cost).
let apiPromise = null;
function api() {
  if (!apiPromise) {
    apiPromise = import('../api/services/notificationService').then((m) => m.notificationService);
  }
  return apiPromise;
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
  );
}

export function getPermissionState() {
  if (!isPushSupported()) return 'unsupported';
  return Notification.permission;
}

/** True when the browser was permanently denied before - never re-prompt. */
export function wasPermissionDenied() {
  try {
    return localStorage.getItem(DENIED_FLAG) === '1';
  } catch {
    return false;
  }
}

/**
 * Ask the user for notification permission exactly once.
 * Returns the resulting state without throwing.
 * @returns {Promise<PermissionState>}
 */
export async function ensurePermission() {
  if (!isPushSupported()) return 'unsupported';
  if (Notification.permission !== 'default') return Notification.permission;

  try {
    const result = await Notification.requestPermission();
    if (result === 'denied') {
      try {
        localStorage.setItem(DENIED_FLAG, '1');
      } catch {
        /* storage unavailable */
      }
    }
    return result;
  } catch {
    return 'denied';
  }
}

/** Standard VAPID key conversion for subscribe(). */
export function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i += 1) {
    output[i] = raw.charCodeAt(i);
  }
  return output;
}

/**
 * Locate and register the service worker robustly.
 *
 * The worker may be hosted at the domain root, under a sub-path
 * (/hrdemo/), or inside a backend/public deployment folder. We probe
 * the known candidates, VERIFY the response is actually JavaScript
 * (an SPA fallback answering text/html poisons registration), and
 * cache-bust so a previously-cached bad response can't stick.
 *
 * @returns {Promise<ServiceWorkerRegistration>}
 */
async function registerServiceWorker() {
  const bases = new Set();

  const add = (u) => {
    try {
      const abs = new URL(u, document.baseURI).href;
      if (abs.startsWith(location.origin)) bases.add(abs);
    } catch {
      /* ignore malformed */
    }
  };

  add(new URL(SW_PATH, document.baseURI).href);
  add(window.location.origin + '/hrdemo/' + SW_PATH);
  add(window.location.origin + '/' + SW_PATH);
  add(new URL('backend/public/' + SW_PATH, document.baseURI).href);
  add(new URL('../backend/public/' + SW_PATH, document.baseURI).href);

  let lastError = null;

  for (const url of bases) {
    try {
      const res = await fetch(url, { cache: 'no-store' });
      if (!res.ok) {
        lastError = new Error(`Service worker ${res.status} at ${url}`);
        continue;
      }
      const contentType = (res.headers.get('content-type') || '').toLowerCase();
      const text = await res.text();
      const looksLikeJs =
        contentType.includes('javascript') ||
        /^\s*(\/\*|\/\/|['"`]|import\b|const\b|let\b|var\b|self\b)/.test(text);

      if (!text || !looksLikeJs) {
        lastError = new Error(`Service worker at ${url} is not JavaScript (${contentType})`);
        continue;
      }

      // Cache-bust so a previously poisoned copy can't be reused.
      const target = url + '?v=' + encodeURIComponent(SW_VERSION);
      return navigator.serviceWorker.register(target);
    } catch (err) {
      lastError = err;
    }
  }

  throw lastError ?? new Error('No reachable service worker location');
}

/** SW file version - bump to force clients to refetch after changes. */
const SW_VERSION = '1';

/**
 * Memoised entry point used by both the app bootstrap and the
 * Settings UI so we never register two different cache-busted URLs.
 */
let registrationPromise = null;

export function ensureServiceWorkerRegistered() {
  if (!registrationPromise) {
    registrationPromise = registerServiceWorker().catch((err) => {
      registrationPromise = null; // allow retry on next user action
      throw err;
    });
  }
  return registrationPromise;
}

/** Current subscription endpoint, if this browser is subscribed. */
export async function getExistingEndpoint() {
  if (!isPushSupported()) return null;
  try {
    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration) return null;
    const sub = await registration.pushManager.getSubscription();
    return sub ? sub.endpoint : null;
  } catch {
    return null;
  }
}

/** The VAPID public key that created the current subscription, if recorded. */
export function getRecordedVapidKey() {
  try {
    return localStorage.getItem(VAPID_KEY_RECORD);
  } catch {
    return null;
  }
}

function recordVapidKey(key) {
  try {
    localStorage.setItem(VAPID_KEY_RECORD, key);
  } catch {
    /* storage unavailable - detection degrades, but nothing breaks */
  }
}

function forgetVapidKey() {
  try {
    localStorage.removeItem(VAPID_KEY_RECORD);
  } catch {
    /* storage unavailable */
  }
}

/**
 * The current browser subscription, but ONLY if it was created with the VAPID
 * key the server is currently using.
 *
 * Returns the subscription when it is genuinely usable, or null when there is
 * none OR when the recorded key disagrees with the server's - in which case the
 * caller must re-subscribe rather than reuse a subscription the push service
 * will reject.
 *
 * @param {string} currentVapidKey
 * @returns {Promise<PushSubscription|null>}
 */
export async function getUsableSubscription(currentVapidKey) {
  if (!isPushSupported()) return null;
  let subscription = null;
  try {
    const registration = await navigator.serviceWorker.getRegistration();
    subscription = (await registration?.pushManager.getSubscription()) ?? null;
  } catch {
    return null;
  }
  if (!subscription) return null;

  const recorded = getRecordedVapidKey();
  if (recorded === null) {
    // No record: a subscription predates this feature, or storage was cleared.
    // Optimistically adopt the current key so the next rotation is detectable.
    recordVapidKey(currentVapidKey);
    return subscription;
  }
  if (recorded !== currentVapidKey) return null; // stale binding - re-subscribe
  return subscription;
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
    return { ok: false, message: 'This browser does not support push notifications.' };
  }

  const permission = await ensurePermission();
  if (permission === 'denied') {
    return {
      ok: false,
      message: 'Notifications are blocked. Enable them in your browser site settings.',
    };
  }

  let vapidKey = '';
  try {
    const service = await api();
    const response = await service.getVapidPublicKey();
    vapidKey = response?.data?.public_key ?? '';
  } catch {
    return { ok: false, message: 'Server push configuration unavailable.' };
  }
  if (!vapidKey) {
    return { ok: false, message: 'Web Push is not configured on the server.' };
  }

  const registration = await ensureServiceWorkerRegistered();

  // Reuse an existing subscription ONLY when it was created with the key the
  // server is using right now. A subscription bound to a rotated-out key can
  // never be repaired in place (applicationServerKey is immutable), so it is
  // discarded and replaced rather than silently reused into permanent 401/403s.
  let subscription = await getUsableSubscription(vapidKey);
  let rebound = false;
  if (!subscription) {
    // Clear any stale subscription first; subscribe() throws if one exists on
    // some browsers when the applicationServerKey differs.
    try {
      const registration2 = await navigator.serviceWorker.getRegistration();
      const stale = await registration2?.pushManager.getSubscription();
      if (stale) await stale.unsubscribe();
      rebound = getRecordedVapidKey() !== null;
    } catch {
      /* best effort */
    }

    try {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKey),
      });
    } catch (e) {
      return { ok: false, message: 'This browser refused to create a push subscription.' };
    }
    recordVapidKey(vapidKey);
  }

  const json = subscription.toJSON();
  const service = await api();
  let response;
  try {
    response = await service.subscribe({
      endpoint: json.endpoint,
      keys: {
        p256dh: json.keys?.p256dh ?? '',
        auth: json.keys?.auth ?? '',
      },
      device_name: deviceName ?? guessDeviceName(),
      platform: navigator.platform || undefined,
    });
  } catch (e) {
    return {
      ok: false,
      message: 'The browser is ready, but the server did not accept the subscription.',
    };
  }

  if (!response.success) {
    return {
      ok: false,
      message: response.message || 'The server did not accept the subscription.',
    };
  }

  return {
    ok: true,
    // Say WHY it worked when it had to re-bind, so the employee is not left
    // wondering why the button was already "on".
    message: rebound
      ? 'Notifications enabled. The previous subscription used an outdated server key and was replaced.'
      : response.message || 'Notifications enabled.',
    rebound,
  };
}

/** Disable for THIS browser only (other devices stay subscribed). */
export async function disablePushForThisDevice() {
  if (!isPushSupported()) return { ok: false, message: 'Push not supported here.' };

  const registration = await navigator.serviceWorker.getRegistration();
  const subscription = await registration?.pushManager.getSubscription();
  if (!subscription) {
    // Nothing on this browser, but a stale server row may still exist from a
    // previous session. Forget the local binding so the UI stops claiming the
    // device is enabled.
    forgetVapidKey();
    return { ok: true, message: 'Already disabled on this device.' };
  }

  const endpoint = subscription.endpoint;
  await subscription.unsubscribe();
  forgetVapidKey();

  // Tell the server to revoke it too. A failure here leaves an orphan row that
  // would keep receiving pushes the browser can no longer display, so it is
  // reported rather than swallowed.
  const service = await api();
  try {
    const response = await service.unsubscribe(endpoint);
    return {
      ok: response.success,
      message: response.success
        ? response.message || 'Notifications disabled.'
        : 'Disabled on this device, but the server could not remove the registration.',
    };
  } catch (e) {
    return {
      ok: false,
      message: 'Disabled on this device, but the server could not be reached to remove the registration.',
    };
  }
}

function guessDeviceName() {
  const ua = navigator.userAgent;
  if (/android/i.test(ua)) return 'Android phone';
  if (/iphone|ipad|ipod/i.test(ua)) return 'iPhone/iPad';
  if (/windows/i.test(ua)) return 'Windows PC';
  if (/macintosh/i.test(ua)) return 'Mac';
  if (/linux/i.test(ua)) return 'Linux PC';
  return 'Browser';
}
