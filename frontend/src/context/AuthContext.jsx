import { createContext, useContext, useState, useEffect } from 'react'
import api from '../utils/api'

const AuthContext = createContext(null)

export const useAuth = () => {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return context
}

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    // Check for existing session
    // The access token is now in an httpOnly cookie (set by the server),
    // so we only need to restore the cached user profile from localStorage.
    const userData = localStorage.getItem('user')

    if (userData) {
      try {
        const parsed = JSON.parse(userData)
        if (parsed && typeof parsed === 'object') {
          setUser(parsed)
        } else {
          // Corrupt entry — clear it so we don't loop
          localStorage.removeItem('user')
        }
      } catch {
        // Corrupt JSON — clear and continue
        localStorage.removeItem('user')
      }
    }

    setLoading(false)
  }, [])

  const login = async (email, password) => {
    try {
      const response = await api.post('/auth/login', {
        email,
        password,
      })

   
      const payload = response?.data
      const data = payload?.data
      const userData = data?.user

      if (!userData) {
        return {
          success: false,
          message:
            payload?.message ||
            'Login response was malformed. Please contact your administrator.',
        }
      }

      // The access token is set as an httpOnly cookie by the server.
      // We only persist the user profile for fast UI restore.
      localStorage.setItem('user', JSON.stringify(userData))

      setUser(userData)

      return { success: true }
    } catch (error) {
      const errorData = error.response?.data;

      // Provide user-friendly error messages based on error type
      let message = 'Login failed. Please try again.';

      if (error.response) {
        if (errorData) {
          if (errorData.error === 'DATABASE_CONNECTION_ERROR' || errorData.error === 'DATABASE_ERROR') {
            message = 'Database is unreachable. Please make sure MySQL is running in XAMPP and the "muwasco" database exists.';
          } else if (errorData.message) {
            message = errorData.message;
          } else if (errorData.errors) {
            // Validation errors (Laravel-style { errors: { field: [...] } })
            const errors = Object.values(errorData.errors).flat();
            message = errors.join(', ');
          }
        }
      } else {
        // No HTTP response — usually means the dev server / Vite proxy
        // couldn't reach the PHP API (XAMPP Apache not running, or the
        // /api proxy is misconfigured). Give an actionable hint.
        message =
          'Cannot reach the server. Make sure XAMPP (Apache + MySQL) is running and try again.';
      }

      // Log full error to the browser console for easier debugging
      // eslint-disable-next-line no-console
      console.error('Login error:', error, errorData)

      return {
        success: false,
        message: message,
      }
    }
  }

  const logout = async () => {
    try {
      await api.post('/auth/logout')
    } catch (error) {
      // Ignore errors, still logout
    } finally {
      localStorage.removeItem('user')
      setUser(null)
    }
  }

  const value = {
    user,
    login,
    logout,
    loading,
    isAuthenticated: !!user
  }

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  )
}