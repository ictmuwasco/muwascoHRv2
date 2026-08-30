import axios, { AxiosInstance, AxiosError, InternalAxiosRequestConfig } from 'axios';
import type { ApiResponse } from '../types';
import { getRequestId, setRequestId, reportClientError } from '../utils/errorReporting';

// Provide a minimal ImportMeta.env typing for Vite variables to avoid
// "Property 'env' does not exist on type 'ImportMeta'" TypeScript error
declare global {
  interface ImportMeta {
    readonly env: {
      readonly VITE_API_URL?: string;
      // add other VITE_... vars here as needed
    };
  }
}

const API_BASE_URL = import.meta.env.VITE_API_URL || '/api';

const apiClient: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
  // The access token is now in an httpOnly cookie (set by the server),
  // so it is sent automatically with credentials.
  withCredentials: true,
});

// Request interceptor
// No manual Authorization header needed — the httpOnly cookie is sent
// automatically via withCredentials. We DO stamp every outgoing request with
// the session correlation id so backend errors, audit rows and this SPA all
// share one traceable X-Request-ID.
apiClient.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    config.headers['X-Request-ID'] = getRequestId();
    return config;
  },
  (error: AxiosError) => Promise.reject(error)
);

const CLIENT_COLLECTOR_PATH = '/system/client-errors';

// Response interceptor - correlation id adoption, client error reporting,
// and 401 handling.
apiClient.interceptors.response.use(
  (response) => {
    // The server is authoritative for the correlation id (it may have
    // adopted/generated its own); adopt it for subsequent requests/reports.
    const serverId = response.headers?.['x-request-id'];
    if (serverId) setRequestId(String(serverId));
    return response;
  },
  (error: AxiosError) => {
    const status = error.response?.status;

    // Adopt correlation id even from failed responses (error envelope §12).
    const serverId = error.response?.headers?.['x-request-id'];
    if (serverId) setRequestId(String(serverId));

    const requestUrl = String(error.config?.url ?? '');

    // Never report the collector itself (recursion guard) or auth redirects.
    if (!requestUrl.includes(CLIENT_COLLECTOR_PATH)) {
      if (status === undefined) {
        // Network failure: request never reached the server.
        reportClientError({
          kind: 'network',
          message: `Network failure calling ${error.config?.method?.toUpperCase()} ${requestUrl}: ${error.message}`,
          endpoint: requestUrl,
          severity: 'HIGH',
        });
      } else if (status >= 500) {
        // Unexpected SERVER failures only - 4xx is expected behaviour (§34).
        const data = error.response?.data as any;
        reportClientError({
          kind: 'api',
          message: `API ${status} on ${error.config?.method?.toUpperCase()} ${requestUrl}: ${data?.message ?? error.message}`,
          stack: data?.error?.details,
          endpoint: requestUrl,
          status_code: status,
          extra: {
            request_id: data?.error?.request_id ?? getRequestId(),
            error_code: data?.error?.code,
            reference: data?.error?.reference,
          },
          severity: status >= 500 ? 'HIGH' : 'MEDIUM',
        });
      }
    }

    if (status === 401) {
      localStorage.removeItem('user');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default apiClient;