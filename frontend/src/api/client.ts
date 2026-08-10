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
});

// Request interceptor - add auth token
apiClient.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const token = localStorage.getItem('token');
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error: AxiosError) => Promise.reject(error)
);

// Response interceptor - handle 401.
// Only bounce to /login for *authenticated* requests that 401.
// A 401 on /auth/login is "bad credentials" — the caller wants to show
// the message, not redirect.
apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    const url: string = error.config?.url ?? '';
    const isLoginAttempt: boolean = url.includes('/auth/login');
    if (error.response?.status === 401 && !isLoginAttempt) {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default apiClient;