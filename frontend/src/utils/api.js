import { reportClientError, getRequestId, setRequestId } from './errorReporting';
// Single source of truth for the API base URL (VITE_API_URL at build time,
// defaulting to '/api' — see .env.example and src/config/api.ts). Importing
// the shared constant means a production deployment repoints the entire SPA
// — axios client, fetch wrapper, error collector and asset URLs — with one
// environment variable instead of this module's hardcoded '/api'.
import { API_BASE_URL } from '../config/api';

const API_URL = API_BASE_URL;

/**
 * Default request timeout (ms). Prevents hung GPS lookups or a stalled
 * network connection from leaving buttons spinning forever.
 */
const DEFAULT_TIMEOUT_MS = 30000;

// ---------------------------------------------------------------------------
// Passive round-trip timing observer (Phase 2 instrumentation)
//
// Every response that reaches this wrapper is measured with a monotonic clock
// and the aggregate (count, mean, p95, max) is exposed for diagnostics. This
// NEVER reads request bodies, headers or response payloads — only the elapsed
// wall-time between send and first-byte/body-read is recorded, keyed by the
// endpoint path so the Phase 2 report can attribute frontend round-trips to
// the same endpoints the backend breakdown covers.
//
// Metrics are intentionally kept in-memory (no network calls, no new API
// usage) and are safe to leave enabled: no PII, no query strings, no ids.
// ---------------------------------------------------------------------------
const PERF_ENABLED =
  typeof performance !== 'undefined' &&
  typeof performance.now === 'function';

/** @type {Map<string, number[]>} endpoint path -> round-trip ms samples */
const perfSamples = new Map();

function samplePerf(endpointPath, startedAt) {
  if (!PERF_ENABLED) return
  try {
    const elapsed = performance.now() - startedAt
    if (elapsed < 0) return
    const samples = perfSamples.get(endpointPath) || []
    samples.push(elapsed)
    // Cap samples per endpoint so a long-lived tab never grows unbounded.
    if (samples.length > 500) samples.shift()
    perfSamples.set(endpointPath, samples)
  } catch {
    /* observability must never break the request path */
  }
}

/** p95 of a sorted copy; null when empty. */
function percentile(sortedSamples, p) {
  if (sortedSamples.length === 0) return null
  const idx = Math.min(sortedSamples.length - 1, Math.max(0, Math.ceil((p / 100) * sortedSamples.length) - 1))
  return Math.round(sortedSamples[idx] * 10) / 10
}

/** Aggregate round-trip timing report for the Phase 2 deliverables. */
export function getPerfTimingReport() {
  const out = {}
  perfSamples.forEach((samples, path) => {
    const sorted = [...samples].sort((a, b) => a - b)
    const sum = sorted.reduce((acc, v) => acc + v, 0)
    out[path] = {
      sample_count: sorted.length,
      mean_ms: Math.round((sum / sorted.length) * 10) / 10,
      p95_ms: percentile(sorted, 95),
      max_ms: Math.round(sorted[sorted.length - 1] * 10) / 10,
    }
  })
  return out
}
/**
 * Endpoints that must never trigger the automatic refresh-retry loop
 * (otherwise a failed login/logout would be silently replayed).
 */
const NON_RETRIABLE_PATHS = ['/auth/login', '/auth/logout', '/auth/refresh'];

/**
 * Silent, single-flight session renewal.
 *
 * The backend keeps the employee signed in with an httpOnly `access_token`
 * cookie that expires after one hour (while the PHP session lives for two).
 * When any API call answers 401 we renew the cookie once via /auth/refresh
 * and then replay the original request, so an employee who leaves the tab
 * idle is never logged out mid-shift. Concurrent 401s share one refresh.
 */
let refreshPromise = null;

const refreshSession = () => {
  if (!refreshPromise) {
    refreshPromise = (async () => {
      // Bounded: a hung renewal must never stall the replay chain (or the
      // caller's spinner) indefinitely.
      const refreshController = new AbortController();
      const refreshTimer = setTimeout(() => refreshController.abort(), 15000);
      const response = await fetch(`${API_URL}/auth/refresh`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        signal: refreshController.signal,
      }).finally(() => clearTimeout(refreshTimer));
      if (!response.ok) {
        throw new Error(`Token refresh failed (${response.status})`);
      }
      return true;
    })().finally(() => {
      refreshPromise = null;
    });
  }
  return refreshPromise;
};

