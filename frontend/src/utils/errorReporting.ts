/**
 * errorReporting.ts - Centralized FRONTEND observability client.
 *
 * Responsibilities:
 *  - Mint/carry the correlation id (X-Request-ID) shared with the backend
 *  - Collect sanitized device/browser/route context
 *  - Ship errors (React crashes, API failures, network issues) to
 *    POST /api/system/client-errors with rate limiting + de-duplication
 *
 * Deliberately dependency-free (uses fetch, NOT the axios apiClient) so that
 * reporting can never recurse through request interceptors. The only import
 * is the pure config constant module — zero runtime dependencies, no
 * interceptors, so the no-recursion guarantee still holds.
 */

import { API_BASE_URL } from '../config/api';

// ---------------------------------------------------------------------------
// Correlation id
// ---------------------------------------------------------------------------

/** Crockford-ish base32 random segment (matches backend's req_ family). */
const B32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function generateRequestId(): string {
  let rand = '';
  const bytes = new Uint8Array(10);
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
  }
  bytes.forEach((b) => { rand += B32[b % 32]; });
  return `req_${Date.now().toString(36).toUpperCase()}${rand}`;
}

let currentRequestId: string = generateRequestId();

/** Id minted for this SPA session; overwritten by the server's authoritative id on every response. */
export function getRequestId(): string {
  return currentRequestId;
}

/** Adopt the server's X-Request-ID so subsequent reports share one trace.
 *  Normalized to uppercase, mirroring backend storage format. */
export function setRequestId(id: string | null | undefined): void {
  if (!id) return;
  const upper = id.toUpperCase();
  if (/^REQ_[0-9A-HJ-NP-TV-Z]{10,32}$/.test(upper)) {
    currentRequestId = upper;
  }
}

/** Browser-visible unique id for a single client error report. */
export function newFrontendErrorId(): string {
  return `FE-${new Date().toISOString().slice(0, 10).replace(/-/g, '')}-${Math.random()
    .toString(36)
    .slice(2, 8)
    .toUpperCase()}`;
}

// ---------------------------------------------------------------------------
// Device / environment context
// ---------------------------------------------------------------------------

interface ParsedUa {
  browser: string;
  browser_version: string;
  operating_system: string;
  device_type: string;
}

