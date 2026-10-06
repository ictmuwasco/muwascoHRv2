import { describe, it, expect } from 'vitest';
import {
  DOCUMENT_ACCESS_STATES,
  isDocumentsTabVisible,
  filterVisibleTabs,
} from '../pages/employee/visibleTabs';

const TABS = [
  { id: 'details' },
  { id: 'contracts' },
  { id: 'documents' },
  { id: 'nextofkin' },
  { id: 'dependants' },
];

const ids = (tabs) => tabs.map((t) => t.id);

describe('isDocumentsTabVisible', () => {
  it('hides the tab while the list is locked', () => {
    // The whole point of the control: a locked viewer must not be able to see
    // that a tab exists, let alone click it.
    expect(isDocumentsTabVisible(DOCUMENT_ACCESS_STATES.LOCKED)).toBe(false);
  });

  it('shows the tab once access is granted', () => {
    expect(isDocumentsTabVisible(DOCUMENT_ACCESS_STATES.GRANTED)).toBe(true);
  });

  it('shows the tab for the owner, who is never gated', () => {
    expect(isDocumentsTabVisible(DOCUMENT_ACCESS_STATES.OWNER)).toBe(true);
  });

  it('shows the tab when the employee has no documents', () => {
    // Nothing to protect, and a tab that only ever renders an empty state is
    // noise. Treating `none` as hidden would make "no documents" look identical
    // to "documents exist but you may not see them".
    expect(isDocumentsTabVisible(DOCUMENT_ACCESS_STATES.NONE)).toBe(true);
  });

  it('defaults to hidden for an unrecognised state', () => {
    // Fail closed. An unexpected value from a newer server must not silently
    // expose the tab.
    expect(isDocumentsTabVisible(undefined)).toBe(false);
    expect(isDocumentsTabVisible('something-else')).toBe(false);
    expect(isDocumentsTabVisible('')).toBe(false);
  });
});

describe('filterVisibleTabs', () => {
  it('removes documents when locked and keeps everything else', () => {
    const result = ids(filterVisibleTabs(TABS, DOCUMENT_ACCESS_STATES.LOCKED));

    expect(result).not.toContain('documents');
    expect(result).toEqual(['details', 'contracts', 'nextofkin', 'dependants']);
  });

  it('keeps the full list when granted', () => {
    const result = ids(filterVisibleTabs(TABS, DOCUMENT_ACCESS_STATES.GRANTED));
    expect(result).toContain('documents');
    expect(result).toHaveLength(TABS.length);
  });

  it('keeps the full list for the owner', () => {
    const result = ids(filterVisibleTabs(TABS, DOCUMENT_ACCESS_STATES.OWNER));
    expect(result).toHaveLength(TABS.length);
  });

  it('never gates the non-document tabs', () => {
    // Whatever the state, details/contracts/nextofkin/dependants stay reachable.
    for (const state of Object.values(DOCUMENT_ACCESS_STATES)) {
      const result = ids(filterVisibleTabs(TABS, state));
      expect(result).toContain('details');
      expect(result).toContain('contracts');
      expect(result).toContain('nextofkin');
      expect(result).toContain('dependants');
    }
  });

  it('preserves the original order', () => {
    const result = ids(filterVisibleTabs(TABS, DOCUMENT_ACCESS_STATES.OWNER));
    expect(result).toEqual(['details', 'contracts', 'documents', 'nextofkin', 'dependants']);
  });

  it('does not mutate the input array', () => {
    const input = [...TABS];
    filterVisibleTabs(input, DOCUMENT_ACCESS_STATES.LOCKED);
    expect(input).toHaveLength(TABS.length);
  });

  it('tolerates an empty tab list', () => {
    expect(filterVisibleTabs([], DOCUMENT_ACCESS_STATES.LOCKED)).toEqual([]);
  });
});
