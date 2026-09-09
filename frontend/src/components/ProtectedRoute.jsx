import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import AccessDenied from './AccessDenied'
import { firstPermittedRoute, parsePermission } from '../config/pagePermissions'

/**
 * Route guard (Phase 2 Section 12, hardened by the Role/Page/Permission
 * restriction phase).
 *
 * Properties:
 *  - `permission` (optional) - "module:action" string, normally supplied from
 *    the central Page Permission Registry (config/pagePermissions.jsx). When
 *    provided and the authenticated user's effective permission set (from
 *    /auth/user) does not include it, the AccessDenied screen is rendered
 *    instead of the page - no silent redirect loops (Section 20).
 *  - `fallbackPermission` (optional) - when provided INSTEAD of `permission`,
 *    a denied user is redirected to the first route they ARE permitted to
 *    open (used for wrapper layouts).
 *
 * SECURITY MODEL: this is UX/DX convenience ONLY. The backend enforces
 * authorization independently on every API request, so a user who navigates
 * here directly while lacking the permission still cannot fetch data. This
 * guard prevents rendering pages the user cannot use and gives clear
 * feedback instead of an empty or erroring screen.
 */
const ProtectedRoute = ({ children, permission, fallbackPermission }) => {
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
    const [module, action] = parsePermission(permission)
    if (module && !can(module, action)) {
      return <AccessDenied permission={permission} />
    }
  }

  if (fallbackPermission) {
    const [module, action] = parsePermission(fallbackPermission)
    if (module && !can(module, action)) {
      // Safe redirect - never /dashboard blindly (that page may itself be
      // denied), so the user lands on the first route they may open.
      return <Navigate to={firstPermittedRoute(can)} replace />
    }
  }

  // Authenticated (and permitted, when required) - render protected content
  return children
}

export default ProtectedRoute
