import { useState, useEffect } from 'react'
import api from '../utils/api'

/**
 * Global Role Registry — the SINGLE frontend source of truth for role keys,
 * labels and role BEHAVIOR groups (which roles are org-free, supervisory,
 * wide-scope, monitoring-capable, etc.).
 *
 * Why this exists: role strings were previously hardcoded as literal arrays in
 * individual pages (EmployeeForm NO_ORG_ROLES, ErrorMonitoring MONITORING_ROLES,
 * TierWorkplanPage inline checks, Dashboard hasRole lists, AuthContext
 * fallbacks, SettingsPermissionsTab badge colors). Every new role had to be
 * chased across the codebase. Import from here instead.
 *
 * Sources of truth:
 *   - Backend `roles` table (migration 083) — canonical, served by GET /roles
 *   - backend/config/permissions.php "roles"/"role_labels" — catalog fallback
 *   - This file mirrors both so the UI always has a synchronous baseline and
 *     never renders an empty dropdown while the API loads.
 *
 * SYNC CONTRACT: when a role is added on the backend (roles table +
 * config/permissions.php), it must be mirrored here so pages that use the
 * static constants stay correct offline/failure. Optional live sync is
 * available via fetchRoles() / useRoleOptions() (below).
 *
 * IMPORTANT (security model): role checks here are UX convenience only.
 * Authorization itself is enforced by the backend on every request
 * (AuthorizationService: role_permissions + user_page_permissions).
 * Prefer `can(module, action)` over role checks whenever a permission exists
 * for the behavior — role groups below only cover behaviors that have no
 * catalog permission (org-scope requirements, badge colors, fallbacks).
 *
 * Place: frontend/src/config/roles.js
 */

/**
 * Canonical role keys in display order (mirrors config/permissions.php).
 */
export const ROLE_KEYS = [
  'super_admin',
  'hr_manager',
  'managing_director',
  'bod_chairman',
  'dept_head',
  'section_head',
  'sub_section_head',
  'manager',
  'officer',
  'employee',
]

/**
 * Human-readable labels (mirrors config/permissions.php role_labels).
 * Legacy 'admin' is normalized to super_admin by the backend (RBAC.php) but
 * kept here so stale cached profiles still render a sane label.
 */
export const ROLE_LABELS = {
  super_admin: 'Super Admin',
  hr_manager: 'HR Manager',
  managing_director: 'Managing Director',
  bod_chairman: 'BOD Chairman',
  dept_head: 'Department Head',
  section_head: 'Section Head',
  sub_section_head: 'Sub Section Head',
  manager: 'Manager',
  officer: 'Officer',
  employee: 'Employee',
  admin: 'Admin',
}

/**
 * Badge/pill color classes per role (moved from SettingsPermissionsTab so
 * every page renders identical role chips).
 */
export const ROLE_BADGE_CLASSES = {
  super_admin: 'bg-purple-100 text-purple-800',
  hr_manager: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
  dept_head: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
  section_head: 'bg-teal-100 text-teal-800',
  sub_section_head: 'bg-cyan-100 text-cyan-800',
  manager: 'bg-indigo-100 text-indigo-800',
  officer: 'bg-yellow-100 text-yellow-800',
  employee: 'bg-gray-100 text-gray-800',
  managing_director: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  bod_chairman: 'bg-rose-100 text-rose-800',
}

// ===========================================================================
// Role BEHAVIOR groups (moved verbatim from the pages they used to live in).
// Each constant documents where it is consumed.
// ===========================================================================

/**
 * Roles with no organizational attachment (EmployeeForm.tsx): the org
 * fields (department/section/subsection) are hidden and cleared.
 */
export const NO_ORG_ROLES = ['managing_director', 'bod_chairman', 'super_admin']

/**
 * Roles that only need a department (EmployeeForm.tsx): section/subsection
 * fields are hidden.
 */
export const DEPT_ONLY_ROLES = ['dept_head', 'hr_manager']

/**
 * Roles that need department + section (EmployeeForm.tsx): the subsection
 * field is hidden.
 */
export const SECTION_ROLES = ['section_head']

/**
 * Roles with org-wide (non-unit) data scope (TierWorkplanPage.tsx): no
 * department narrowing is applied.
 */
export const WIDE_SCOPE_ROLES = ['super_admin', 'hr_manager', 'managing_director']

