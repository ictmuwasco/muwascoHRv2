import { NavLink, Outlet, Navigate } from 'react-router-dom'
import {
  FileText,
  Users as UsersIcon,
  Shield,
  User,
  Bell,
  Lock,
  Activity,
  ShieldAlert,
} from 'lucide-react'
import { useAuth } from '../../context/AuthContext'
import { SETTINGS_TABS, parsePermission, firstPermittedRoute } from '../../config/pagePermissions'

/**
 * Settings module layout (Phase: Role, Page & Permission restriction).
 *
 * The whole /settings page is a PROTECTED MODULE (Section 27): the page shell
 * and every tab carry their own "settings:<tab>" permission from the central
 * Page Permission Registry. Tabs render only when the user holds the
 * corresponding effective permission; a user with no settings permissions at
 * all sees the Access Denied screen.
 *
 * This replaces the previous hardcoded MONITORING_ROLES role array — the
 * centralized effective-permission check (Section 26) is the single source.
 *
 * SECURITY MODEL: UX only. The underlying APIs (users, audit, permission
 * overrides, monitoring, admin) are permission-gated server-side on every
 * request; hiding a tab never protects data by itself.
 *
 * Place: frontend/src/components/settings/SettingsLayout.jsx
 */
const TAB_ICONS = {
  profile: <User className="h-4 w-4" />,
  notifications: <Bell className="h-4 w-4" />,
  security: <Lock className="h-4 w-4" />,
  audit: <FileText className="h-4 w-4" />,
  users: <UsersIcon className="h-4 w-4" />,
  permissions: <Shield className="h-4 w-4" />,
  monitoring: <Activity className="h-4 w-4" />, 
}

/**
 * Index route for /settings: land the user on the first settings tab they
 * may open; if they hold no settings permissions at all, bounce to the
 * first permitted route (never a redirect loop).
 */
export function SettingsIndexRedirect() {
  const { can } = useAuth()
  const allowed = SETTINGS_TABS.filter((tab) => {
    const [module, action] = parsePermission(tab.permission)
    return can(module, action)
  })

  if (allowed.length === 0) {
    return <Navigate to={firstPermittedRoute(can)} replace />
  }
  return <Navigate to={`/settings/${allowed[0].id}`} replace />
}

const SettingsLayout = () => {
  const { can } = useAuth()

  // Permission-driven tab visibility (replaces the hardcoded role list).
  const tabs = SETTINGS_TABS.filter((tab) => {
    const [module, action] = parsePermission(tab.permission)
    return can(module, action)
  })

  // Complete Settings lockdown (e.g. Officer/Sub-section Head/Section Head/
  // Department Head/HR Manager/Managing Director by default): fail fast with
  // the standard Access Denied screen. The individual tab guards would also
  // catch this, but the layout is the right place for the module shell.
  if (tabs.length === 0) {
    return (
      <div className="space-y-6">
        <div className="text-center py-12">
          <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
            <ShieldAlert className="h-8 w-8 text-red-600 dark:text-red-400" />
          </div>
          <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100">Access denied</h1>
          <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Your account does not have permission to open any Settings area.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Settings</h1>
        <p className="text-gray-500 dark:text-gray-400">Manage your account, users, and system configuration</p>
      </div>

      {/* Horizontal tab nav - only permitted tabs are rendered. */}
      <div className="border-b border-gray-200 dark:border-slate-700 overflow-x-auto scrollbar-thin">
        <nav className="-mb-px flex space-x-1 sm:space-x-8" aria-label="Settings tabs">
          {tabs.map((tab) => (
            <NavLink
              key={tab.id}
              to={`/settings/${tab.id}`}
              className={({ isActive }) =>
                `flex items-center space-x-1 sm:space-x-2 py-3 px-2 sm:px-1 border-b-2 text-xs sm:text-sm font-medium transition-colors whitespace-nowrap ${
                  isActive
                    ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-400'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-slate-500'
                }`
              }
            >
              {TAB_ICONS[tab.id]}
              <span className="hidden xs:inline">{tab.name}</span>
              <span className="xs:hidden">{tab.name.split(' ')[0]}</span>
            </NavLink>
          ))}
        </nav>
      </div>

      {/* The tab routes are individually permission-guarded in App.jsx. */}
      <Outlet />
    </div>
  )
}

export default SettingsLayout
