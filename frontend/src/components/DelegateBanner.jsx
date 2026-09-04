import { useAuth } from '../context/AuthContext'

/**
 * DelegateBanner — Temporary Delegation / Acting Authority notice (§27/§28).
 *
 * Rendered by Layout directly under the Header whenever the signed-in user
 * holds one or more ACTIVE delegations (delivered on /auth/login and
 * /auth/user as `active_delegations`, refreshed by AuthContext's permission
 * polling). The banner explains WHY the user suddenly has extra authority —
 * without ever implying a permanent role change:
 *
 *   "You are temporarily acting on behalf of {Delegator}
 *    Delegated authority: ... · Valid until 25 Sep 2026"
 *
 * It disappears automatically the moment the delegation expires or is
 * cancelled, because the next /auth/user payload no longer lists it.
 *
 * Place: frontend/src/components/DelegateBanner.jsx
 */

const formatDate = (value) => {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

const DelegateBanner = () => {
  const { user } = useAuth()
  const delegations = Array.isArray(user?.active_delegations) ? user.active_delegations : []

  if (delegations.length === 0) return null

  return (
    <div className="lg:pl-64" role="status" aria-live="polite">
      {delegations.map((delegation) => (
        <div
          key={delegation.id}
          className="mx-4 md:mx-6 mt-4 flex items-start gap-3 rounded-lg border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 px-4 py-3"
        >
          <svg
            className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            {/* lucide "user-check" */}
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <polyline points="16 11 18 13 22 9" />
          </svg>
          <div className="min-w-0 text-sm text-amber-900 dark:text-amber-200">
            <p className="font-semibold">
              Acting Delegate — you are temporarily acting on behalf of{' '}
              <span className="whitespace-nowrap">{delegation.delegator_name}</span>
            </p>
            <p className="mt-0.5 text-xs break-words">
              Delegated authority: {(delegation.permissions || []).join(', ') || delegation.delegated_role}
              {' · '}Scope: {delegation.scope_label}
              {' · '}Valid until {formatDate(delegation.end_date)}
            </p>
            <p className="mt-0.5 text-xs text-amber-700 dark:text-amber-300/80">
              This is a temporary delegation — your permanent role and permissions are unchanged and
              resume automatically on {formatDate(delegation.end_date)}.
            </p>
          </div>
        </div>
      ))}
    </div>
  )
}

export default DelegateBanner
