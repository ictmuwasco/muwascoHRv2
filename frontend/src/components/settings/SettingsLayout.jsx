import { NavLink, Outlet, Navigate } from 'react-router-dom'
import Card from '../ui/Card'
import {
  Settings as SettingsIcon,
  FileText,
  Users as UsersIcon,
  Shield,
  User,
  Bell,
  Lock,
  Activity,
} from 'lucide-react'
import { useAuth } from '../../context/AuthContext'

// System Monitoring is technical - restrict the tab to admin-level roles.
const MONITORING_ROLES = ['super_admin', 'hr_manager']

const baseTabs = [
  { id: 'profile',      name: 'Profile',      icon: <User className="h-4 w-4" />,          href: '/settings/profile' },
  { id: 'notifications',name: 'Notifications',icon: <Bell className="h-4 w-4" />,           href: '/settings/notifications' },
  { id: 'security',     name: 'Security',     icon: <Lock className="h-4 w-4" />,           href: '/settings/security' },
  { id: 'audit',        name: 'Audit',        icon: <FileText className="h-4 w-4" />,       href: '/settings/audit' },
  { id: 'users',        name: 'Users',        icon: <UsersIcon className="h-4 w-4" />,      href: '/settings/users' },
  { id: 'permissions',  name: 'Permissions',  icon: <Shield className="h-4 w-4" />,         href: '/settings/permissions' },
]

const monitoringTab = { id: 'monitoring', name: 'System Monitor', icon: <Activity className="h-4 w-4" />, href: '/settings/monitoring' }

const SettingsLayout = () => {
  // RBAC-driven tab visibility (§31): only technical roles see monitoring.
  let tabs = baseTabs
  try {
    const auth = useAuth()
    const role = String(auth?.user?.role ?? '')
    if (MONITORING_ROLES.includes(role)) {
      tabs = [...baseTabs, monitoringTab]
    }
  } catch {
    tabs = baseTabs
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Settings</h1>
        <p className="text-gray-500 dark:text-gray-400">Manage your account, users, and system configuration</p>
      </div>

      {/* Horizontal tab nav */}
      <div className="border-b border-gray-200 dark:border-slate-700 overflow-x-auto scrollbar-thin">
        <nav className="-mb-px flex space-x-1 sm:space-x-8" aria-label="Settings tabs">
          {tabs.map((tab) => (
            <NavLink
              key={tab.id}
              to={tab.href}
              className={({ isActive }) =>
                `flex items-center space-x-1 sm:space-x-2 py-3 px-2 sm:px-1 border-b-2 text-xs sm:text-sm font-medium transition-colors whitespace-nowrap ${
                  isActive
                    ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-400'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-slate-500'
                }`
              }
            >
              {tab.icon}
              <span className="hidden xs:inline">{tab.name}</span>
              <span className="xs:hidden">{tab.name.split(' ')[0]}</span>
            </NavLink>
          ))}
        </nav>
      </div>

      <Outlet />
    </div>
  )
}

export default SettingsLayout

