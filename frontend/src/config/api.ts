/**
 * config/api.ts — single source of truth for client-side API configuration.
 *
 * Vite exposes VITE_* variables to the bundle at build time (see .env.example).
 * Every consumer that needs the API base URL — the axios client
 * (api/client.ts), the fetch wrapper (utils/api.js), the client-error
 * collector (utils/errorReporting.ts) and any component building a direct
 * asset URL (avatars, profile images) — MUST import from here instead of
 * re-reading import.meta.env, so a production deployment repoints the whole
 * SPA with a single environment variable.
 */

/** Base URL of the HR API. '/api' in dev (Vite proxy) or an absolute URL in prod. */
export const API_BASE_URL: string = import.meta.env.VITE_API_URL || '/api';

/** Deployed frontend version, shipped with every client-error report. */
export const APP_VERSION: string = import.meta.env.VITE_APP_VERSION || '1.0.0';