import { useState, useEffect } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import {
  LayoutDashboard,
  Users,
  Building2,
  CalendarCheck,
  Calendar,
  UserCog,
  Settings,
  LogOut,
  User,
  Star,
  X,
  ChevronDown,
  ChevronRight,
  DollarSign,
  ClipboardList,
  PartyPopper,
  CalendarDays,
  CalendarRange,
  BarChart3
} from 'lucide-react'
import Logo from './Logo'

const Sidebar = ({ isOpen = false, onClose = () => {} }) => {
  const { user, logout } = useAuth()
  const location = useLocation()
  const [isHRAdminExpanded, setIsHRAdminExpanded] = useState(false)
  const [isLeaveExpanded, setIsLeaveExpanded] = useState(false)
  const [isMeetingsExpanded, setIsMeetingsExpanded] = useState(false)

  const MANAGER_ROLES = ['sub_section_head', 'section_head', 'dept_head', 'managing_director', 'hr_manager', 'super_admin']
  const role = (user?.role || '').toLowerCase()
  const canManageLeave = MANAGER_ROLES.includes(role)

  useEffect(() => {
    if (location.pathname.startsWith('/leave')) {
      setIsLeaveExpanded(true)
    }
  }, [location.pathname])

  const navigation = [
    { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    { name: 'Employees', href: '/employees', icon: Users },
    { name: 'Profile', href: '/profile', icon: User },
    { name: 'Departments', href: '/departments', icon: Building2 },
    {
      name: 'HR Admin',
      icon: UserCog,
      submenu: [
        { name: 'Financial Year', href: '/financial_year', icon: DollarSign },
        { name: 'Consent Management', href: '/consent_management', icon: ClipboardList },
        { name: 'Holidays', href: '/holidays', icon: PartyPopper },
      ]
    },
    { name: 'Attendance', href: '/attendance', icon: CalendarCheck },
    {
      name: 'Meetings',
      icon: CalendarDays,
      submenu: [
        { name: 'Create Meeting', href: '/meetings/create', icon: Calendar },
        { name: 'My Meetings', href: '/my-meetings', icon: CalendarCheck },
        { name: 'Dashboard', href: '/meetings', icon: LayoutDashboard },
      ]
    },
    canManageLeave
      ? {
          name: 'LEAVE MANAGEMENT',
          icon: Calendar,
          submenu: [
            { name: 'Leave Applications', href: '/leave', icon: Calendar },
            { name: 'Manage Leave', href: '/leave/manage', icon: ClipboardList },
            { name: 'Leave Roster', href: '/leave/roster', icon: CalendarRange },
            { name: 'Leave Oversight', href: '/leave/oversight', icon: BarChart3 },
          ],
        }
      : { name: 'Leave', href: '/leave', icon: Calendar },
    { name: 'Appraisal', href: '/appraisal', icon: Star },
    { name: 'Settings', href: '/settings', icon: Settings },
  ]

  return (
    <>
      {/* Mobile overlay */}
      {isOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 lg:hidden"
          onClick={onClose}
        />
      )}

      {/* Sidebar */}
      <div
        className={`fixed inset-y-0 left-0 z-50 w-64 bg-white dark:bg-slate-800 border-r dark:border-slate-700 transform transition-transform duration-300 ease-in-out ${
          isOpen ? 'translate-x-0' : '-translate-x-full'
        } lg:translate-x-0 lg:block`}
      >
        {/* Logo */}
        <div className="flex items-center justify-between h-16 border-b dark:border-slate-700 px-4">
          <Logo className="h-10 w-10" />
          <h1 className="text-xl font-bold text-primary-600">MUWASCO HR</h1>
          {/* Close button for mobile */}
          <button
            onClick={onClose}
            className="lg:hidden text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Navigation */}
        <nav className="p-4 space-y-1">
          {navigation.map((item) => {
            if (item.submenu) {
              const isExpanded = item.name === 'HR Admin'
                ? isHRAdminExpanded
                : item.name === 'LEAVE MANAGEMENT'
                  ? isLeaveExpanded
                  : item.name === 'Meetings'
                    ? isMeetingsExpanded
                    : false
              const toggle = item.name === 'HR Admin'
                ? () => setIsHRAdminExpanded(!isHRAdminExpanded)
                : item.name === 'LEAVE MANAGEMENT'
                  ? () => setIsLeaveExpanded(!isLeaveExpanded)
                  : item.name === 'Meetings'
                    ? () => setIsMeetingsExpanded(!isMeetingsExpanded)
                    : () => {}
              return (
                <div key={item.name}>
                  <button
                    onClick={toggle}
                    className="flex items-center w-full space-x-3 px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                  >
                    <item.icon className="h-5 w-5" />
                    <span className="font-medium flex-1 text-left">{item.name}</span>
                    {isExpanded ? (
                      <ChevronDown className="h-4 w-4" />
                    ) : (
                      <ChevronRight className="h-4 w-4" />
                    )}
                  </button>
                  {isExpanded && (
                    <div className="ml-6 mt-1 space-y-1">
                      {item.submenu.map((subItem) => (
                        <NavLink
                          key={subItem.name}
                          to={subItem.href}
                          end
                          onClick={onClose}
                          className={({ isActive }) =>
                            `flex items-center space-x-3 px-4 py-2 rounded-lg transition-colors ${
                              isActive
                                ? 'bg-primary-50 dark:bg-slate-700 text-primary-700 dark:text-primary-300'
                                : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-slate-700'
                            }`
                          }
                        >
                          <subItem.icon className="h-4 w-4" />
                          <span className="text-sm font-medium">{subItem.name}</span>
                        </NavLink>
                      ))}
                    </div>
                  )}
                </div>
              )
            }
            // For routes with children, mark active on the prefix so the parent item lights up.
            const routeIsPrefix = location.pathname.startsWith(item.href)
            return (
              <NavLink
                key={item.name}
                to={item.href}
                end={!item.href.startsWith('/settings')}
                onClick={onClose}
                className={({ isActive }) =>
                  `flex items-center space-x-3 px-4 py-3 rounded-lg transition-colors ${
                    isActive || (item.href === '/settings' && routeIsPrefix)
                      ? 'bg-primary-50 dark:bg-slate-700 text-primary-700 dark:text-primary-300'
                      : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700'
                  }`
                }
              >
                <item.icon className="h-5 w-5" />
                <span className="font-medium">{item.name}</span>
              </NavLink>
            )
          })}
        </nav>

        {/* User info at bottom */}
        <div className="absolute bottom-0 left-0 right-0 p-4 border-t dark:border-slate-700">
          <NavLink
            to="/profile"
            onClick={onClose}
            className="flex items-center space-x-3 mb-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
          >
            <div className="h-10 w-10 rounded-full bg-primary-600 flex items-center justify-center text-white">
              {user?.first_name?.[0] || 'U'}
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                {user?.first_name} {user?.last_name}
              </p>
              <p className="text-xs text-gray-500 dark:text-gray-400 truncate">{user?.email}</p>
            </div>
          </NavLink>
          <button
            onClick={logout}
            className="flex items-center w-full px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg"
          >
            <LogOut className="h-4 w-4 mr-2" />
            Logout
          </button>
        </div>
      </div>
    </>
  )
}

export default Sidebar
