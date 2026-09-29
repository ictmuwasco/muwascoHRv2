import { Fragment, useEffect, useState } from 'react';
import api from '../../utils/api';
import Card from '../../components/ui/Card';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../leave/leaveManageShared.jsx';

/**
 * Delegations — Temporary Delegation / Acting Authority management (§24-§26).
 *
 *  - Every role sees its OWN delegations (as delegator or delegate).
 *  - Holders of delegations:create (supervisory roles) get the "New
 *    Delegation" form: explicit delegate + window + authority + reason (§25).
 *  - Holders of delegations:approve (HR) can approve/reject pending requests
 *    (§11); delegator or HR can cancel pending/approved/active ones (§35).
 *
 * TWO SOURCES appear here (the `source` column, migration 096):
 *
 *  - 'manual' — the hand-created rows from the "New Delegation" button above,
 *    which go through the pending → HR-approved queue.
 *  - 'leave_application' — AUTO-MINTED the moment a leave application is
 *    fully approved, for the delegate the applicant picked on the Apply Leave
 *    form. These skip the manual queue on purpose: the leave workflow's own
 *    multi-stage chain is already the authorization gate. They appear as
 *    Active (window open) or Upcoming (window not yet open) and are marked
 *    with a Leave #N reference, so the record explains itself.
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
];

const today = () => new Date().toISOString().slice(0, 10);

const filterRows = (rows, tab) => {
  const now = today();
  switch (tab) {
    case 'pending':
      return rows.filter((r) => r.status === 'pending');
    case 'active':
      return rows.filter(
        (r) =>
          r.status === 'active' ||
          (r.status === 'approved' && r.start_date <= now && r.end_date >= now),
      );
    case 'upcoming':
      return rows.filter((r) => r.status === 'approved' && r.start_date > now);
    case 'history':
      return rows.filter((r) => ['expired', 'cancelled', 'rejected'].includes(r.status));
    default:
      return rows;
  }
};

const statusBadge = (status) => {
  const map = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/20 dark:text-yellow-300',
    approved: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-300',
    active: 'bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-300',
    expired: 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-gray-300',
    cancelled: 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-gray-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300',
  };
  return map[status] || map.expired;
};

const prettyPermission = (perm) => {
  const [module, action] = String(perm || '').split(':');
  return `${module} · ${action}`;
};

/**
 * The backend pre-resolves every snapshotted permission into
 * permission_details [{ permission, module_label, action_label, type }], where
 * `type` is the catalog's 'page' (drives sidebar/route visibility) or 'action'
 * (drives buttons). Preferring that keeps the permission vocabulary in ONE
 * place — config/permissions.php — instead of duplicating labels in the SPA.
 *
 * Older rows may not carry it, so fall back to splitting the raw key.
 */
const detailsFor = (row) => {
  if (Array.isArray(row.permission_details) && row.permission_details.length > 0) {
    return row.permission_details;
  }
  return (row.permissions || []).map((perm) => {
    const [module, action] = String(perm).split(':');
    return {
      permission: perm,
      module,
      action,
      module_label: module,
      action_label: action,
      type: 'action',
    };
  });
};

/** Compact one-line summary for the table cell. */
const summarise = (row) => {
  const details = detailsFor(row);
  if (details.length === 0) return '—';
  return details.map((d) => `${d.module_label} · ${d.action_label}`).join(', ');
};

const sourceBadge = (row) =>
  row.source === 'leave_application' ? (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-300">
      Auto — Leave #{row.leave_application_id}
    </span>
  ) : (
    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-gray-300">
      Manual
    </span>
  );

/**
 * The expanded "who / what" panel for one delegation — the audit answer to
 * "who is covering, and exactly which pages did they get?". Split into
 * "Pages unlocked" (catalog type 'page' — drives sidebar + route visibility)
 * and "Actions enabled" (button-level), because those are different kinds of
 * authority and reading them as one flat list is how over-broad grants hide.
 */
