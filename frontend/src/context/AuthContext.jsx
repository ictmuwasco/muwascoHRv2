import { createContext, useContext, useState, useEffect, useRef } from 'react';
import api from '../utils/api';
// Fallback broad-access roles — centralized in the global role registry (config/roles.js)
import { BROAD_ACCESS_ROLES } from '../config/roles';

const AuthContext = createContext(null);

// ===========================================================================
// Single-write module classification (mirrors backend config/permissions.php
// + api.php route gates). See AuthContext.canCreate/canEdit/canDelete docs.
//
// GRANULAR modules (employees, users, meetings, departments, holidays,
// financial_year, delegations, leave...) expose separate create/edit/delete
// actions — each is its own grant; edit never implies create or delete.
// SINGLE-WRITE modules expose ONE write action covering the whole write set:
//   * `manage` — API gates create+update+delete under `<module>:manage`
//     (e.g. DELETE /targets → strategic_plan:manage, DELETE
//     /settings/hr-policies/{id} → hr_policies:manage). Holding manage =
//     full write, INCLUDING Delete (user decision: manage = full write).
//   * `edit` (profile) — profile:edit gates profile updates, document
//     upload/delete, next-of-kin, dependants and contract renewal.
//
// DANGER: a sibling `manage` on a GRANULAR module must NOT widen the other
// actions — meetings:manage (minutes lifecycle) does not grant meeting
// edit/delete; leave:manage does not grant approve/reject/invalidate. Only
// the lists below promote manage/edit to full-write.
// ===========================================================================

/** Modules whose sole write action `manage` covers create + edit + delete. */
const MANAGE_FULL_WRITE_MODULES = [
  'strategic_plan',
  'performance_contract',
  'workplan',
  'kpi',
  'sectional_objective',
  'performance',
  'hr_policies',
  'attendance',
  'consent',
  'payroll',
  'notifications',
  'permission_overrides',
  'system',
  'system_errors',
  'security',
  'admin',
];

/** Modules whose sole write action `edit` covers create + edit + delete. */
const EDIT_FULL_WRITE_MODULES = ['profile'];

