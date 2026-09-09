/**
 * Page Permission Registry — the SINGLE source of truth mapping every
 * protected frontend route to the effective permission ("module:action")
 * required to open it (Phase: Role, Page & Permission restriction system,
 * requirement §29).
 *
 * consumed by:
 *   - App.jsx            (route-level protection via <ProtectedRoute permission>)
 *   - Sidebar.jsx        (navigation visibility — sidebar is UX only, §19)
 *   - SettingsLayout.jsx (per-tab visibility inside the Settings module)
 *
 * SECURITY MODEL: this registry drives USER EXPERIENCE ONLY. The backend
 * (api.php route table + AuthorizationMiddleware) enforces authorization
 * independently on every API request. A user who reaches a page through URL
 * manipulation simply cannot fetch data — and with the registry wired into
 * ProtectedRoute, direct navigation is also denied.
 *
 * Place: frontend/src/config/pagePermissions.jsx
 */

/**
 * Every protected page: stable id → route + required permission.
 * `permission` uses the backend catalog "module:action" form.
 */
export const PAGE_PERMISSIONS = {
  // --- Core ---------------------------------------------------------------
  '/dashboard':             { id: 'dashboard',          permission: 'dashboard:view' },
  '/profile':               { id: 'profile',            permission: 'profile:view' },

  // --- Employees & org ------------------------------------------------------
  '/employees':             { id: 'employees',          permission: 'employees:view' },
  '/employees/add':         { id: 'employees',          permission: 'employees:create' },
  '/employees/:id/edit':    { id: 'employees',          permission: 'employees:edit' },
  '/employees/:id/profile': { id: 'employees',          permission: 'employees:view' },
  '/departments':           { id: 'departments',        permission: 'departments:view' },

  // --- Attendance -------------------------------------------------------------
  // The attendance LIST page needs attendance:view (officers see their own
  // records; the backend pins the data scope server-side). The monitoring
  // dashboard additionally requires attendance:manage.
  '/attendance':           { id: 'attendance',           permission: 'attendance:view' },
  '/attendance/dashboard': { id: 'attendance_dashboard', permission: 'attendance:manage' },

  // --- Leave --------------------------------------------------------------------
  '/leave':           { id: 'leave',           permission: 'leave:view' },
  '/leave/apply':     { id: 'leave_apply',     permission: 'leave:apply' },
  '/leave/profile':   { id: 'leave_profile',   permission: 'leave:view' },
  '/leave/manage':    { id: 'leave_manage',    permission: 'leave:manage' },
  // Temporary Delegation / Acting Authority page. delegations:view is held by
  // every role (self-service "My Delegations"); the create/approve actions are
  // gated inside the page by delegations:create / delegations:approve.
  '/delegations':     { id: 'delegations',     permission: 'delegations:view' },
  // Roster / Oversight show OTHER employees' planned leave (§33): approver/HR
  // only — require leave:manage, never leave:view.
  // Phase 10: Roster is an HR-only module - gated by the dedicated
  // leave:roster permission (hr_manager + super_admin by default), NOT
  // leave:manage, which heads hold for scoped Leave Management.
  '/leave/roster':    { id: 'leave_roster',    permission: 'leave:roster' },
  '/leave/oversight': { id: 'leave_oversight', permission: 'leave:roster' },
  '/leave/reports':   { id: 'leave_reports',   permission: 'reports:view' },

  // --- Meetings ------------------------------------------------------------
  // /meetings and its details/confirm sub-routes render the ORG-WIDE
  // MeetingsDashboard — restricted to hr_manager / managing_director /
  // super_admin (permission meetings:dashboard, migration 038). Personal
  // invitations live at /my-meetings (meetings:view) and are open to every
  // invited role (§4).
  '/meetings':             { id: 'meetings',        permission: 'meetings:dashboard' },
  '/meetings/create':      { id: 'meetings_create', permission: 'meetings:create' },
  '/meetings/:id/edit':    { id: 'meetings_create', permission: 'meetings:edit' },
  '/my-meetings':          { id: 'my_meetings',     permission: 'meetings:view' },
  '/meetings/:id/details': { id: 'meetings',        permission: 'meetings:dashboard' },
  '/meetings/:id/confirm': { id: 'meetings',        permission: 'meetings:dashboard' },

  // --- HR admin ---------------------------------------------------------------------
  // The whole HR Admin group is an HR-restricted module (migration 039):
  // hr_manager / managing_director / super_admin hold every permission below
  // by default. Appraisal Cycles uses the dedicated performance:cycles page
  // permission (decoupled from performance:view, which gates the standalone
  // Appraisal page heads keep, and performance:manage, which heads need for
  // appraisal create/submit/approve).
  '/financial_year':            { id: 'financial_year',   permission: 'financial_year:view' },
  '/hr_admin/appraisal-cycles': { id: 'appraisal_cycles', permission: 'performance:cycles' },
  '/appraisal_cycles':          { id: 'appraisal_cycles', permission: 'performance:cycles' },
  '/consent_management':        { id: 'consent',          permission: 'consent:view' },
  '/consent':                   { id: 'consent',          permission: 'consent:view' },
  '/holidays':                  { id: 'holidays',         permission: 'holidays:view' },
  '/appraisal':                 { id: 'appraisal',        permission: 'performance:view' },

  // --- Strategy & performance --------------------------------------------------
  '/strategy/strategic-plan':              { id: 'strategic_plan',        permission: 'strategic_plan:view' },
  '/strategic-plan':                       { id: 'strategic_plan',        permission: 'strategic_plan:view' },
  '/strategy/performance-contracts':       { id: 'performance_contracts', permission: 'performance_contract:view' },
  '/strategy/workplans':                   { id: 'workplans',             permission: 'workplan:view' },
  '/strategy/workplans/managing-director': { id: 'workplans_md',          permission: 'workplan:view' },
  '/strategy/workplans/department-head':   { id: 'workplans_dept',        permission: 'workplan:view' },
  '/strategy/workplans/section-head':      { id: 'workplans_sec',         permission: 'workplan:view' },
  '/strategy/workplans/subsection-head':   { id: 'workplans_sub',         permission: 'workplan:view' },
  '/strategy/reports':                     { id: 'strategy_reports',      permission: 'strategic_plan:view' },

  // --- Reports ---------------------------------------------------------------------
  '/reports':            { id: 'reports',            permission: 'reports:view' },
  '/reports/attendance': { id: 'reports_attendance', permission: 'reports:view' },

  // --- Settings module (§27): the whole page is a protected module. The page
  // shell requires settings:view (super_admin by default); each tab requires
  // its own action; the self-service notifications tab is granted to all
  // roles by default (migration 038).
  '/settings':               { id: 'settings',               permission: 'settings:view' },
  '/settings/profile':       { id: 'settings_profile',       permission: 'settings:profile' },
  '/settings/notifications': { id: 'settings_notifications', permission: 'settings:notifications' },
  '/settings/security':      { id: 'settings_security',      permission: 'settings:security' },
  '/settings/audit':         { id: 'settings_audit',         permission: 'settings:audit' },
  '/settings/users':         { id: 'settings_users',         permission: 'settings:users' },
  '/settings/permissions':   { id: 'settings_permissions',   permission: 'settings:permissions' },
  '/settings/monitoring':    { id: 'settings_monitoring',    permission: 'settings:monitoring' },

  // --- Standalone admin pages --------------------------------------------------------
  '/admin': { id: 'admin', permission: 'admin:view' },
  '/audit': { id: 'audit', permission: 'audit:view' },
}

