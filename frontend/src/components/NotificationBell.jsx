import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, CheckCheck, Loader2 } from 'lucide-react';
import { notificationService } from '../api/services/notificationService';

/** How often the badge re-polls, in ms. */
const POLL_INTERVAL_MS = 60_000;

/**
 * Compact relative time: "just now", "5 minutes ago", "2 days ago", then a date.
 *
 * `Intl.RelativeTimeFormat` is available in every browser this app supports, so
 * no date library is needed for what is only coarse recency.
 */
export const relativeTime = (iso) => {
  if (!iso) return '';
  // The API returns "Y-m-d H:i:s" in UTC with no zone marker, which Safari
  // refuses to parse. Normalise it, and treat it as UTC because that is what
  // the API stores.
  const normalised = /Z|[+-]\d{2}:?\d{2}$/.test(iso) ? iso : `${iso.replace(' ', 'T')}Z`;
  const then = new Date(normalised).getTime();
  if (Number.isNaN(then)) return '';

  const seconds = Math.round((Date.now() - then) / 1000);
  if (seconds < 60) return 'just now';

  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
  if (seconds < 3600) return rtf.format(-Math.floor(seconds / 60), 'minute');
  if (seconds < 86400) return rtf.format(-Math.floor(seconds / 3600), 'hour');
  if (seconds < 604800) return rtf.format(-Math.floor(seconds / 86400), 'day');
  return new Date(normalised).toLocaleDateString();
};

/** Icon tint by notification type, so a warning reads differently to an info. */
export const toneClass = (type) => {
  switch (type) {
    case 'warning':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300';
    case 'success':
      return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300';
    case 'error':
      return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
    default:
      return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300';
  }
};

/**
 * The header bell: unread badge plus a small dropdown.
 *
 * Polls the dedicated /notifications/unread endpoint rather than the full
 * inbox, so a user with thousands of notifications never has the header pull a
 * full page of them every minute. The dropdown fetches only when opened.
 *
 * `enabled` is false when signed out: polling /notifications as an anonymous
 * visitor is a guaranteed 401 and would only generate console noise.
 */
const NotificationBell = ({ enabled = true }) => {
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [marking, setMarking] = useState(false);
  const containerRef = useRef(null);

  const refreshBadge = useCallback(async () => {
    try {
      const res = await notificationService.listUnread(5);
      const data = res?.data ?? {};
      setUnreadCount(Number(data.unread_count ?? 0));
      // Keep the dropdown populated if it happens to be open, so clicking an
      // item never leaves a stale list behind.
      setItems(Array.isArray(data.notifications) ? data.notifications : []);
    } catch {
      // A failed poll must never raise an error banner in the header; the badge
      // simply keeps its last known value.
    }
  }, []);

  // Poll only while signed in, and never after unmount.
  useEffect(() => {
    if (!enabled) {
      setUnreadCount(0);
      setItems([]);
      return undefined;
    }
    refreshBadge();
    const id = setInterval(refreshBadge, POLL_INTERVAL_MS);
    return () => clearInterval(id);
  }, [enabled, refreshBadge]);

  // Close on outside click / Escape. Without this the dropdown traps the user.
  useEffect(() => {
    if (!open) return undefined;
    const onPointerDown = (e) => {
      if (containerRef.current && !containerRef.current.contains(e.target)) setOpen(false);
    };
    const onKeyDown = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  const handleOpen = async () => {
    const next = !open;
    setOpen(next);
    if (!next) return;
    setLoading(true);
    try {
      const res = await notificationService.listUnread(8);
      const data = res?.data ?? {};
      setItems(Array.isArray(data.notifications) ? data.notifications : []);
      setUnreadCount(Number(data.unread_count ?? 0));
    } catch {
      setItems([]);
    } finally {
      setLoading(false);
    }
  };

  const handleItemClick = async (item) => {
    setOpen(false);
    // Optimistic: the badge should react immediately, not after a round trip.
    if (!item.is_read) {
      setUnreadCount((c) => Math.max(0, c - 1));
      setItems((list) => list.filter((i) => i.id !== item.id));
    }
    try {
      await notificationService.markAsRead(item.id);
    } catch {
      // Re-sync rather than leaving a badge that disagrees with the server.
      refreshBadge();
    }
    if (item.action_url) navigate(item.action_url);
  };

  const handleMarkAll = async () => {
    setMarking(true);
    try {
      const res = await notificationService.markAllAsRead();
      const data = res?.data ?? {};
      setUnreadCount(Number(data.unread_count ?? 0));
      setItems([]);
    } catch {
      refreshBadge();
    } finally {
      setMarking(false);
    }
  };

  if (!enabled) return null;

  return (
    <div className="relative" ref={containerRef}>
      <button
        onClick={handleOpen}
        aria-label={`Notifications (${unreadCount} unread)`}
        aria-expanded={open}
        className="relative p-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg transition-colors"
        title="Notifications"
      >
        <Bell className="h-6 w-6" />
        {unreadCount > 0 && (
          <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 bg-red-600 text-white text-[10px] font-semibold rounded-full flex items-center justify-center">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-80 sm:w-96 bg-white dark:bg-slate-800 rounded-md shadow-lg border dark:border-slate-700 z-50 overflow-hidden">
          <div className="flex items-center justify-between px-4 py-2 border-b dark:border-slate-700">
            <span className="text-sm font-medium text-gray-900 dark:text-gray-100">
              Notifications
              {unreadCount > 0 && (
                <span className="ml-2 text-xs text-gray-500 dark:text-gray-400">
                  {unreadCount} unread
                </span>
              )}
            </span>
            {unreadCount > 0 && (
              <button
                onClick={handleMarkAll}
                disabled={marking}
                className="text-xs text-primary-600 dark:text-primary-400 hover:underline disabled:opacity-50 flex items-center gap-1"
              >
                {marking ? (
                  <Loader2 className="h-3 w-3 animate-spin" />
                ) : (
                  <CheckCheck className="h-3 w-3" />
                )}
                Mark all read
              </button>
            )}
          </div>

          <div className="max-h-96 overflow-y-auto">
            {loading ? (
              <div className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                <Loader2 className="h-4 w-4 animate-spin mx-auto mb-2" />
                Loading…
              </div>
            ) : items.length === 0 ? (
              <div className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                You are all caught up.
              </div>
            ) : (
              <ul className="divide-y divide-gray-100 dark:divide-slate-700">
                {items.map((item) => (
                  <li key={item.id}>
                    <button
                      onClick={() => handleItemClick(item)}
                      className="w-full text-left px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                    >
                      <div className="flex items-start gap-3">
                        <span
                          className={`mt-1.5 shrink-0 h-2 w-2 rounded-full ${toneClass(item.type)}`}
                          aria-hidden="true"
                        />
                        <div className="min-w-0 flex-1">
                          <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {item.title}
                          </p>
                          <p className="text-xs text-gray-600 dark:text-gray-300 line-clamp-2 mt-0.5">
                            {item.message}
                          </p>
                          <p className="text-[11px] text-gray-400 dark:text-gray-500 mt-1">
                            {relativeTime(item.created_at)}
                          </p>
                        </div>
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="border-t dark:border-slate-700 px-4 py-2 text-center">
            <button
              onClick={() => {
                setOpen(false);
                navigate('/notifications');
              }}
              className="text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline"
            >
              View all notifications
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default NotificationBell;
