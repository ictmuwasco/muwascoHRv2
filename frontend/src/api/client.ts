import axios, { AxiosInstance, AxiosError, InternalAxiosRequestConfig } from 'axios';
import type { ApiResponse } from '../types';

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
// automatically via withCredentials.
apiClient.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    return config;
  },
  (error: AxiosError) => Promise.reject(error)
);

// Response interceptor - handle 401
apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('user');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default apiClient;