import axios from 'axios'

// Create axios instance
// Use direct URL to bypass Vite proxy issues
// Use '/api' in dev mode so the Vite proxy handles forwarding to the backend.
// In production (built files served from XAMPP), use /hrdemo/api path.
const isProduction = import.meta.env.PROD
const baseURL = isProduction 
  ? '/hrdemo/api' 
  : '/api'

export const api = axios.create({
  baseURL: baseURL,
  // Do NOT set Content-Type globally - axios will set it automatically
  // to multipart/form-data when sending FormData, and application/json otherwise.
  timeout: 15000,
  withCredentials: true,
})

// Request interceptor
// The access token is now in an httpOnly cookie (set by the server),
// so it is sent automatically with withCredentials. No manual header needed.
api.interceptors.request.use(
  (config) => {
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const url = error.config?.url || ''
    const status = error.response?.status

    // Session validation endpoints - a 401 here genuinely means
    // the session is dead/expired. Force logout.
    const isSessionValidation = 
      url.includes('/auth/me') ||
      url.includes('/auth/refresh') ||
      (url.includes('/auth/logout') && status === 401)

    if (status === 401 && isSessionValidation) {
      localStorage.removeItem('user')
      window.location.href = '/login'
      return Promise.reject(error)
    }

    // For other 401s (e.g. /leave, /consent/status) - retry a few times.
    // This handles the race condition where the httpOnly session cookie
    // or access_token cookie hasn't propagated to the server yet after
    // login. The browser sets the cookie from the login response, but
    // there can be a brief window where subsequent API requests don't
    // include the cookie yet. Retrying after a short delay resolves this.
    if (status === 401) {
      const config = error.config
      const retryCount = config._retryCount || 0
      const maxRetries = 2
      const retryDelay = 500

      // Only retry if the user is authenticated (has a user object in localStorage)
      const hasUser = !!localStorage.getItem('user')

      if (hasUser && retryCount < maxRetries) {
        config._retryCount = retryCount + 1
        await new Promise((resolve) => setTimeout(resolve, retryDelay))
        return api(config)
      }
    }

    return Promise.reject(error)
  }
)

export default api