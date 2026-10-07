import { useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import AccessDenied from './AccessDenied';
import { firstPermittedRoute, parsePermission } from '../config/pagePermissions';
import { getCachedConsent, getConsentStatus } from '../api/services/consentService';

/**
 * Route guard (Phase 2 Section 12, hardened by the Role/Page/Permission
 * restriction phase).
 *
 * Properties:
 *  - `permission` (optional) - "module:action" string, normally supplied from
 *    the central Page Permission Registry (config/pagePermissions.jsx). When
 *    provided and the authenticated user's effective permission set (from
 *    /auth/user) does not include it, the AccessDenied screen is rendered
 *    instead of the page - no silent redirect loops (Section 20).
 *  - `fallbackPermission` (optional) - when provided INSTEAD of `permission`,
 *    a denied user is redirected to the first route they ARE permitted to
 *    open (used for wrapper layouts).
 *
 * SECURITY MODEL: this is UX/DX convenience ONLY. The backend enforces
 * authorization independently on every API request, so a user who navigates
 * here directly while lacking the permission still cannot fetch data. This
 * guard prevents rendering pages the user cannot use and gives clear
 * feedback instead of an empty or erroring screen.
 */
const ProtectedRoute = ({ children, permission, fallbackPermission, skipConsentCheck = false }) => {
  const { user, isAuthenticated, loading: authLoading, can } = useAuth();
  const location = useLocation();
  const [consentState, setConsentState] = useState('checking'); // checking | ok | missing

  // Consent gate (login → consent → dashboard):
  // every authenticated page EXCEPT /data-protection-consent itself requires
  // an accepted Data Protection Notice. Order of checks avoids the known
  // session-cookie race:
  //   1. user.consent_accepted from the login/me payload (no extra request)
  //   2. localStorage consent cache (survives logout, skips the race entirely)
  //   3. GET /consent/status as the authoritative fallback
  useEffect(() => {
    if (authLoading || !isAuthenticated || skipConsentCheck) {
      setConsentState(skipConsentCheck ? 'ok' : 'checking');
      return;
    }
    if (user?.consent_accepted === true) {
      setConsentState('ok');
      return;
    }
    if (user?.id && getCachedConsent(user.id)) {
      setConsentState('ok');
      return;
    }
    let cancelled = false;
    setConsentState('checking');
    getConsentStatus()
      .then((status) => {
        if (cancelled) return;
        setConsentState(status?.consented === true ? 'ok' : 'missing');
      })
      .catch(() => {
        // Fail-closed: a broken consent lookup must not silently grant access
        // to the whole HR system. The consent page itself re-checks on load.
        if (!cancelled) setConsentState('missing');
      });
    return () => {
      cancelled = true;
    };
  }, [authLoading, isAuthenticated, skipConsentCheck, user?.id, user?.consent_accepted]);

  if (authLoading || (isAuthenticated && !skipConsentCheck && consentState === 'checking')) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  // Authenticated but consent not yet given — force the consent flow.
  // The location key lets the consent page return them where they were headed.
  if (!skipConsentCheck && consentState === 'missing') {
    return (
      <Navigate
        to={`/data-protection-consent?returnTo=${encodeURIComponent(location.pathname + location.search)}`}
        replace
      />
    );
  }

  if (permission) {
    const [module, action] = parsePermission(permission);
    if (module && !can(module, action)) {
      return <AccessDenied permission={permission} />;
    }
  }

  if (fallbackPermission) {
    const [module, action] = parsePermission(fallbackPermission);
    if (module && !can(module, action)) {
      // Safe redirect - never /dashboard blindly (that page may itself be
      // denied), so the user lands on the first route they may open.
      return <Navigate to={firstPermittedRoute(can)} replace />;
    }
  }

  // Authenticated (and permitted, when required) - render protected content
  return children;
};

export default ProtectedRoute;
