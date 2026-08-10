import axios from 'axios'

// Create axios instance
// Use direct URL to bypass Vite proxy issues
const isProduction = import.meta.env.PROD
const baseURL = isProduction 
  ? '/hrdemo/api' 
  : 'http://localhost/hrdemo/api'

export const api = axios.create({
  baseURL: baseURL,
  headers: {
    'Content-Type': 'application/json',
  },
  // Prevent infinite waiting. Clock In/Out should respond quickly.
  // 15s is generous for GPS + backend processing.
  timeout: 15000,
  // CRITICAL: Send cookies/session cookies with every request
  // The backend uses PHP sessions for auth, so cookies must be included
  withCredentials: true,
})

// Request interceptor to add auth token
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
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
      // Unauthorized - clear storage and redirect to login
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export default api