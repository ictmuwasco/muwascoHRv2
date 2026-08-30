import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import ErrorBoundary from '../../components/ErrorBoundary';

function Bomb() {
  throw new Error('Kaboom-Render-Crash');
}

/*
 * NOTE: This suite originally intercepted utils/errorReporting with vi.mock.
 * vi.mock factories cannot intercept TypeScript modules under this repo's
 * toolchain (see Reports.test.jsx for details), so instead we observe the
 * reporter from the outside: reportClientError POSTs to
 * /api/system/client-errors via global fetch, which we stub below and
 * inspect in assertions. The internal rate-limiter/dedupe of the real
 * reporter means only the FIRST crash produces a call per window, which is
 * why crash-count assertions live only in a single test.
 */
describe('<ErrorBoundary>', () => {
  let fetchMock;

  beforeEach(() => {
    fetchMock = vi.fn(() =>
      Promise.resolve({ ok: true, status: 204, headers: { get: () => null } })
    );
    globalThis.fetch = fetchMock;
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  /** Parsed request bodies sent to the client-error collector. */
  const collectorBodies = () =>
    fetchMock.mock.calls
      .filter(([url]) => String(url).includes('/system/client-errors'))
      .map(([, opts]) => JSON.parse((opts && opts.body) || '{}'));

  it('renders children normally when nothing crashes', () => {
    render(
      <ErrorBoundary>
        <div>All systems operational</div>
      </ErrorBoundary>
    );
    expect(screen.getByText('All systems operational')).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('shows the friendly reference screen and reports a crash instead of a white page', () => {
    render(
      <ErrorBoundary>
        <Bomb />
      </ErrorBoundary>
    );

    // §33 user experience: no technical details, always a reference code.
    expect(screen.getByText(/Something went wrong/i)).toBeInTheDocument();
    expect(screen.getByText(/contact HR support/i)).toBeInTheDocument();
    expect(screen.getByText(/Reference:/i)).toBeInTheDocument();

    // Observability hook fired exactly once with the react crash details.
    const bodies = collectorBodies();
    expect(bodies.length).toBe(1);
    expect(bodies[0].kind).toBe('react');
    expect(String(bodies[0].message)).toContain('Kaboom-Render-Crash');
    expect(bodies[0].severity).toBe('HIGH');

    // Stack trace must NOT be shown to the end user.
    expect(screen.queryByText(/componentDidCatch|Bomb/)).not.toBeInTheDocument();
  });

  it('offers a reload action', () => {
    render(
      <ErrorBoundary>
        <Bomb />
      </ErrorBoundary>
    );
    expect(screen.getByRole('button', { name: /reload application/i })).toBeInTheDocument();
  });
});