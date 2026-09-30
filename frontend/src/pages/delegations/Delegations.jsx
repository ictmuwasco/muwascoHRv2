import { Fragment, useEffect, useState } from 'react';
import api from '../../utils/api';
import Card from '../../components/ui/Card';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../leave/leaveManageShared.jsx';

/**
 * Delegations — the Acting Authority register (§24-§26), now READ-ONLY.
 *
 * Delegations are no longer created by hand. They are minted automatically by
 * DelegationService::createFromApprovedLeave() the moment a leave application
 * is fully approved, from the three facts the Apply Leave form already
 * captures: WHO is applying, WHICH delegate they nominated, and WHICH
 * permissions the applicant holds. So the applicant + delegate + authority
 * triple is decided by the leave workflow itself, and this page's only job is
 * to FETCH and display what that produced.
 *
 * The "New Delegation" button and its modal were removed accordingly — adding
 * authority by hand would create a second, unaudited path to the same grant.
 *
 * TWO SOURCES appear here (the `source` column, migration 096):
 *
 *  - 'leave_application' — the auto-minted rows described above. They skip the
 *    manual pending→approved queue on purpose: the leave workflow's own
 *    multi-stage chain (subsection → section → dept → MD → BOD → HR) is
 *    already the authorization gate. They show as Active (window open) or
 *    Upcoming (window not yet open), tagged with the originating Leave #N.
 *  - 'manual' — any row predating this change, kept for the audit trail.
 *
 * VISIBILITY is decided server-side by DelegationService::listFor() and is
 * scoped to the caller's organizational unit: hr_manager / super_admin /
 * managing_director see everything; a section head sees their section; a
 * department head sees their department; officers cannot open the register at
 * all. This page only renders whatever the API returned — it never widens.
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

/**
 * Why an empty tab is empty.
 *
 * Without this the register is indistinguishable from a broken one: a
 * cancelled or expired delegation is filed under History, so someone landing on
 * the default Active tab sees "No delegations in this view" and has no way to
 * tell whether the page failed, whether the filter is wrong, or whether their
 * colleagues simply have nothing booked. Naming the reason — and pointing at the
 * tab that does hold the rows — turns a dead end into an answer.
 */
const emptyExplanation = (rows, tab) => {
  if (rows.length === 0) {
    return (
      'No delegations have been recorded for anyone in your scope yet. A delegation is ' +
      'created automatically when a leave application is approved and names a delegate.'
    );
  }
  const elsewhere = TABS.filter((t) => t.key !== tab && filterRows(rows, t.key).length > 0);
  if (elsewhere.length > 0) {
    return (
      `Nothing in this view. There ${elsewhere.length === 1 ? 'is' : 'are'} ` +
      `${elsewhere.map((t) => t.label).join(' and ')} ` +
      `${elsewhere.length === 1 ? 'entry' : 'entries'} instead.`
    );
  }
  return 'Nothing in this view.';
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
            <p className="text-xs text-gray-500 dark:text-gray-400">No permissions were granted.</p>
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
  const [tab, setTab] = useState('active');
  const [actionError, setActionError] = useState('');
  // Which row's "who / what pages" panel is open (null = all collapsed).
  const [expandedId, setExpandedId] = useState(null);

  // Approve/reject only ever applies to a 'pending' row, which the removed
  // manual form used to create. Auto-minted rows arrive already effective, so
  // in practice this stays false — kept because pre-existing 'manual' rows may
  // still be awaiting an HR decision, and the decision is re-authorized
  // server-side either way.
  const canApprove = can('delegations', 'approve');

  const fetchRows = async () => {
    setLoading(true);
    setError('');
    try {
      // Single source of truth: whatever DelegationService::listFor() scopes
      // to this user is exactly what renders. The page adds no client-side
      // widening of its own.
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
  const userId = user?.id;

  // Digests for the header line. Derived from the same filterRows() the tabs
  // use, so the summary can never disagree with the tabs below it.
  const activeCount = filterRows(rows, 'active').length;
  const upcomingCount = filterRows(rows, 'upcoming').length;
  const pendingCount = filterRows(rows, 'pending').length;
  const totalCount = rows.length;
  const liveCount = activeCount + upcomingCount;

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
        <div className="mb-4">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
            Delegations{' '}
            <span className="text-sm font-normal text-gray-500 dark:text-gray-400">
              (Acting Authority)
            </span>
          </h2>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            Temporary, time-bound transfer of approval authority, recorded automatically when a
            leave application is approved. Your permanent role never changes.
          </p>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div className="flex flex-wrap gap-2">
            {TABS.map((t) => {
              // The count is ALWAYS rendered, including zero. Showing it only
              // when non-zero made a tab that legitimately holds nothing
              // indistinguishable from a tab that failed to load, which is
              // exactly the confusion this page is meant to resolve.
              const count = filterRows(rows, t.key).length;
              const isCurrent = tab === t.key;
              return (
                <button
                  key={t.key}
                  onClick={() => setTab(t.key)}
                  className={`px-3 py-1.5 rounded-full text-sm font-medium transition-colors ${
                    isCurrent
                      ? 'bg-primary-600 text-white'
                      : 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-600'
                  }`}
                >
                  {t.label}
                  <span
                    aria-hidden="true"
                    className={`ml-1.5 px-1.5 py-0.5 rounded-full text-xs font-semibold ${
                      isCurrent
                        ? 'bg-white/25 text-white'
                        : count > 0
                          ? 'bg-gray-300 dark:bg-slate-600 text-gray-800 dark:text-gray-100'
                          : 'bg-gray-200/70 dark:bg-slate-800 text-gray-400 dark:text-gray-500'
                    }`}
                  >
                    {count}
                  </span>
                  {/* Screen readers get the meaning, not just the digit. */}
                  <span className="sr-only">
                    {' '}
                    ({count} {count === 1 ? 'delegation' : 'delegations'})
                  </span>
                </button>
              );
            })}
          </div>

          {/* One-line digest of the whole register, so the answer to "is anyone
              covering for me right now?" is readable without clicking tabs. */}
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {liveCount} live now · {pendingCount} awaiting approval · {totalCount} total in your
            scope
          </p>
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
                  <td colSpan={8} className="px-4 py-8 text-center">
                    <p className="text-gray-500 dark:text-gray-400">
                      {emptyExplanation(rows, tab)}
                    </p>
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
    </div>
  );
};

export default Delegations;
