import { AlertTriangle, ArrowLeft, ShieldX } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import Button from './ui/Button'
import Card from './ui/Card'

/**
 * Access Denied screen (Phase: Role, Page & Permission restriction system).
 *
 * Rendered by ProtectedRoute when an authenticated user reaches a route
 * whose effective permission they do not hold. UX only — the backend still
 * independently refuses every unauthorized API request (§19/§35).
 *
 * Place: frontend/src/components/AccessDenied.jsx
 */
const AccessDenied = ({ permission, message }) => {
  const navigate = useNavigate()

  return (
    <div className="min-h-[60vh] flex items-center justify-center p-6">
      <Card className="max-w-lg w-full">
        <div className="text-center py-8 px-4">
          <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
            <ShieldX className="h-8 w-8 text-red-600 dark:text-red-400" />
          </div>
          <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100">Access denied</h1>
          <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
            {message ||
              'Your account does not have permission to open this page. If you believe this is a mistake, contact your administrator.'}
          </p>
          {permission && (
            <p className="mt-3 inline-flex items-center gap-1 rounded-md bg-gray-100 dark:bg-slate-700 px-2 py-1 text-xs font-mono text-gray-600 dark:text-gray-300">
              <AlertTriangle className="h-3.5 w-3.5" />
              required: {permission}
            </p>
          )}
          <div className="mt-6 flex justify-center gap-3">
            <Button variant="outline" onClick={() => navigate(-1)}>
              <ArrowLeft className="h-4 w-4 mr-1" />
              Go back
            </Button>
          </div>
        </div>
      </Card>
    </div>
  )
}

export default AccessDenied
