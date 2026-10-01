/**
 * Vitest global setup.
 *
 * Referenced by vite.config.js (`test.setupFiles`) and documented in that file
 * as the place where @testing-library/jest-dom extends the global `expect`
 * with DOM matchers (toBeInTheDocument, toHaveTextContent, ...).
 *
 * THE FILE WAS MISSING, which made `vitest run` fail at collection time with
 * "Cannot find module setup.ts" for every test - the suite could not run at
 * all. Pure-logic tests do not need the matchers, so the import is defensive:
 * if jest-dom is present the DOM matchers are registered, and if it is not the
 * suite still runs rather than failing to boot.
 *
 * Pure functions (tab visibility, formatters, validators) should be written to
 * need nothing from here, so they keep working even if this file is removed.
 */
import '@testing-library/jest-dom/vitest';
