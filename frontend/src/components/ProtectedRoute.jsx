import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

/**
 * Route guard (Phase 2, §12).
 *
 * Properties:
 *  - `permission` (optional) — "module:action" string. When provided, the
 *    route is redirected to /dashboard if the authenticated user's effective
 *    permission set (from /auth/user) does not include it.
 *
 * SECURITY MODEL: this is UX/DX convenience ONLY. The backend enforces
 * authorization independently on every API request, so a user who navigates
 * here directly while lacking the permission still cannot fetch data. This
 * guard simply prevents rendering an empty/erroring page.
 */
const ProtectedRoute = ({ children, permission }) => {
  const { isAuthenticated, loading: authLoading, can } = useAuth()

  if (authLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  if (permission) {
    const [module, action] = permission.split(':')
    if (!module || !can(module, action || 'view')) {
      return <Navigate to="/dashboard" replace />
    }
  }

  // Authenticated (and permitted, when required) - render protected content
  return children
}

export default ProtectedRoute