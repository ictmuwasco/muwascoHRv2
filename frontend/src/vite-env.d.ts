/// <reference types="vite/client" />

/**
 * Vite environment variable typing (Sprint 1 — Foundation).
 *
 * All VITE_* variables MUST be declared on ImportMetaEnv here so that
 * `import.meta.env` is fully typed across every .ts/.tsx file. This replaces
 * the old per-file `declare global { interface ImportMeta ... }` hacks
 * (previously inlined in api/client.ts) and the `(import.meta as any)`
 * casts (previously in utils/errorReporting.ts).
 *
 * When adding a new variable: declare it in .env.example AND here.
 */
interface ImportMetaEnv {
  /** Base URL of the HR API — '/api' in dev (Vite proxy), absolute URL in prod. */
  readonly VITE_API_URL?: string;
  /** Deployed frontend version, shipped with client-error reports. */
  readonly VITE_APP_VERSION?: string;
}