/**
 * Settings tabs in display order with their required permission. Used by
 * SettingsLayout.jsx to render only the tabs the user may open and to pick
 * the landing tab for /settings.
 */
export const SETTINGS_TABS = [
  { id: 'profile',       name: 'Profile',        permission: 'settings:profile',       selfService: true },
  { id: 'notifications', name: 'Notifications',  permission: 'settings:notifications', selfService: true },
  { id: 'security',      name: 'Security',       permission: 'settings:security',      selfService: true },
  { id: 'audit',         name: 'Audit',          permission: 'settings:audit' },
  { id: 'users',         name: 'Users',          permission: 'settings:users' },
  { id: 'permissions',   name: 'Permissions',    permission: 'settings:permissions' },
  { id: 'monitoring',    name: 'System Monitor', permission: 'settings:monitoring' },
]

/**
 * Permissions that unlock the Settings sidebar entry. Users without ANY of
 * them never see the item (UX only — routes are guarded independently).
 */
export const SETTINGS_VISIBILITY_PERMISSIONS = ['settings:view', 'settings:notifications']

/**
 * Look up the permission requirement for a route pattern.
 * Supports the ":id" placeholder form used by the route table.
 * @param {string} routePath e.g. "/employees/:id/profile" or a concrete "/employees/42/profile"
 * @returns {{ id: string, permission: string } | null}
 */
export function getRoutePermission(routePath) {
  if (!routePath) return null
  if (PAGE_PERMISSIONS[routePath]) return PAGE_PERMISSIONS[routePath]

  // Concrete path fallback: swap numeric segments for ":id".
  const concrete = routePath.replace(/\/[^/]+/g, (seg) => {
    const value = seg.slice(1)
    return /^\d+$/.test(value) || /^[0-9a-f-]{8,}$/i.test(value) ? '/:id' : seg
  })
  return PAGE_PERMISSIONS[concrete] || null
}

/**
 * Parse a "module:action" string into [module, action].
 * @param {string} permission
 * @returns {[string, string]}
 */
export function parsePermission(permission) {
  const [module, action = 'view'] = String(permission || '').split(':')
  return [module, action || 'view']
}

/**
 * The first route the given user may open — used as the SAFE redirect
 * target so a user without dashboard access never enters a redirect loop.
 * @param {(module: string, action?: string) => boolean} can
 * @returns {string}
 */
export function firstPermittedRoute(can) {
  const ordered = [
    '/dashboard',
    '/my-meetings',
    '/attendance',
    '/leave',
    '/profile',
    '/leave/apply',
  ]
  for (const route of ordered) {
    const entry = PAGE_PERMISSIONS[route]
    if (!entry) continue
    const [module, action] = parsePermission(entry.permission)
    if (can(module, action)) return route
  }
  // Authenticated fallback: the backend's own-profile self-service exception
  // always allows the profile page for the session user.
  return '/profile'
}