/**
 * Roles allowed to manage system monitoring (ErrorMonitoring.tsx): the
 * acknowledge/resolve workflow actions.
 */
export const MONITORING_ROLES = ['super_admin', 'hr_manager']

/**
 * Leadership roles that hold approval queues (Dashboard.tsx hasRole
 * fallback for the "My Pending" card before permissions load).
 */
export const SUPERVISOR_ROLES = ['section_head', 'sub_section_head', 'dept_head', 'managing_director', 'hr_manager']

/**
 * Fallback broad-access roles in AuthContext.can(): used ONLY when the
 * effective permission set is missing (stale localStorage pre-Phase 2).
 * 'admin' is the legacy alias the backend normalizes to super_admin.
 */
export const BROAD_ACCESS_ROLES = ['super_admin', 'admin', 'managing_director']

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * Display label for a role key, falling back to a humanized key for roles
 * that exist only server-side.
 * @param {string} roleKey
 * @returns {string}
 */
export function getRoleLabel(roleKey) {
  if (!roleKey) return ''
  return ROLE_LABELS[roleKey] ?? String(roleKey).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

/**
 * Tailwind badge classes for a role key (default chip when unknown).
 * @param {string} roleKey
 * @returns {string}
 */
export function getRoleBadgeClass(roleKey) {
  return ROLE_BADGE_CLASSES[roleKey] ?? 'bg-gray-100 text-gray-800'
}

/**
 * Select-friendly options for role dropdowns, in display order.
 * @returns {Array<{ value: string, label: string }>}
 */
export function roleOptions() {
  return ROLE_KEYS.map((key) => ({ value: key, label: ROLE_LABELS[key] ?? key }))
}

/**
 * The super_admin role key — the engine policy role (always allowed, never
 * overrideable). Referenced by name only through this constant.
 */
export const SUPER_ADMIN = 'super_admin'

/**
 * True when the given role key is super_admin (or its legacy 'admin' alias,
 * which the backend normalizes in RBAC.php).
 * @param {string} roleKey
 * @returns {boolean}
 */
export function isSuperAdmin(roleKey) {
  return roleKey === 'super_admin' || roleKey === 'admin'
}

/**
 * Membership test against a role group. Accepts a single key or array.
 * Named roleInGroup (not hasRole) to avoid confusion with AuthContext's
 * hasRole(role) which tests the CURRENT user's single role.
 * @param {string|string[]} roles
 * @param {string} roleKey
 * @returns {boolean}
 */
export function roleInGroup(roles, roleKey) {
  if (!roleKey) return false
  const list = Array.isArray(roles) ? roles : [roles]
  return list.includes(roleKey)
}


// ===========================================================================
// Optional live sync with the backend roles table (GET /roles, migration 083)
// ===========================================================================

let serverRolesCache = null
let serverRolesInFlight = null

/**
 * Fetch the canonical role list from the backend (roles table), cached in
 * module scope so multiple consumers share one request. Returns null on
 * failure — callers must fall back to the static ROLE_KEYS above.
 * @returns {Promise<Array<{ key: string, label: string }> | null>}
 */
export async function fetchRoles() {
  if (serverRolesCache) return serverRolesCache
  if (!serverRolesInFlight) {
    serverRolesInFlight = api
      .get('/roles')
      .then((response) => {
        const rows = response?.data?.data ?? response?.data
        if (Array.isArray(rows) && rows.length > 0) {
          serverRolesCache = rows.map((r) => ({
            key: String(r.key),
            label: String(r.label ?? r.key),
          }))
          return serverRolesCache
        }
        return null
      })
      .catch(() => null)
      .finally(() => {
        serverRolesInFlight = null
      })
  }
  return serverRolesInFlight
}

/**
 * Hook: server-backed role options with graceful static fallback. Returns
 * { value, label } options in display order — drop-in for the shared Select.
 * @returns {Array<{ value: string, label: string }>}
 */
export function useRoleOptions() {
  const [options, setOptions] = useState(roleOptions())
  useEffect(() => {
    let cancelled = false
    fetchRoles().then((rows) => {
      if (cancelled || !rows) return
      setOptions(rows.map((r) => ({ value: r.key, label: r.label })))
    })
    return () => {
      cancelled = true
    }
  }, [])
  return options
}