export const apiFetch = async (endpoint, options = {}) => {
  const {
    method = 'GET',
    body,
    headers: customHeaders,
    responseType,
    params,
    timeout = DEFAULT_TIMEOUT_MS,
    credentials = 'include',
    signal: callerSignal,
    ...restOptions
  } = options;

  // If the body is FormData, let the browser set the Content-Type (including boundary).
  // Strip any manually-supplied Content-Type to avoid breaking the boundary.
  const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;

  // Serialize params into a query string (axios-style behavior).
  // This is required because the native fetch() API does not understand a
  // `params` option — without this, query parameters are silently dropped.
  let url = endpoint;
  if (params && typeof params === 'object') {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
      if (value === null || value === undefined) continue;
      if (Array.isArray(value)) {
        value.forEach((v) => query.append(key, String(v)));
      } else {
        query.append(key, String(value));
      }
    }
    const queryString = query.toString();
    if (queryString) {
      url += (url.includes('?') ? '&' : '?') + queryString;
    }
  }

  const headers = {
    ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
    ...(customHeaders || {}),
  };
  if (isFormData && headers['Content-Type']) {
    delete headers['Content-Type'];
  }

  const isRetriable = !NON_RETRIABLE_PATHS.some((path) => endpoint.startsWith(path));

  // One request attempt, wrapped in an abort-based timeout so a hung GPS or
  // network call can never block the UI indefinitely. The timeout ALWAYS
  // applies — even when the caller passes its own signal (previously the
  // timer was skipped for caller signals, leaving long AI/chat requests
  // without any client-side cap and the UI spinning forever). A caller
  // signal is CHAINED: caller abort => this request aborts too.
  const send = () => {
    const controller = new AbortController();
    let onCallerAbort = null;
    if (callerSignal) {
      if (callerSignal.aborted) {
        controller.abort();
      } else {
        onCallerAbort = () => controller.abort();
        callerSignal.addEventListener('abort', onCallerAbort, { once: true });
      }
    }
    const timer = setTimeout(() => controller.abort(), timeout);
    const perfStart = performance.now();

    // X-Request-ID MUST ride inside the headers object — passing it as a
    // bare fetch() option is silently ignored, which previously stripped
    // the correlation id from every fetch-wrapper request (the axios client
    // and the error collector were unaffected). Minted per attempt so a
    // refresh-replay keeps sharing the same traceable request id.
    return fetch(`${API_URL}${url}`, {
      method,
      headers: { ...headers, 'X-Request-ID': getRequestId() },
      body: body !== undefined ? (isFormData ? body : JSON.stringify(body)) : undefined,
      credentials,
      signal: controller.signal,
      ...restOptions,
    }).then((response) => {
      // Passive round-trip timing (Phase 2): keyed by the endpoint path
      // only — never body, headers, query string or ids.
      samplePerf(url.split('?')[0], perfStart);
      return response;
    }).finally(() => {
      clearTimeout(timer);
      if (onCallerAbort && callerSignal) {
        callerSignal.removeEventListener('abort', onCallerAbort);
      }
    });
  };

  const toTimeoutError = () => {
    const error = new Error(
      `Request timed out after ${Math.round(timeout / 1000)}s. Check your connection and try again.`
    );
    error.isTimeout = true;
    error.response = { data: {}, status: 0, statusText: 'Timeout' };
    return error;
  };

  let response;
  try {
    response = await send();
  } catch (err) {
    if (err && err.name === 'AbortError') throw toTimeoutError();
    // Network failure - the request never reached the server. Mirrors the
    // axios client so every page benefits from System Monitoring reports.
    reportClientError({
      kind: 'network',
      message: `Network failure calling ${method.toUpperCase()} ${url}: ${err?.message || String(err)}`,
      endpoint: url,
      severity: 'HIGH',
    });
    throw err;
  }

  // Adopt the server-authoritative correlation id for future calls/reports.
  const requestIdHeader = response.headers?.get?.('x-request-id');
  if (requestIdHeader) setRequestId(requestIdHeader);

  // Expired access-token cookie -> renew once, then replay the request.
  if (response.status === 401 && isRetriable) {
    try {
      await refreshSession();
      response = await send();
    } catch (err) {
      if (err && err.name === 'AbortError') throw toTimeoutError();
      const error = new Error('Your session has expired. Please sign in again.');
      error.isAuthError = true;
      error.response = { data: {}, status: 401, statusText: 'Unauthorized' };
      throw error;
    }
  }

  let data;
  if (responseType === 'blob') {
    data = await response.blob();
  } else {
    data = await response.json().catch(() => ({}));
  }

  if (!response.ok) {
    // Register unexpected SERVER failures with System Monitoring - mirrors
    // the axios client. 4xx is expected app behaviour (§34); only 5xx and
    // unreachable networks get reported. The collector never reports itself.
    if (response.status >= 500 && !url.includes('/system/client-errors')) {
      reportClientError({
        kind: 'api',
        message: `API ${response.status} on ${method.toUpperCase()} ${url}: ${data?.message || 'Internal Server Error'}`,
        stack: data?.error?.details ?? undefined,
        endpoint: url,
        status_code: response.status,
        severity: 'HIGH',
        extra: {
          request_id: data?.error?.request_id ?? undefined,
        },
      });
    }
    const message = data.error || data.message || `Request failed with status ${response.status}`;
    const error = new Error(message);
    error.response = { data, status: response.status, statusText: response.statusText };
    throw error;
  }

  // Return an axios-like response object so callers can use response.data
  return {
    data,
    status: response.status,
    statusText: response.statusText,
    headers: response.headers,
    config: {},
  };
};

export const apiGet = (endpoint, config) => apiFetch(endpoint, { ...config });
export const apiPost = (endpoint, data, config) => apiFetch(endpoint, { method: 'POST', body: data, ...config });
export const apiPut = (endpoint, data, config) => apiFetch(endpoint, { method: 'PUT', body: data, ...config });
export const apiDelete = (endpoint, config) => apiFetch(endpoint, { method: 'DELETE', ...config });

export default {
  get: apiGet,
  post: apiPost,
  put: apiPut,
  delete: apiDelete,
};
