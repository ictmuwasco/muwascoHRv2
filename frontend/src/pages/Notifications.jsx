import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, CheckCheck, ChevronLeft, ChevronRight, Loader2, Inbox } from 'lucide-react';
import { notificationService } from '../api/services/notificationService';
import { relativeTime, toneClass } from '../components/NotificationBell';

const FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'unread', label: 'Unread' },
  { key: 'read', label: 'Read' },
];

/**
 * The full in-app notification inbox.
 *
 * Reads and writes only the signed-in user's own rows: the server scopes every
 * query by user_id, so nothing here can reach another account.
 */
const Notifications = () => {
  const navigate = useNavigate();
  const [items, setItems] = useState([]);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(0);
  const [total, setTotal] = useState(0);
  const [unread, setUnread] = useState(0);
  const [filter, setFilter] = useState('all');
  const [category, setCategory] = useState('');
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(null);

  const load = useCallback(
    async (nextPage = 1) => {
      setLoading(true);
      setError('');
      try {
        const res = await notificationService.list({
          page: nextPage,
          per_page: 15,
          filter,
          category: category || null,
        });
        const d = res?.data ?? {};
        setItems(Array.isArray(d.notifications) ? d.notifications : []);
        setTotal(Number(d.total ?? 0));
        setUnread(Number(d.unread_count ?? 0));
        setPages(Number(d.pages ?? 0));
        setPage(Number(d.page ?? nextPage));
        setCategories(Array.isArray(d.categories) ? d.categories : []);
      } catch (e) {
        setError(e?.response?.data?.message || 'Unable to load notifications.');
      } finally {
        setLoading(false);
      }
    },
    [filter, category],
  );

  // Reload whenever the filters change, always from page 1: staying on page 4
  // after switching to "Unread" would otherwise show an empty list.
  useEffect(() => {
    load(1);
  }, [load]);

  const handleOpen = async (item) => {
    if (!item.is_read) {
      setBusyId(item.id);
      // Optimistic, and rolled back by the re-sync if the call fails.
      setItems((list) => list.map((n) => (n.id === item.id ? { ...n, is_read: 1 } : n)));
      setUnread((c) => Math.max(0, c - 1));
      try {
        await notificationService.markAsRead(item.id);
      } catch {
        await load(page);
      } finally {
        setBusyId(null);
      }
    }
    if (item.action_url) navigate(item.action_url);
  };

  const handleMarkAll = async () => {
    setBusyId('all');
    try {
      await notificationService.markAllAsRead();
      await load(page);
    } catch {
      setError('Unable to mark all as read.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="max-w-4xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
            <Bell className="h-6 w-6" />
            Notifications
          </h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
            {total} notification{total === 1 ? '' : 's'}
            {unread > 0 ? ` \u00b7 ${unread} unread` : ''}
          </p>
        </div>
        {unread > 0 && (
          <button
            onClick={handleMarkAll}
            disabled={busyId !== null}
            className="inline-flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-50 transition-colors"
          >
            {busyId === 'all' ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <CheckCheck className="h-4 w-4" />
            )}
            Mark all read
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-2 mb-4">
        {FILTERS.map((f) => (
          <button
            key={f.key}
            onClick={() => setFilter(f.key)}
            aria-pressed={filter === f.key}
            className={`px-3 py-1.5 text-sm rounded-lg border transition-colors ${filter === f.key ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700'}`}
          >
            {f.label}
          </button>
        ))}

        {categories.length > 1 && (
          <select
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            aria-label="Filter by category"
            className="ml-auto text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-gray-700 dark:text-gray-300"
          >
            <option value="">All categories</option>
            {categories.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        )}
      </div>

      {error && (
        <div className="mb-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-sm text-red-700 dark:text-red-300">
          {error}
        </div>
      )}

      <div className="bg-white dark:bg-slate-800 rounded-lg border dark:border-slate-700 overflow-hidden">
        {loading ? (
          <div className="py-16 text-center text-sm text-gray-500 dark:text-gray-400">
            <Loader2 className="h-5 w-5 animate-spin mx-auto mb-2" />
            Loading notifications...
          </div>
        ) : items.length === 0 ? (
          <div className="py-16 text-center text-gray-500 dark:text-gray-400">
            <Inbox className="h-8 w-8 mx-auto mb-3 opacity-50" />
            <p className="text-sm">No notifications to show.</p>
            {filter !== 'all' && (
              <button
                onClick={() => {
                  setFilter('all');
                  setCategory('');
                }}
                className="mt-3 text-sm text-primary-600 dark:text-primary-400 hover:underline"
              >
                Clear filters
              </button>
            )}
          </div>
        ) : (
          <ul className="divide-y divide-gray-100 dark:divide-slate-700">
            {items.map((item) => (
              <li key={item.id}>
                <button
                  onClick={() => handleOpen(item)}
                  disabled={busyId === item.id}
                  className={`w-full text-left px-4 py-3.5 flex items-start gap-3 transition-colors hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-60 ${!item.is_read ? 'bg-primary-50/40 dark:bg-primary-900/10' : ''}`}
                >
                  <span
                    className={`mt-1.5 shrink-0 h-2.5 w-2.5 rounded-full ${toneClass(item.type)}`}
                    aria-hidden="true"
                  />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-3">
                      <p
                        className={`text-sm truncate ${item.is_read ? 'font-normal text-gray-700 dark:text-gray-300' : 'font-semibold text-gray-900 dark:text-gray-100'}`}
                      >
                        {item.title}
                        {!item.is_read && <span className="sr-only"> (unread)</span>}
                      </p>
                      <span className="shrink-0 text-[11px] text-gray-400 dark:text-gray-500 whitespace-nowrap">
                        {relativeTime(item.created_at)}
                      </span>
                    </div>
                    <p className="text-sm text-gray-600 dark:text-gray-300 mt-1 whitespace-pre-line">
                      {item.message}
                    </p>
                    {item.category && (
                      <span className="inline-block mt-2 text-[11px] px-2 py-0.5 rounded-full bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300">
                        {item.category}
                      </span>
                    )}
                  </div>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {pages > 1 && (
        <div className="flex items-center justify-between mt-4">
          <button
            onClick={() => load(page - 1)}
            disabled={page <= 1 || loading}
            className="inline-flex items-center gap-1 text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-40 transition-colors"
          >
            <ChevronLeft className="h-4 w-4" />
            Previous
          </button>
          <span className="text-sm text-gray-500 dark:text-gray-400">
            Page {page} of {pages}
          </span>
          <button
            onClick={() => load(page + 1)}
            disabled={page >= pages || loading}
            className="inline-flex items-center gap-1 text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-40 transition-colors"
          >
            Next
            <ChevronRight className="h-4 w-4" />
          </button>
        </div>
      )}
    </div>
  );
};

export default Notifications;
