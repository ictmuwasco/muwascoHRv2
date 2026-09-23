// ============================================================================
// Type declarations for the legacy JS fetch-wrapper API client (utils/api.js).
//
// This sidecar eliminates the @ts-ignore casts previously needed in every
// .tsx page that imports the untyped api module. The wrapper returns
// axios-shaped responses: { data, status, statusText, headers, config }.
//
// When utils/api.js is eventually converted to utils/api.ts these can be
// replaced with real implementations.
// ============================================================================

import type { ApiResponse } from '../types';

export interface ApiConfig {
  method?: string;
  body?: unknown;
  headers?: Record<string, string>;
  timeout?: number;
  responseType?: 'json' | 'blob';
  signal?: AbortSignal;
  params?: Record<string, unknown>;
  [key: string]: unknown;
}

export interface AxiosLikeResponse<T = unknown> {
  data: ApiResponse<T>;
  status: number;
  statusText: string;
  headers: Record<string, string>;
  config: Record<string, unknown>;
}

export interface ApiClient {
  get: <T = unknown>(url: string, config?: ApiConfig) => Promise<AxiosLikeResponse<T>>;
  post: <T = unknown>(
    url: string,
    data?: unknown,
    config?: ApiConfig,
  ) => Promise<AxiosLikeResponse<T>>;
  put: <T = unknown>(
    url: string,
    data?: unknown,
    config?: ApiConfig,
  ) => Promise<AxiosLikeResponse<T>>;
  delete: <T = unknown>(url: string, config?: ApiConfig) => Promise<AxiosLikeResponse<T>>;
}

declare const api: ApiClient;
export default api;