const DetailRow = ({ row, pages, actions }) => (
  <tr className="border-t border-gray-200 bg-gray-50 dark:bg-slate-800/50 dark:border-slate-700">
    <td colSpan={8} className="px-4 py-4">
      <div className="grid gap-4 md:grid-cols-2">
        <div>
          <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
            Who
          </h4>
          <dl className="space-y-1 text-xs text-gray-700 dark:text-gray-300">
            <div>
              <dt className="inline font-medium">Away (delegator):</dt>{' '}
              <dd className="inline">{row.delegator_name}</dd>
            </div>
            <div>
              <dt className="inline font-medium">Acting (delegate):</dt>{' '}
              <dd className="inline">{row.delegate_name}</dd>
            </div>
            <div>
              <dt className="inline font-medium">Role covered:</dt>{' '}
              <dd className="inline">{row.delegated_role || '—'}</dd>
            </div>
            <div>
              <dt className="inline font-medium">Scope:</dt>{' '}
              <dd className="inline">{row.scope_label}</dd>
            </div>
            <div>
              <dt className="inline font-medium">Valid:</dt>{' '}
              <dd className="inline">
                {formatDate(row.start_date)} → {formatDate(row.end_date)}
              </dd>
            </div>
            {row.leave_application_id && (
              <div>
                <dt className="inline font-medium">From leave:</dt>{' '}
                <dd className="inline">Application #{row.leave_application_id}</dd>
              </div>
            )}
            {row.reason && (
              <div>
                <dt className="inline font-medium">Reason:</dt>{' '}
                <dd className="inline">{row.reason}</dd>
              </div>
            )}
          </dl>
        </div>

        <div>
          <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
            What pages & actions
          </h4>
          {pages.length === 0 && actions.length === 0 ? (
            <p className="text-xs text-gray-500 dark:text-gray-400">
              No permissions were granted.
            </p>
          ) : (
            <div className="space-y-2">
              {pages.length > 0 && (
                <div>
                  <p className="text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Pages unlocked
                  </p>
                  <div className="flex flex-wrap gap-1">
                    {pages.map((d) => (
                      <span
                        key={d.permission}
                        title={d.permission}
                        className="px-2 py-0.5 rounded bg-primary-100 text-primary-800 text-[11px] font-medium dark:bg-primary-500/20 dark:text-primary-200"
                      >
                        {d.module_label}
                      </span>
                    ))}
                  </div>
                </div>
              )}
              {actions.length > 0 && (
                <div>
                  <p className="text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Actions enabled
                  </p>
                  <div className="flex flex-wrap gap-1">
                    {actions.map((d) => (
                      <span
                        key={d.permission}
                        title={d.permission}
                        className="px-2 py-0.5 rounded bg-gray-200 text-gray-800 text-[11px] dark:bg-slate-600 dark:text-gray-200"
                      >
                        {d.module_label} · {d.action_label}
                      </span>
                    ))}
                  </div>
                </div>
              )}
              <p className="text-[11px] text-gray-500 dark:text-gray-400">
                Temporary only — the delegate&apos;s own role and permissions are unchanged and
                resume automatically on {formatDate(row.end_date)}.
              </p>
            </div>
          )}
        </div>
      </div>
    </td>
  </tr>
);

