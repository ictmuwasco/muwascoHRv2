import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Bell,
  CalendarCheck,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  FileText,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import api from '../utils/api';
import { notificationService } from '../api/services/notificationService';
import { useAuth } from '../context/AuthContext';

/** How long one summary stays on screen before sliding to the next (ms). */
const SLIDE_INTERVAL_MS = 5000;

/** How often the underlying counts re-poll (ms) - matches the header bell. */
const REFRESH_INTERVAL_MS = 60_000;

/** Unread page size; category tallies are only trusted when one page holds it all. */
const UNREAD_PAGE_SIZE = 50;

type Tone = 'approvals' | 'meetings' | 'inbox' | 'category' | 'clear';

interface Slide {
  id: string;
  tone: Tone;
  /** Big attention-grabbing number; null for the all-clear slide. */
  count: number | null;
  headline: string;
  detail: string;
  /** Where a click on the slide takes the user. */
  target: string;
}

interface CategorySummary {
  category: string;
  count: number;
}

export interface NotificationSlideshowProps {
  /**
   * Leave requests awaiting the signed-in user's approval. The Dashboard
   * passes the org-wide figure for HR holders and the personal scoped queue
   * for other approvers; 0 hides the slide.
   */
  pendingLeaveApprovals?: number;
}

/** One gradient family per topic so the slide colour itself signals urgency. */
const TONE_CLASSES: Record<Tone, string> = {
  approvals: 'from-amber-500 to-orange-600',
  meetings: 'from-blue-500 to-indigo-600',
  inbox: 'from-indigo-500 to-violet-600',
  category: 'from-teal-500 to-emerald-600',
  clear: 'from-emerald-500 to-teal-600',
};

const TONE_ICONS: Record<Tone, LucideIcon> = {
  approvals: FileText,
  meetings: CalendarCheck,
  inbox: Bell,
  category: Bell,
  clear: CheckCircle2,
};

/** "delegate_assignment" -> "delegate assignment". */
const humaniseCategory = (raw: string): string => raw.replace(/[_-]+/g, ' ').trim();

/**
 * Dashboard notification slideshow.
 *
 * A small auto-rotating banner that sits directly above the attendance card
 * and turns everything the app knows about the signed-in user into one-line,
 * count-first summaries ("30 pending leave requests awaiting your approval",
 * "1 meeting invitation awaiting your response", "5 unread notifications").
 * Clicking a slide lands on the page that owns the summary.
 */
