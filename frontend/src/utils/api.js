import { reportClientError, getRequestId, setRequestId } from './errorReporting';

const API_URL = '/api';

/**
 * Default request timeout (ms). Prevents hung GPS lookups or a stalled
 * network connection from leaving buttons spinning forever.
 */
const DEFAULT_TIMEOUT_MS = 30000;

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
      const response = await fetch(`${API_URL}/auth/refresh`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
      });
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
  // network call can never block the UI indefinitely.
  const send = () => {
    const controller = callerSignal ? null : new AbortController();
    const signal = callerSignal || controller.signal;
    const timer = controller ? setTimeout(() => controller.abort(), timeout) : null;

    return fetch(`${API_URL}${url}`, {
      method,
      headers,
      body: body !== undefined ? (isFormData ? body : JSON.stringify(body)) : undefined,
      credentials,
      'X-Request-ID': getRequestId(),
      signal,
      ...restOptions,
    }).finally(() => {
      if (timer) clearTimeout(timer);
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
