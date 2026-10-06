/**
 * Tab-visibility rules for the employee profile.
 *
 * SEPARATE MODULE ON PURPOSE.
 *
 * This rule originally lived inline in EmployeeProfile.jsx as a `useMemo` whose
 * result was consumed by a `useEffect` dependency array declared ABOVE it. That
 * is a temporal dead zone error:
 *
 *   ReferenceError: Cannot access 'tabs' before initialization
 *
 * A hook's dependency array is evaluated DURING render, at the point the call is
 * reached, while the binding further down the function body is still
 * uninitialised. The build succeeds and the page fails on load, which is the
 * worst possible shape for a bug - and neither `vite build` nor ESLint caught it,
 * because the ordering is only wrong at runtime.
 *
 * Extracting the rule here means it can be unit tested directly - no component
 * mount, no ordering hazard - and the component consumes a plain import.
 */

/** What the server reports for a document list, in `documents_access`. */
export const DOCUMENT_ACCESS_STATES = Object.freeze({
  NONE: 'none',
  OWNER: 'owner',
  GRANTED: 'granted',
  LOCKED: 'locked',
});

/**
 * Is the Documents tab available to this viewer?
 *
 * `none` counts as visible: an employee with no documents has nothing to gate,
 * and a tab that only ever renders an empty state is noise.
 *
 * `locked` does NOT. The entry is removed from the nav entirely rather than
 * shown disabled, because a present-but-un-clickable tab still tells the viewer
 * that documents exist - which is the very thing being protected.
 *
 * @param {string} documentsAccess
 * @returns {boolean}
 */
export const isDocumentsTabVisible = (documentsAccess) =>
  documentsAccess === DOCUMENT_ACCESS_STATES.OWNER ||
  documentsAccess === DOCUMENT_ACCESS_STATES.GRANTED ||
  documentsAccess === DOCUMENT_ACCESS_STATES.NONE;

/**
 * Filter a tab list to those this viewer may open. Only `documents` is gated.
 *
 * @param {Array<{id:string}>} tabs
 * @param {string} documentsAccess
 * @returns {Array<{id:string}>}
 */
export const filterVisibleTabs = (tabs, documentsAccess) => {
  const visible = isDocumentsTabVisible(documentsAccess);
  return tabs.filter((tab) => tab.id !== 'documents' || visible);
};