/** One-time flag so we never spam the console on repeated fallbacks. */
let warnedMissingProvider = false;

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    // Defensive fallback instead of throwing. A component mounted outside
    // <AuthProvider> - or a transient Vite HMR module swap while the dev
    // server hot-reloads edited files - previously crashed the entire tree
    // with "useAuth must be used within an AuthProvider" (seen once from
    // /leave/roster). Behaving as signed-out lets ProtectedRoute send the
    // user to /login and every other consumer keep rendering safely.
    if (!warnedMissingProvider) {
      warnedMissingProvider = true;
      console.warn('useAuth called outside AuthProvider - using signed-out fallback.');
    }
    return {
      user: null,
      loading: false,
      isAuthenticated: false,
      can: () => false,
      canAny: () => false,
      canEdit: () => false,
      canDelete: () => false,
      hasRole: () => false,
      login: async () => ({
        success: false,
        message: 'Authentication is unavailable. Please reload the page.',
      }),
      logout: async () => {
        localStorage.removeItem('user');
      },
      refreshPermissions: async () => {},
    };
  }
  return context;
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  const fetchedUserRef = useRef(false);
  const refreshingRef = useRef(false);

  /**
   * Refresh the effective permission set from the server (§31). Single-flight:
   * concurrent callers (focus handler + interval + 403 handler) share one
   * in-flight request. The cached profile/permissions render instantly, then
   * /auth/user overwrites them with the authoritative set so sidebar, routes
   * and buttons reflect recent permission changes without a manual reload or
   * clearing browser storage. UX ONLY — the backend enforces authorization
   * independently on every request.
   */
  const refreshPermissions = async () => {
    if (refreshingRef.current) return;
    refreshingRef.current = true;
    try {
      const response = await api.get('/auth/user');
      const freshUser = response?.data?.data ?? response?.data;
      if (freshUser && typeof freshUser === 'object' && freshUser.id) {
        localStorage.setItem('user', JSON.stringify(freshUser));
        setUser(freshUser);
      }
    } catch {
      // Silent — a stale session cookie simply leaves the cached profile in
      // place; ProtectedRoute / backend 401 handling bounces the user to
      // /login when a request actually fails.
    } finally {
      refreshingRef.current = false;
    }
  };

  useEffect(() => {
    // React StrictMode (development) double-invokes effects.
    // Guard against the duplicate fetch so /auth/user is called once.
    if (fetchedUserRef.current) return;
    fetchedUserRef.current = true;

    // Check for existing session
    // The access token is now in an httpOnly cookie (set by the server),
    // so we only need to restore the cached user profile from localStorage.
    const userData = localStorage.getItem('user');

    if (userData) {
      try {
        const parsed = JSON.parse(userData);
        if (parsed && typeof parsed === 'object') {
          setUser(parsed);
        } else {
          // Corrupt entry � clear it so we don't loop
          localStorage.removeItem('user');
        }
      } catch {
        // Corrupt JSON � clear and continue
        localStorage.removeItem('user');
      }
    }

    // §31: keep the effective permission set fresh without manual reloads.
    //  - on mount (authoritative overwrite of the cached profile)
    //  - on window focus / visibility change (returning to the tab)
    //  - on a 5-minute interval (long-lived sessions pick up admin changes)
    //
    // IMPORTANT: setLoading(false) is delayed until refreshPermissions()
    // resolves so that ProtectedRoute doesn't unblock with a stale
    // localStorage profile (missing the permissions array). This was the
    // root cause of managing_director seeing "Access denied" on the
    // dashboard even though the backend granted dashboard:view.
    refreshPermissions().finally(() => setLoading(false));

    const onVisibility = () => {
      if (document.visibilityState === 'visible') refreshPermissions();
    };
    const onFocus = () => refreshPermissions();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('focus', onFocus);
    const interval = window.setInterval(refreshPermissions, 5 * 60 * 1000);

    return () => {
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('focus', onFocus);
      window.clearInterval(interval);
    };
  }, []);

  const login = async (email, password) => {
    try {
      const response = await api.post('/auth/login', {
        email,
        password,
      });

      const payload = response?.data;
      const data = payload?.data;
      const userData = data?.user;

      if (!userData) {
        return {
          success: false,
          message:
            payload?.message || 'Login response was malformed. Please contact your administrator.',
        };
      }

      // The access token is set as an httpOnly cookie by the server.
      // We only persist the user profile for fast UI restore.
      localStorage.setItem('user', JSON.stringify(userData));

      setUser(userData);

      return { success: true };
    } catch (error) {
      const errorData = error.response?.data;

      // Provide user-friendly error messages based on error type
      let message = 'Login failed. Please try again.';

      if (error.response) {
        if (errorData) {
          if (
            errorData.error === 'DATABASE_CONNECTION_ERROR' ||
            errorData.error === 'DATABASE_ERROR'
          ) {
            message =
              'Database is unreachable. Please make sure MySQL is running in XAMPP and the "muwasco" database exists.';
          } else if (errorData.message) {
            message = errorData.message;
          } else if (errorData.errors) {
            // Validation errors (Laravel-style { errors: { field: [...] } })
            const errors = Object.values(errorData.errors).flat();
            message = errors.join(', ');
          }
        }
      } else {
        // No HTTP response — usually means the dev server / Vite proxy
        // couldn't reach the PHP API (XAMPP Apache not running, or the
        // /api proxy is misconfigured). Give an actionable hint.
        message =
          'Cannot reach the server. Make sure XAMPP (Apache + MySQL) is running and try again.';
      }

      // Log full error to the browser console for easier debugging

      console.error('Login error:', error, errorData);

      return {
        success: false,
        message: message,
      };
    }
  };

  const logout = async () => {
    try {
      await api.post('/auth/logout');
    } catch (error) {
      // Ignore errors, still logout
    } finally {
      // Note: We deliberately do NOT clear the consent cache here.
      // Consent is a database fact that persists when a user logs out.
      // Keeping the cache lets ProtectedRoute skip the server-side
      // consent check on the next login — avoiding the race condition
      // where the session cookie hasn't propagated yet, which would
      // otherwise redirect already-consented users back to the consent page.
      localStorage.removeItem('user');
      setUser(null);
    }
  };

  /**
   * Centralized frontend authorization helper (Phase 2, §11–12).
   *
   * `can(module, action)` consults the EFFECTIVE permission strings the
   * backend attached to /auth/login and /auth/user responses (already the
   * result of super_admin policy + user overrides + role permissions +
   * default deny). Returns a boolean suitable for menu/button/route
   * visibility.
   *
   * SECURITY MODEL: this is UX convenience ONLY — it never replaces the
   * backend. The API enforces authorization independently on every request,
   * so a stale/incorrect cached value can at worst hide or show a button,
   * never grant access.
   *
   * @param {string} module  catalog module key, e.g. 'leave'
   * @param {string} action  catalog action key, e.g. 'approve'
   * @returns {boolean}
   */
  const can = (module, action = 'view') => {
    if (!user || !Array.isArray(user.permissions)) {
      // No effective-permission set (e.g. stale localStorage from before
      // Phase 2). Default deny for everyone except the broad-access roles,
      // whose documented policy is broad access — managing_director holds
      // dashboard:view in the role matrix (migration 038 §5) but is NOT
      // covered by the super_admin/admin shortcut that previously excluded
      // it (Phase 2 §14 fallback). Role list lives in the global role
      // registry (config/roles.js). UX only — the backend enforces the real
      // check on every API request.
      return !!user && BROAD_ACCESS_ROLES.includes(user.role);
    }
    return user.permissions.includes(`${module}:${action}`);
  };

  /**
   * Role-based check — useful for UI elements that should be visible to
   * all users holding a particular role, regardless of whether the
   * permission set has been loaded yet.
   * @param {string|string[]} roles e.g. 'section_head' or ['section_head','subsection_head']
   * @returns {boolean}
   */
  const hasRole = (roles) => {
    if (!user || !user.role) return false;
    const list = Array.isArray(roles) ? roles : [roles];
    return list.includes(user.role);
  };

  /**
   * True when the user holds ANY of the given "module:action" pairs.
   * @param {Array<[string, string]>} pairs e.g. [['leave','approve'],['leave','manage']]
   * @returns {boolean}
   */
  const canAny = (pairs) => {
    if (!Array.isArray(pairs) || pairs.length === 0) return false;
    return pairs.some(([module, action]) => can(module, action));
  };

  // ===========================================================================
  // Single-write module classification — the MANAGE_FULL_WRITE_MODULES /
  // EDIT_FULL_WRITE_MODULES lists live at module scope (top of file).
  // ===========================================================================

  /**
   * Centralized CREATE rule (mandatory across every page).
   *
   * Add affordances require an explicit `<module>:create` grant — never view
   * or edit. Exception: for single-write modules (lists above) the module's
   * sole write action IS the create grant (profile:edit → "Add" document /
   * next-of-kin / dependant; <module>:manage → "New" for manage-only modules).
   *
   * @param {string} module catalog module key, e.g. 'employees'
   * @returns {boolean}
   */
  const canCreate = (module) =>
    can(module, 'create') ||
    (MANAGE_FULL_WRITE_MODULES.includes(module) && can(module, 'manage')) ||
    (EDIT_FULL_WRITE_MODULES.includes(module) && can(module, 'edit'));

  /**
   * Centralized MUTATION rule (mandatory across every page).
   *
   * Viewing is never enough to mutate: a user who only holds `<module>:view`
   * must NOT see Edit affordances. An explicit `edit`/`update` grant qualifies;
   * so does `manage` — but ONLY for single-write modules where manage is the
   * whole write set (see list above). On granular modules a sibling `manage`
   * (e.g. meetings:manage = minutes) never unlocks edit.
   *
   * @param {string} module catalog module key, e.g. 'employees'
   * @returns {boolean}
   */
  const canEdit = (module) =>
    can(module, 'edit') ||
    can(module, 'update') ||
    (MANAGE_FULL_WRITE_MODULES.includes(module) && can(module, 'manage'));

  /**
   * Centralized DESTRUCTION rule (mandatory across every page).
   *
   * Delete is a strictly separate grant on granular modules: holding view —
   * or even view + edit — never renders a Delete affordance; only an explicit
   * `<module>:delete` unlocks it. For single-write modules (lists above) the
   * sole write action counts as the full write set, so `<module>:manage`
   * (or profile's `edit`) unlocks Delete — matching the API, which gates those
   * DELETE endpoints under the same single action.
   *
   * @param {string} module catalog module key, e.g. 'employees'
   * @returns {boolean}
   */
  const canDelete = (module) =>
    can(module, 'delete') ||
    (MANAGE_FULL_WRITE_MODULES.includes(module) && can(module, 'manage')) ||
    (EDIT_FULL_WRITE_MODULES.includes(module) && can(module, 'edit'));

  const value = {
    user,
    login,
    logout,
    loading,
    isAuthenticated: !!user,
    can,
    canAny,
    canCreate,
    canEdit,
    canDelete,
    hasRole,
    refreshPermissions,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
