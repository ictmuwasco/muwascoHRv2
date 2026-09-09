import { useEffect, useState } from 'react'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import { useAuth } from '../../context/AuthContext'
import { formatDate } from '../leave/leaveManageShared.jsx'

/**
 * Delegations — Temporary Delegation / Acting Authority management (§24-§26).
 *
 *  - Every role sees its OWN delegations (as delegator or delegate).
 *  - Holders of delegations:create (supervisory roles) get the "New
 *    Delegation" form: explicit delegate + window + authority + reason (§25).
 *  - Holders of delegations:approve (HR) can approve/reject pending requests
 *    (§11); delegator or HR can cancel pending/approved/active ones (§35).
 *
 * The page is pure UX: every action is re-authorized server-side and the
 * effective permissions (sidebar, Manage Leave, banner) update through the
 * AuthContext permission refresh.
 */

const TABS = [
  { key: 'pending', label: 'Pending' },
  { key: 'active', label: 'Active' },
  { key: 'upcoming', label: 'Upcoming' },
  { key: 'history', label: 'History' },
]

const today = () => new Date().toISOString().slice(0, 10)

const filterRows = (rows, tab) => {
  const now = today()
  switch (tab) {
    case 'pending':
      return rows.filter((r) => r.status === 'pending')
    case 'active':
      return rows.filter(
        (r) =>
          r.status === 'active' ||
          (r.status === 'approved' && r.start_date <= now && r.end_date >= now)
      )
    case 'upcoming':
      return rows.filter((r) => r.status === 'approved' && r.start_date > now)
    case 'history':
      return rows.filter((r) => ['expired', 'cancelled', 'rejected'].includes(r.status))
    default:
      return rows
  }
}

const statusBadge = (status) => {
  const map = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/20 dark:text-yellow-300',
    approved: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-300',
    active: 'bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-300',
    expired: 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-gray-300',
    cancelled: 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-gray-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300',
  }
  return map[status] || map.expired
}

const prettyPermission = (perm) => {
  const [module, action] = String(perm || '').split(':')
  return `${module} · ${action}`
}

