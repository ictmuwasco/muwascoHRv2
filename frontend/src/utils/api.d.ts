/**
 * Type declarations for the fetch-based API wrapper (src/utils/api.js).
 *
 * NOTE: this is NOT axios — it is a thin fetch() wrapper that adds the silent
 * session-refresh replay, per-request timeout, axios-style `params`
 * serialization and the X-Request-ID correlation header. It deliberately
 * returns an axios-like `{ data, status, statusText, headers }` envelope so
 * call sites can keep reading `response.data`.
 */

/** Options accepted by apiFetch and the get/post/put/delete helpers. */
export interface ApiRequestConfig {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  headers?: Record<string, string>;
  /** 'blob' for file downloads; JSON is otherwise assumed. */
  responseType?: 'json' | 'blob';
  /** Serialized into a query string (axios-style `params`). */
  params?: Record<string, unknown>;
  /** Request timeout in ms (default 30s) — aborts with an isTimeout error. */
  timeout?: number;
  credentials?: 'include' | 'omit' | 'same-origin';
  /** Caller-provided abort signal; replaces the built-in timeout. */
  signal?: AbortSignal;
  [key: string]: unknown;
}

/** Axios-like response envelope so call sites keep reading `.data`. */
export interface ApiEnvelope<T = any> {
  data: T;
  status: number;
  statusText: string;
  headers: Headers;
  config: Record<string, never>;
}

export declare function apiFetch<T = any>(
  endpoint: string,
  options?: ApiRequestConfig
): Promise<ApiEnvelope<T>>;

export declare const apiGet: <T = any>(
  endpoint: string,
  config?: ApiRequestConfig
) => Promise<ApiEnvelope<T>>;

export declare const apiPost: <T = any>(
  endpoint: string,
  data?: unknown,
  config?: ApiRequestConfig
) => Promise<ApiEnvelope<T>>;

export declare const apiPut: <T = any>(
  endpoint: string,
  data?: unknown,
  config?: ApiRequestConfig
) => Promise<ApiEnvelope<T>>;

export declare const apiDelete: <T = any>(
  endpoint: string,
  config?: ApiRequestConfig
) => Promise<ApiEnvelope<T>>;

declare const api: {
  get: typeof apiGet;
  post: typeof apiPost;
  put: typeof apiPut;
  delete: typeof apiDelete;
};
export default api;