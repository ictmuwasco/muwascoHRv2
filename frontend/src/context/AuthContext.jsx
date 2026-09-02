import { createContext, useContext, useState, useEffect, useRef } from 'react'
import api from '../utils/api'

const AuthContext = createContext(null)

/** One-time flag so we never spam the console on repeated fallbacks. */
let warnedMissingProvider = false

export const useAuth = () => {
  const context = useContext(AuthContext)
  if (!context) {
    // Defensive fallback instead of throwing. A component mounted outside
    // <AuthProvider> - or a transient Vite HMR module swap while the dev
    // server hot-reloads edited files - previously crashed the entire tree
    // with "useAuth must be used within an AuthProvider" (seen once from
    // /leave/roster). Behaving as signed-out lets ProtectedRoute send the
    // user to /login and every other consumer keep rendering safely.
    if (!warnedMissingProvider) {
      warnedMissingProvider = true
      console.warn('useAuth called outside AuthProvider - using signed-out fallback.')
    }
    return {
      user: null,
      loading: false,
      isAuthenticated: false,
      can: () => false,
      canAny: () => false,
      login: async () => ({
        success: false,
        message: 'Authentication is unavailable. Please reload the page.',
      }),
      logout: async () => { localStorage.removeItem('user') },
    }
  }
  return context
}

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  const fetchedUserRef = useRef(false)

  useEffect(() => {
    // React StrictMode (development) double-invokes effects.
    // Guard against the duplicate fetch so /auth/user is called once.
    if (fetchedUserRef.current) return
    fetchedUserRef.current = true

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

    // Phase 2: refresh the effective permission set from the server when a
    // session exists. The cached profile renders instantly, then /auth/user
    // overwrites it with the authoritative user + permissions so sidebar /
    // button visibility reflects recent permission changes. UX only — the
    // backend still enforces authorization independently.
    const refreshPermissions = async () => {
      try {
        const response = await api.get('/auth/user')
        const freshUser = response?.data?.data ?? response?.data
        if (freshUser && typeof freshUser === 'object' && freshUser.id) {
          localStorage.setItem('user', JSON.stringify(freshUser))
          setUser(freshUser)
        }
      } catch {
        // Silent — a stale session cookie simply leaves the cached profile
        // in place; ProtectedRoute / backend 401 handling will bounce the
        // user to /login when a request actually fails.
      }
    }
    refreshPermissions()

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
      // Note: We deliberately do NOT clear the consent cache here.
      // Consent is a database fact that persists when a user logs out.
      // Keeping the cache lets ProtectedRoute skip the server-side
      // consent check on the next login — avoiding the race condition
      // where the session cookie hasn't propagated yet, which would
      // otherwise redirect already-consented users back to the consent page.
      localStorage.removeItem('user')
      setUser(null)
    }
  }

  /**
   * Centralized frontend authorization helper (Phase 2, §11–12).
   *
   * `can(module, action)` consults the EFFECTIVE permission strings the
   * backend attached to /auth/login and /auth/user responses (already the
   * result of super_admin policy + user overrides + role permissions +
   * default deny). Returns a boolean suitable for menu/button/route
   * visibility.
   *
   * SECURITY MODEL: this is UX convenience ONLY — it never replaces the
   * backend. The API enforces authorization independently on every request,
   * so a stale/incorrect cached value can at worst hide or show a button,
   * never grant access.
   *
   * @param {string} module  catalog module key, e.g. 'leave'
   * @param {string} action  catalog action key, e.g. 'approve'
   * @returns {boolean}
   */
  const can = (module, action = 'view') => {
    if (!user || !Array.isArray(user.permissions)) {
      // No effective-permission set (e.g. stale localStorage from before
      // Phase 2). Default deny for everyone except super_admin, whose
      // documented policy is unlimited access — the backend declares it.
      return !!user && (user.role === 'super_admin' || user.role === 'admin')
    }
    return user.permissions.includes(`${module}:${action}`)
  }

  /**
   * True when the user holds ANY of the given "module:action" pairs.
   * @param {Array<[string, string]>} pairs e.g. [['leave','approve'],['leave','manage']]
   * @returns {boolean}
   */
  const canAny = (pairs) => {
    if (!Array.isArray(pairs) || pairs.length === 0) return false
    return pairs.some(([module, action]) => can(module, action))
  }

  const value = {
    user,
    login,
    logout,
    loading,
    isAuthenticated: !!user,
    can,
    canAny,
  }

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  )
}