const Delegations = () => {
  const { can, user } = useAuth()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [tab, setTab] = useState('pending')
  const [actionError, setActionError] = useState('')

  // Create form state
  const [showCreate, setShowCreate] = useState(false)
  const [delegates, setDelegates] = useState([])
  const [delegatable, setDelegatable] = useState({ flat: [], grouped: {} })
  const [form, setForm] = useState({
    delegate_user_id: '',
    start_date: '',
    end_date: '',
    permissions: [],
    reason: '',
  })
  const [submitting, setSubmitting] = useState(false)

  const canCreate = can('delegations', 'create')
  const canApprove = can('delegations', 'approve')

  const fetchRows = async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/delegations')
      setRows(response.data?.data?.delegations || [])
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load delegations.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchRows()
  }, [])

  const openCreate = async () => {
    setActionError('')
    setForm({ delegate_user_id: '', start_date: '', end_date: '', permissions: [], reason: '' })
    setShowCreate(true)
    try {
      const [delegatesRes, permsRes] = await Promise.all([
        api.get('/delegations/eligible-delegates'),
        api.get('/delegations/delegatable-permissions'),
      ])
      setDelegates(delegatesRes.data?.data?.delegates || [])
      setDelegatable(permsRes.data?.data?.permissions || { flat: [], grouped: {} })
    } catch (err) {
      setActionError(err.response?.data?.message || 'Failed to load delegation options.')
    }
  }

  const togglePermission = (perm) => {
    setForm((prev) => ({
      ...prev,
      permissions: prev.permissions.includes(perm)
        ? prev.permissions.filter((p) => p !== perm)
        : [...prev.permissions, perm],
    }))
  }

  const submitCreate = async (event) => {
    event.preventDefault()
    setSubmitting(true)
    setActionError('')
    try {
      await api.post('/delegations', form)
      setShowCreate(false)
      await fetchRows()
      setTab('pending')
    } catch (err) {
      setActionError(err.response?.data?.message || 'Failed to create the delegation.')
    } finally {
      setSubmitting(false)
    }
  }

  const decide = async (id, action) => {
    setActionError('')
    const reason =
      action === 'approve'
        ? ''
        : window.prompt(`Reason for ${action === 'reject' ? 'rejecting' : 'cancelling'} this delegation (optional):`) || ''
    try {
      await api.put(`/delegations/${id}/${action}`, { reason })
      await fetchRows()
    } catch (err) {
      setActionError(err.response?.data?.message || `Failed to ${action} the delegation.`)
    }
  }

  const visibleRows = filterRows(rows, tab)
  const pendingCount = rows.filter((r) => r.status === 'pending').length
  const userId = user?.id

  return (
    <div className="space-y-4">
      {error && (
        <div className="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-md">
          {error}
        </div>
      )}
      {actionError && (
        <div className="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-md">
          {actionError}
        </div>
      )}

      <Card>
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
          <div>
            <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
              Delegations <span className="text-sm font-normal text-gray-500 dark:text-gray-400">(Acting Authority)</span>
            </h2>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Temporary, time-bound transfer of approval authority. Your permanent role never changes.
            </p>
          </div>
          {canCreate && (
            <button
              onClick={openCreate}
              className="px-4 py-2 rounded-lg bg-primary-600 text-white text-sm font-medium hover:bg-primary-700 transition-colors"
            >
              New Delegation
            </button>
          )}
        </div>

        <div className="flex flex-wrap gap-2 mb-4">
          {TABS.map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`px-3 py-1.5 rounded-full text-sm font-medium transition-colors ${
                tab === t.key
                  ? 'bg-primary-600 text-white'
                  : 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-600'
              }`}
            >
              {t.label}
              {t.key === 'pending' && pendingCount > 0 && (
                <span className="ml-1.5 px-1.5 py-0.5 rounded-full text-xs bg-red-600 text-white">{pendingCount}</span>
              )}
            </button>
          ))}
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-gray-600 dark:text-gray-400">
                <th className="px-4 py-2">Delegator</th>
                <th className="px-4 py-2">Delegate</th>
                <th className="px-4 py-2">Authority</th>
                <th className="px-4 py-2">Scope</th>
                <th className="px-4 py-2">Period</th>
                <th className="px-4 py-2">Status</th>
                <th className="px-4 py-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                    Loading delegations…
                  </td>
                </tr>
              ) : visibleRows.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                    No delegations in this view.
                  </td>
                </tr>
              ) : (
                visibleRows.map((row) => {
                  const iAmDelegator = userId === row.delegator_user_id
                  const cancellable =
                    ['pending', 'approved', 'active'].includes(row.status) &&
                    (iAmDelegator || can('delegations', 'cancel'))
                  return (
                    <tr key={row.id} className="border-t border-gray-200 dark:border-slate-700">
                      <td className="px-4 py-2 text-gray-900 dark:text-gray-100">{row.delegator_name}</td>
                      <td className="px-4 py-2 text-gray-900 dark:text-gray-100">{row.delegate_name}</td>
                      <td className="px-4 py-2 text-xs text-gray-700 dark:text-gray-300">
                        {(row.permissions || []).map(prettyPermission).join(', ')}
                      </td>
                      <td className="px-4 py-2 text-xs text-gray-700 dark:text-gray-300">{row.scope_label}</td>
                      <td className="px-4 py-2 text-xs text-gray-700 dark:text-gray-300">
                        {formatDate(row.start_date)} → {formatDate(row.end_date)}
                      </td>
                      <td className="px-4 py-2">
                        <span className={`px-2 py-1 rounded-full text-xs font-medium ${statusBadge(row.status)}`}>
                          {row.status}
                        </span>
                      </td>
                      <td className="px-4 py-2 space-x-2 whitespace-nowrap">
                        {row.status === 'pending' && canApprove && (
                          <>
                            <button
                              onClick={() => decide(row.id, 'approve')}
                              className="px-2 py-1 rounded text-xs font-medium bg-green-600 text-white hover:bg-green-700"
                            >
                              Approve
                            </button>
                            <button
                              onClick={() => decide(row.id, 'reject')}
                              className="px-2 py-1 rounded text-xs font-medium bg-red-600 text-white hover:bg-red-700"
                            >
                              Reject
                            </button>
                          </>
                        )}
                        {cancellable && (
                          <button
                            onClick={() => decide(row.id, 'cancel')}
                            className="px-2 py-1 rounded text-xs font-medium border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700"
                          >
                            Cancel
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {showCreate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setShowCreate(false)}>
          <form
            onClick={(e) => e.stopPropagation()}
            onSubmit={submitCreate}
            className="w-full max-w-lg bg-white dark:bg-slate-800 rounded-xl shadow-xl border dark:border-slate-700 p-5 space-y-4 max-h-[90vh] overflow-y-auto"
          >
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">New Delegation</h3>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Explicitly choose who acts for you, what authority they receive, and for how long. The request
              requires HR approval before it becomes effective.
            </p>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Delegate *</label>
              <select
                required
                value={form.delegate_user_id}
                onChange={(e) => setForm({ ...form, delegate_user_id: e.target.value })}
                className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100"
              >
                <option value="">Select a delegate…</option>
                {delegates.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.first_name} {d.last_name} ({d.employee_id}) — {d.role}
                  </option>
                ))}
              </select>
              {delegates.length === 0 && (
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">No eligible delegates in your scope.</p>
              )}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start date *</label>
                <input
                  type="date"
                  required
                  min={today()}
                  value={form.start_date}
                  onChange={(e) => setForm({ ...form, start_date: e.target.value })}
                  className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End date *</label>
                <input
                  type="date"
                  required
                  min={form.start_date || today()}
                  value={form.end_date}
                  onChange={(e) => setForm({ ...form, end_date: e.target.value })}
                  className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100"
                />
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Delegated authority * <span className="font-normal text-gray-500 dark:text-gray-400">(only what your role may delegate)</span>
              </label>
              <div className="space-y-1.5">
                {delegatable.flat.length === 0 && (
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    No delegatable authority is configured for your role.
                  </p>
                )}
                {delegatable.flat.map((perm) => (
                  <label key={perm} className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input
                      type="checkbox"
                      checked={form.permissions.includes(perm)}
                      onChange={() => togglePermission(perm)}
                      className="h-4 w-4 rounded border-gray-300 text-primary-600"
                    />
                    {prettyPermission(perm)}
                  </label>
                ))}
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason *</label>
              <textarea
                required
                rows={2}
                maxLength={500}
                placeholder="e.g. Annual leave 10–25 September"
                value={form.reason}
                onChange={(e) => setForm({ ...form, reason: e.target.value })}
                className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100"
              />
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => setShowCreate(false)}
                className="px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={submitting}
                className="px-4 py-2 rounded-lg text-sm font-medium bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-60"
              >
                {submitting ? 'Submitting…' : 'Submit for approval'}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  )
}

export default Delegations
