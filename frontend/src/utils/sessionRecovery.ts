/**
 * sessionRecovery - one place for "the session ended, what do we do?".
 *
 * Both HTTP clients (utils/api.js and api/client.ts) hit 401s, and before this
 * module each had its own ad-hoc recovery: drop localStorage, hard-navigate to
 * /login. That produced a bare 401 in the console, a full page reload, and the
 * employee losing whatever page they were on.
 *
 * Now a dead session:
 *   - remembers where the user was, so login can return them there;
 *   - says so in plain language instead of dumping a stack trace;
 *   - redirects at most once per page load (no redirect loops).
 *
 * A 401 is NOT reported to System Monitoring: an expired token is ordinary
 * operation, not a fault, and logging it as one buries real incidents.
 */

import { API_BASE_URL } from '../config/api';

/**
 * Renew the access token from the httpOnly refresh_token cookie.
 *
 * Single-flight on purpose: a page load can fire several requests at once
 * (dashboard widgets, permission poll) and they will all 401 together. Without
 * this guard each would start its own renewal, and because the backend ROTATES
 * the refresh token, the later ones would present an already-revoked token and
 * fail - logging the user out even though renewal had just succeeded.
 *
 * Both HTTP clients (utils/api.js and api/client.ts) call this, so a single
 * in-flight promise is shared across the whole app.
 */
let renewalPromise: Promise<void> | null = null;

export const renewSession = (): Promise<void> => {
  if (!renewalPromise) {
    const controller = new AbortController();
    // Bounded: a hung renewal must not stall every queued request forever.
    const timer = setTimeout(() => controller.abort(), 15000);

    renewalPromise = fetch(`${API_BASE_URL}/auth/refresh`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      signal: controller.signal,
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error(`Session renewal failed (${response.status})`);
        }
      })
      .finally(() => {
        clearTimeout(timer);
        renewalPromise = null;
      });
  }
  return renewalPromise;
};

const RETURN_TO_KEY = 'auth:returnTo';

/** Session-scoped (not localStorage) so it cannot resurrect a stale tab weeks later. */
const safeSession = (): Storage | null => {
  try {
    return window.sessionStorage;
  } catch {
    return null;
  }
};

/**
 * Remember the page the user was on so login can send them back to it.
 * Never stores /login itself, or the user would bounce in a loop.
 */
export const rememberReturnTo = (pathname?: string): void => {
  const store = safeSession();
  if (!store) return;
  const path = pathname ?? (typeof window !== 'undefined' ? window.location.pathname : '/');
  if (!path || path.startsWith('/login')) return;
  try {
    store.setItem(RETURN_TO_KEY, `${path}${window.location.search || ''}`);
  } catch {
    /* storage full or blocked - a lost return path is not worth breaking over */
  }
};

/** Read and clear the remembered destination (single use). */
export const consumeReturnTo = (): string | null => {
  const store = safeSession();
  if (!store) return null;
  try {
    const value = store.getItem(RETURN_TO_KEY);
    store.removeItem(RETURN_TO_KEY);
    return value || null;
  } catch {
    return null;
  }
};

let redirecting = false;

/**
 * Handle a definitively dead session: clear the cached profile, show a human
 * message, and send the user to the sign-in screen exactly once.
 *
 * @param reason Short, user-facing explanation.
 */
export const handleSessionExpired = (
  reason = 'Your session has ended. Please sign in again.',
): void => {
  if (typeof window === 'undefined') return;
  if (window.location.pathname.startsWith('/login')) return;
  if (redirecting) return;
  redirecting = true;

  try {
    localStorage.removeItem('user');
  } catch {
    /* storage may be blocked; the redirect still matters */
  }

  rememberReturnTo();

  // Carry the message through the redirect. The login screen reads it once and
  // clears it, so it never reappears on a later visit.
  const target = `/login?reason=${encodeURIComponent(reason)}`;
  window.location.assign(target);
};

/** Test/reset hook - lets the guard be exercised more than once in a session. */
export const __resetSessionRecovery = (): void => {
  redirecting = false;
};