const NotificationSlideshow = ({ pendingLeaveApprovals = 0 }: NotificationSlideshowProps) => {
  const navigate = useNavigate();
  const { can } = useAuth();

  const [unreadCount, setUnreadCount] = useState(0);
  const [categorySummaries, setCategorySummaries] = useState<CategorySummary[]>([]);
  const [pendingMeetings, setPendingMeetings] = useState(0);
  const [loading, setLoading] = useState(true);
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const [reduceMotion, setReduceMotion] = useState(false);

  const canViewMeetings = can('meetings', 'view');

  // Honour the OS "reduce motion" preference: no slide-in animation and no
  // auto-advance, so the banner stays still until the user acts. jsdom (and
  // very old browsers) have no matchMedia - degrade to normal motion.
  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return undefined;
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    const apply = () => setReduceMotion(mq.matches);
    apply();
    mq.addEventListener('change', apply);
    return () => mq.removeEventListener('change', apply);
  }, []);
  const refresh = useCallback(async () => {
    // 1) Inbox - one unread page carries the total (unread_count) and, when
    //    every unread row fits on that page, exact per-category counts too.
    //    A failed poll keeps the previous numbers rather than blanking.
    try {
      const res = await notificationService.list({
        filter: 'unread',
        page: 1,
        per_page: UNREAD_PAGE_SIZE,
      });
      const payload = res?.data;
      if (payload) {
        setUnreadCount(Number(payload.unread_count ?? 0));
        if ((payload.pages ?? 0) <= 1) {
          const tally = new Map<string, number>();
          for (const item of payload.notifications ?? []) {
            const cat = (item.category ?? '').trim();
            if (cat) tally.set(cat, (tally.get(cat) ?? 0) + 1);
          }
          setCategorySummaries(
            [...tally.entries()]
              .map(([category, count]) => ({ category, count }))
              .sort((a, b) => b.count - a.count)
              .slice(0, 3),
          );
        } else {
          // Too many unread to tally cheaply - the total slide still shows.
          setCategorySummaries([]);
        }
      }
    } catch {
      // Signed out / offline: keep whatever we had.
    }

    // 2) Meeting invitations - mirrors the PendingMeetingsCard rule exactly:
    //    still awaiting my reply and not already in the past.
    if (canViewMeetings) {
      try {
        const response = await api.get('/my-meetings');
        const list = Array.isArray(response.data?.data) ? (response.data.data as any[]) : [];
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const pending = list.filter((m) => {
          if ((m?.invitation?.response_status || 'pending') !== 'pending') return false;
          const dateStr = m?.meeting_date;
          if (!dateStr) return true;
          const d = new Date(dateStr);
          return Number.isNaN(d.getTime()) || d >= today;
        });
        setPendingMeetings(pending.length);
      } catch {
        // No meetings:view on the API or offline - hide the slide instead.
      }
    }

    setLoading(false);
  }, [canViewMeetings]);

  useEffect(() => {
    refresh();
    const id = window.setInterval(refresh, REFRESH_INTERVAL_MS);
    return () => window.clearInterval(id);
  }, [refresh]);

  const slides = useMemo<Slide[]>(() => {
    const out: Slide[] = [];

    if (pendingLeaveApprovals > 0) {
      out.push({
        id: 'leave-approvals',
        tone: 'approvals',
        count: pendingLeaveApprovals,
        headline: `Pending leave request${pendingLeaveApprovals === 1 ? '' : 's'} awaiting your approval`,
        detail: 'Open Manage Leave to review the queue.',
        target: '/leave/manage/pending',
      });
    }

    if (pendingMeetings > 0) {
      out.push({
        id: 'meeting-invites',
        tone: 'meetings',
        count: pendingMeetings,
        headline: `Meeting invitation${pendingMeetings === 1 ? '' : 's'} awaiting your response`,
        detail: 'Let the organizer know whether you can attend.',
        target: '/my-meetings?tab=scheduled',
      });
    }

    if (unreadCount > 0) {
      out.push({
        id: 'unread-total',
        tone: 'inbox',
        count: unreadCount,
        headline: `Unread notification${unreadCount === 1 ? '' : 's'} in your inbox`,
        detail: 'Your in-app inbox has new messages for you.',
        target: '/notifications',
      });
    }

    for (const { category, count } of categorySummaries) {
      out.push({
        id: `cat:${category}`,
        tone: 'category',
        count,
        headline: `Unread ${humaniseCategory(category)} notification${count === 1 ? '' : 's'}`,
        detail: 'Open your inbox to catch up.',
        target: '/notifications',
      });
    }

    if (out.length === 0 && !loading) {
      out.push({
        id: 'all-clear',
        tone: 'clear',
        count: null,
        headline: "You're all caught up",
        detail: 'No pending approvals, invitations or unread notifications right now.',
        target: '/notifications',
      });
    }

    return out;
  }, [pendingLeaveApprovals, pendingMeetings, unreadCount, categorySummaries, loading]);

  // Auto-advance; paused while hovered/focused, disabled under reduced motion.
  useEffect(() => {
    if (paused || reduceMotion || slides.length <= 1) return undefined;
    const timer = window.setTimeout(
      () => setIndex((i) => (i + 1) % slides.length),
      SLIDE_INTERVAL_MS,
    );
    return () => window.clearTimeout(timer);
  }, [paused, reduceMotion, slides.length, index]);

  // The list can shrink (e.g. an approval completed in another tab); clamp
  // instead of pointing past the end.
  const active = slides.length > 0 ? slides[index % slides.length] : undefined;

  const goTo = (next: number) => {
    if (slides.length === 0) return;
    setIndex((next + slides.length) % slides.length);
  };

  // First paint: reserve the banner's height with a skeleton so the page does
  // not jump when the counts arrive.
  if (loading && slides.length === 0) {
    return (
      <div
        className="h-[84px] animate-pulse rounded-xl bg-gray-100 dark:bg-slate-800"
        aria-hidden="true"
      />
    );
  }

  if (!active) return null;

  const Icon = TONE_ICONS[active.tone];
  const isClear = active.tone === 'clear';
  const current = index % slides.length;

  return (
    <section
      aria-label="Notification summary slideshow"
      className={`group relative overflow-hidden rounded-xl bg-gradient-to-r text-white shadow-lg ring-1 ring-black/10 ${TONE_CLASSES[active.tone]}`}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
    >
      {/* Slide body - remounted per slide (key) so the right-to-left entrance
          animation replays on every rotation. Click opens the summary's page. */}
      <div
        key={active.id}
        role="button"
        tabIndex={0}
        aria-label={`${active.headline}. Open details.`}
        onClick={() => navigate(active.target)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            navigate(active.target);
          }
        }}
        className={`flex w-full cursor-pointer items-center gap-4 px-4 py-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white/80 sm:px-5 ${reduceMotion ? '' : 'notification-slide-in'}`}
      >
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/20 ring-1 ring-white/40">
          <Icon className="h-5 w-5" aria-hidden="true" />
        </span>

        <div className="min-w-0 flex-1">
          <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-white/85">
            <span
              className="inline-block h-1.5 w-1.5 rounded-full bg-white motion-safe:animate-pulse"
              aria-hidden="true"
            />
            {isClear ? 'All clear' : 'Needs your attention'}
          </p>
          <p className="mt-0.5 flex items-baseline gap-2">
            {active.count !== null && (
              <span className="text-2xl font-extrabold leading-none">{active.count}</span>
            )}
            <span className="truncate text-sm font-semibold sm:text-base">{active.headline}</span>
          </p>
          <p className="truncate text-xs text-white/85">{active.detail}</p>
        </div>

        <ChevronRight className="hidden h-5 w-5 shrink-0 opacity-80 sm:block" aria-hidden="true" />
      </div>

      {/* Manual controls - revealed on hover / keyboard focus so the automatic
          rotation can be taken over at any time. */}
      {slides.length > 1 && (
        <>
          <button
            type="button"
            aria-label="Previous summary"
            onClick={(e) => {
              e.stopPropagation();
              goTo(current - 1);
            }}
            className="absolute left-2 top-1/2 hidden -translate-y-1/2 rounded-full bg-white/20 p-1 opacity-0 transition hover:bg-white/40 focus-visible:opacity-100 focus-visible:outline-none group-hover:opacity-100 md:block"
          >
            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
          </button>
          <button
            type="button"
            aria-label="Next summary"
            onClick={(e) => {
              e.stopPropagation();
              goTo(current + 1);
            }}
            className="absolute right-2 top-1/2 hidden -translate-y-1/2 rounded-full bg-white/20 p-1 opacity-0 transition hover:bg-white/40 focus-visible:opacity-100 focus-visible:outline-none group-hover:opacity-100 md:block"
          >
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </button>
        </>
      )}

      {/* Dots - quick jump plus a visual hint that the banner rotates. */}
      {slides.length > 1 && (
        <div className="absolute right-3 top-2 flex gap-1.5">
          {slides.map((s, i) => (
            <button
              key={s.id}
              type="button"
              aria-label={`Go to summary ${i + 1}: ${s.headline}`}
              aria-current={i === current}
              onClick={(e) => {
                e.stopPropagation();
                goTo(i);
              }}
              className={`h-2 w-2 rounded-full transition ${
                i === current ? 'bg-white' : 'bg-white/40 hover:bg-white/70'
              }`}
            />
          ))}
        </div>
      )}

      {/* Auto-advance progress along the bottom edge; freezes with the slide.
          Hidden entirely under reduced motion, where nothing auto-advances. */}
      {slides.length > 1 && !reduceMotion && (
        <div className="absolute inset-x-0 bottom-0 h-1 bg-white/25" aria-hidden="true">
          <div
            key={active.id}
            className="notification-progress h-full bg-white/90"
            style={{
              animationDuration: `${SLIDE_INTERVAL_MS}ms`,
              animationPlayState: paused ? 'paused' : 'running',
            }}
          />
        </div>
      )}
    </section>
  );
};

export default NotificationSlideshow;
