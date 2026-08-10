import axios from 'axios'

// Create axios instance
// Use direct URL to bypass Vite proxy issues
const isProduction = import.meta.env.PROD
const baseURL = isProduction 
  ? '/hrdemo/api' 
  : 'http://localhost/hrdemo/api'

export const api = axios.create({
  baseURL: baseURL,
  // Do NOT set Content-Type globally - axios will set it automatically
  // to multipart/form-data when sending FormData, and application/json otherwise.
  timeout: 15000,
  withCredentials: true,
})

// Request interceptor
// The access token is now stored in an httpOnly cookie (set by the server),
// so it is sent automatically with withCredentials. No manual header needed.
api.interceptors.request.use(
  (config) => {
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor to handle errors
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Don't clear auth for consent/status checks during login flow
      // These can fail if session isn't fully established yet
      const isConsentCheck = error.config?.url?.includes('/consent/status')
      
      if (!isConsentCheck) {
        // Unauthorized - clear user data and redirect to login
        // (the httpOnly cookie is cleared server-side on logout/expiry)
        localStorage.removeItem('user')
        window.location.href = '/login'
      }
    }
    return Promise.reject(error)
  }
)

export default api