function parseUserAgent(ua: string): ParsedUa {
  const pick = (re: RegExp): string => ua.match(re)?.[1] ?? '';
  let browser = 'Other';
  let version = '';

  if (/Edg\//.test(ua))          { browser = 'Edge';    version = pick(/Edg\/([\d.]+)/); }
  else if (/OPR\//.test(ua))     { browser = 'Opera';   version = pick(/OPR\/([\d.]+)/); }
  else if (/Chrome\//.test(ua))  { browser = 'Chrome';  version = pick(/Chrome\/([\d.]+)/); }
  else if (/Firefox\//.test(ua)) { browser = 'Firefox'; version = pick(/Firefox\/([\d.]+)/); }
  else if (/Safari\//.test(ua))  { browser = 'Safari';  version = pick(/Version\/([\d.]+)/); }

  let os = 'Unknown OS';
  if (/Windows NT/.test(ua))            os = 'Windows';
  else if (/Mac OS X/.test(ua))         os = 'macOS';
  else if (/Android/.test(ua))          os = 'Android';
  else if (/iPhone|iPad|iPod/.test(ua)) os = 'iOS';
  else if (/Linux/.test(ua))            os = 'Linux';

  const deviceType =
    /Mobi/.test(ua) ? 'mobile'
      : /Tablet|iPad/.test(ua) ? 'tablet'
        : 'desktop';

  return { browser, browser_version: version.split('.')[0] ?? '', operating_system: os, device_type: deviceType };
}

function currentUserId(): number | null {
  try {
    const raw = localStorage.getItem('user');
    const user = raw ? JSON.parse(raw) : null;
    const id = user?.id ?? user?.user?.id ?? null;
    return typeof id === 'number' ? id : null;
  } catch {
    return null;
  }
}

export function collectContext(): Record<string, unknown> {
  const ua = typeof navigator !== 'undefined' ? navigator.userAgent : '';
  const parsed = parseUserAgent(ua);

  return {
    request_id: currentRequestId,
    frontend_error_id: newFrontendErrorId(),
    url: typeof location !== 'undefined' ? location.href : '',
    route: typeof location !== 'undefined' ? location.pathname : '',
    screen_size: typeof screen !== 'undefined' ? `${screen.width}x${screen.height}` : '',
    application_version: import.meta.env.VITE_APP_VERSION || '1.0.0',
    user_id: currentUserId(),
    timestamp: new Date().toISOString(),
    ...parsed,
  };
}

// ---------------------------------------------------------------------------
// Reporting pipeline
// ---------------------------------------------------------------------------

export type ErrorKind =
  | 'react'            // component crash caught by an ErrorBoundary
  | 'api'              // HTTP >= 500 from our own API
  | 'network'          // request never reached the server / aborted
  | 'invalid_response' // malformed JSON envelope
  | 'dynamic_import'
  | 'unhandled_rejection'
  | 'uncaught'
  | 'push';            // Web Push failures

export interface ReportInput {
  kind: ErrorKind;
  message: string;
  stack?: string;
  component?: string;
  /** API failures: which endpoint + status */
  endpoint?: string;
  status_code?: number;
  severity?: 'LOW' | 'MEDIUM' | 'HIGH' | 'CRITICAL';
  extra?: Record<string, unknown>;
}

const MAX_REPORTS_PER_MINUTE = 20;
const DEDUPE_WINDOW_MS = 15_000;

const recentSignatures = new Map<string, number>();
let reportsThisMinute = 0;
let minuteWindowStart = 0;

function shouldSend(signature: string): boolean {
  const now = Date.now();

  // Rolling minute quota.
  if (now - minuteWindowStart > 60_000) {
    minuteWindowStart = now;
    reportsThisMinute = 0;
  }
  if (reportsThisMinute >= MAX_REPORTS_PER_MINUTE) return false;

  // Identical error inside the dedupe window is swallowed.
  const last = recentSignatures.get(signature);
  if (last && now - last < DEDUPE_WINDOW_MS) return false;

  recentSignatures.set(signature, now);
  if (recentSignatures.size > 100) {
    const oldest = recentSignatures.keys().next().value as string | undefined;
    if (oldest !== undefined) recentSignatures.delete(oldest);
  }
  reportsThisMinute += 1;
  return true;
}

/** Strip anything credential-shaped before it leaves the browser. */
export function scrubStack(stack: string): string {
  return stack
    .replace(/(Bearer\s+)[A-Za-z0-9\-_.~+/=]+/gi, '$1[REDACTED]')
    .replace(/((?:password|token|secret|api[_-]?key)=)[^&\s]+/gi, '$1[REDACTED]')
    .slice(0, 8000);
}

/**
 * Fire-and-forget reporter. Never throws, never blocks UX, never recurses.
 */
export function reportClientError(input: ReportInput): void {
  try {
    const signature = `${input.kind}|${String(input.message).slice(0, 120)}|${
      String(input.stack ?? '').split('\n')[1]?.slice(0, 80) ?? ''
    }`;
    if (!shouldSend(signature)) return;

    const body = {
      ...collectContext(),
      kind: input.kind,
      message: String(input.message ?? 'Unknown client error').slice(0, 1000),
      stack: input.stack ? scrubStack(input.stack) : undefined,
      component: input.component,
      severity: input.severity ?? (input.kind === 'api' || input.kind === 'react' ? 'HIGH' : 'MEDIUM'),
      ...(input.endpoint ? { endpoint_path: input.endpoint } : {}),
      ...(input.status_code ? { status_code: input.status_code } : {}),
      ...input.extra,
    };

    fetch(`${API_BASE_URL}/system/client-errors`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-Request-ID': currentRequestId },
      body: JSON.stringify(body),
      keepalive: true,
    })
      .then((res) => {
        // Adopt the authoritative correlation id even from the collector.
        setRequestId(res.headers.get('X-Request-ID'));
      })
      .catch(() => {
        /* collector unavailable - silently ignore */
      });
  } catch {
    /* reporting must NEVER throw */
  }
}

/** User-facing copy per §33 - no technical details, always a reference. */
export function friendlyError(referenceId?: string): {
  title: string;
  hint: string;
  reference: string;
} {
  return {
    title: 'Something went wrong.',
    hint: 'Please try again or contact HR support if the problem continues.',
    reference: referenceId ?? currentRequestId,
  };
}