const Delegations = () => {
  const { can, user } = useAuth();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [tab, setTab] = useState('pending');
  const [actionError, setActionError] = useState('');
  // Which row's "who / what pages" panel is open (null = all collapsed).
  const [expandedId, setExpandedId] = useState(null);

  // Create form state
  const [showCreate, setShowCreate] = useState(false);
  const [delegates, setDelegates] = useState([]);
  const [delegatable, setDelegatable] = useState({ flat: [], grouped: {} });
  const [form, setForm] = useState({
    delegate_user_id: '',
    start_date: '',
    end_date: '',
    permissions: [],
    reason: '',
  });
  const [submitting, setSubmitting] = useState(false);

  const canCreate = can('delegations', 'create');
  const canApprove = can('delegations', 'approve');

  const fetchRows = async () => {
    setLoading(true);
    setError('');
    try {
      const response = await api.get('/delegations');
      setRows(response.data?.data?.delegations || []);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load delegations.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchRows();
  }, []);

  const openCreate = async () => {
    setActionError('');
    setForm({ delegate_user_id: '', start_date: '', end_date: '', permissions: [], reason: '' });
    setShowCreate(true);
    try {
      const [delegatesRes, permsRes] = await Promise.all([
        api.get('/delegations/eligible-delegates'),
        api.get('/delegations/delegatable-permissions'),
      ]);
      setDelegates(delegatesRes.data?.data?.delegates || []);
      setDelegatable(permsRes.data?.data?.permissions || { flat: [], grouped: {} });
    } catch (err) {
      setActionError(err.response?.data?.message || 'Failed to load delegation options.');
    }
  };

  const togglePermission = (perm) => {
    setForm((prev) => ({
      ...prev,
      permissions: prev.permissions.includes(perm)
        ? prev.permissions.filter((p) => p !== perm)
        : [...prev.permissions, perm],
    }));
  };

  const submitCreate = async (event) => {
    event.preventDefault();
    setSubmitting(true);
    setActionError('');
    try {
      await api.post('/delegations', form);
      setShowCreate(false);
      await fetchRows();
      setTab('pending');
    } catch (err) {
      setActionError(err.response?.data?.message || 'Failed to create the delegation.');
    } finally {
      setSubmitting(false);
    }
  };

  const decide = async (id, action) => {
    setActionError('');
    const reason =
      action === 'approve'
        ? ''
        : window.prompt(
            `Reason for ${action === 'reject' ? 'rejecting' : 'cancelling'} this delegation (optional):`,
          ) || '';
    try {
      await api.put(`/delegations/${id}/${action}`, { reason });
      await fetchRows();
    } catch (err) {
      setActionError(err.response?.data?.message || `Failed to ${action} the delegation.`);
    }
  };

  const visibleRows = filterRows(rows, tab);
  const pendingCount = rows.filter((r) => r.status === 'pending').length;
  const userId = user?.id;

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
              Delegations{' '}
              <span className="text-sm font-normal text-gray-500 dark:text-gray-400">
                (Acting Authority)
              </span>
            </h2>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Temporary, time-bound transfer of approval authority. Your permanent role never
              changes.
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
                <span className="ml-1.5 px-1.5 py-0.5 rounded-full text-xs bg-red-600 text-white">
                  {pendingCount}
                </span>
              )}
            </button>
          ))}
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-gray-600 dark:text-gray-400">
                <th className="px-4 py-2">Source</th>
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
                  <td
                    colSpan={8}
                    className="px-4 py-8 text-center text-gray-500 dark:text-gray-400"
                  >
                    Loading delegations…
                  </td>
                </tr>
              ) : visibleRows.length === 0 ? (
                <tr>
                  <td
                    colSpan={8}
                    className="px-4 py-8 text-center text-gray-500 dark:text-gray-400"
                  >
                    No delegations in this view.
                  </td>
                </tr>
              ) : (
                visibleRows.map((row) => {
                  const iAmDelegator = userId === row.delegator_user_id;
                  const cancellable =
                    ['pending', 'approved', 'active'].includes(row.status) &&
                    (iAmDelegator || can('delegations', 'cancel'));
                  const expanded = expandedId === row.id;
                  const details = detailsFor(row);
                  // Split on the catalog's page/action type: "which PAGES was
                  // this delegate given" is the question the record has to
                  // answer, and it is answered directly rather than inferred.
                  const pages = details.filter((d) => d.type === 'page');
                  const actions = details.filter((d) => d.type !== 'page');
                  return (
                    <Fragment key={row.id}>
                      <tr className="border-t border-gray-200 dark:border-slate-700">
                        <td className="px-4 py-2">{sourceBadge(row)}</td>
                        <td className="px-4 py-2 text-gray-900 dark:text-gray-100">
                          {row.delegator_name}
                        </td>
                        <td className="px-4 py-2 text-gray-900 dark:text-gray-100">
                          {row.delegate_name}
                        </td>
                        <td className="px-4 py-2 text-xs text-gray-700 dark:text-gray-300 max-w-xs">
                          <span className="line-clamp-2">{summarise(row)}</span>
                          <button
                            onClick={() => setExpandedId(expanded ? null : row.id)}
                            className="mt-1 block text-primary-600 hover:underline dark:text-primary-400"
                          >
                            {expanded ? 'Hide details' : `Details (${details.length})`}
                          </button>
                        </td>
                        <td className="px-4 py-2 text-xs text-gray-700 dark:text-gray-300">
                          {row.scope_label}
                        </td>
                        <td className="px-4 py-2 text-xs text-gray-700 dark:text-gray-300 whitespace-nowrap">
                          {formatDate(row.start_date)} → {formatDate(row.end_date)}
                        </td>
                        <td className="px-4 py-2">
                          <span
                            className={`px-2 py-1 rounded-full text-xs font-medium ${statusBadge(row.status)}`}
                          >
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
                      {expanded && <DetailRow row={row} pages={pages} actions={actions} />}
                    </Fragment>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {showCreate && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setShowCreate(false)}
        >
          <form
            onClick={(e) => e.stopPropagation()}
            onSubmit={submitCreate}
            className="w-full max-w-lg bg-white dark:bg-slate-800 rounded-xl shadow-xl border dark:border-slate-700 p-5 space-y-4 max-h-[90vh] overflow-y-auto"
          >
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
              New Delegation
            </h3>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Explicitly choose who acts for you, what authority they receive, and for how long. The
              request requires HR approval before it becomes effective.
            </p>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Delegate *
              </label>
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
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  No eligible delegates in your scope.
                </p>
              )}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Start date *
                </label>
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
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                  End date *
                </label>
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
                Delegated authority *{' '}
                <span className="font-normal text-gray-500 dark:text-gray-400">
                  (only what your role may delegate)
                </span>
              </label>
              <div className="space-y-1.5">
                {delegatable.flat.length === 0 && (
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    No delegatable authority is configured for your role.
                  </p>
                )}
                {delegatable.flat.map((perm) => (
                  <label
                    key={perm}
                    className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"
                  >
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
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Reason *
              </label>
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
  );
};

export default Delegations;
