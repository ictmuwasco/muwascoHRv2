import React from 'react';
import Card from './ui/Card';
import Button from './ui/Button';
import { reportClientError, friendlyError } from '../utils/errorReporting';

/**
 * ErrorBoundary - global React crash catcher.
 *
 * Renders a friendly, reference-coded fallback (§33) instead of a white
 * screen, and ships the crash to the observability backend. Technical details
 * are NEVER shown to employees; only support/developers see them (dashboard).
 */
class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
    this.handleReload = this.handleReload.bind(this);
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    reportClientError({
      kind: 'react',
      message: error?.message || String(error),
      stack: error?.stack,
      component: info?.componentStack ? String(info.componentStack).slice(0, 2000) : undefined,
      severity: 'HIGH',
    });
  }

  handleReload() {
    window.location.reload();
  }

  render() {
    if (this.state.error) {
      const friendly = friendlyError();
      return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
          <Card className="max-w-md w-full text-center space-y-4">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
              <svg className="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.5 0L3.16 16.25A2 2 0 005 19z" />
              </svg>
            </div>
            <h1 className="text-lg font-semibold text-gray-900">{friendly.title}</h1>
            <p className="text-sm text-gray-500">{friendly.hint}</p>
            <p className="text-xs text-gray-400">
              Reference: <code className="font-mono bg-gray-100 px-1.5 py-0.5 rounded">{friendly.reference}</code>
            </p>
            <Button onClick={this.handleReload} className="w-full">
              Reload Application
            </Button>
          </Card>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
