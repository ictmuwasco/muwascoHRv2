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
  BarChart3,
  Target,
  FileText,
  CalendarClock,
  FileBarChart2,
} from 'lucide-react'
import Logo from './Logo'

const Sidebar = ({ isOpen = false, onClose = () => {} }) => {
  const { user } = useAuth()
  const location = useLocation()
  const [expandedParent, setExpandedParent] = useState(null)

  const MANAGER_ROLES = ['sub_section_head', 'section_head', 'dept_head', 'managing_director', 'hr_manager', 'super_admin']
  const role = (user?.role || '').toLowerCase()
  const canManageLeave = MANAGER_ROLES.includes(role)

  // Strategy & Performance visibility mirrors the backend OrgScope::canViewAny()
  // viewer roles so the menu only appears for users who can actually use it.
  const STRATEGY_VIEWER_ROLES = [
    'super_admin', 'hr_manager', 'dept_head', 'section_head',
    'sub_section_head', 'manager', 'managing_director', 'officer'
  ]
  const canViewStrategy = STRATEGY_VIEWER_ROLES.includes(role)

  // Auto-expand the correct parent based on the current route.
  useEffect(() => {
    const path = location.pathname
    if (path.startsWith('/leave/roster') || path.startsWith('/leave/oversight')) {
      setExpandedParent('Roster')
    } else if (path.startsWith('/leave/reports')) {
      setExpandedParent('Reports')
    } else if (path.startsWith('/leave')) {
      setExpandedParent('LEAVE MANAGEMENT')
    } else if (path.startsWith('/meetings') || path.startsWith('/my-meetings')) {
      setExpandedParent('Meetings')
    } else if (path.startsWith('/attendance')) {
      setExpandedParent('Attendance')
    } else if (path.startsWith('/financial_year') || path.startsWith('/hr_admin') || path.startsWith('/consent_management') || path.startsWith('/holidays')) {
      setExpandedParent('HR Admin')
    } else if (canViewStrategy && path.startsWith('/strategy')) {
      setExpandedParent('Strategy & Performance')
    } else if (path.startsWith('/reports')) {
      setExpandedParent('Reports')
    } else {
      setExpandedParent(null)
    }
  }, [location.pathname, canViewStrategy])

  const toggleParent = (name) => {
    setExpandedParent((prev) => (prev === name ? null : name))
  }

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
        { name: 'Appraisal Cycles', href: '/hr_admin/appraisal-cycles', icon: CalendarRange },
        { name: 'Consent Management', href: '/consent_management', icon: ClipboardList },
        { name: 'Holidays', href: '/holidays', icon: PartyPopper },
      ]
    },
    {
      name: 'Attendance',
      icon: CalendarCheck,
      submenu: [
        { name: 'Attendance Dashboard', href: '/attendance/dashboard', icon: LayoutDashboard },
        { name: 'Attendance Records', href: '/attendance', icon: CalendarCheck },
      ]
    },
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
            { name: 'Employee Leave Profile', href: '/leave/profile', icon: User },
          ],
        }
      : { name: 'Leave', href: '/leave', icon: Calendar },
    {
      name: 'Roster',
      icon: CalendarRange,
      submenu: [
        { name: 'Leave Roster', href: '/leave/roster', icon: CalendarRange },
        { name: 'Leave Oversight', href: '/leave/oversight', icon: BarChart3 },
      ]
    },
    { name: 'Appraisal', href: '/appraisal', icon: Star },
    ...(canViewStrategy
      ? [{
          name: 'Strategy & Performance',
          icon: Target,
          submenu: [
            { name: 'Strategic Plan', href: '/strategy/strategic-plan', icon: Target },
            { name: 'Performance Contracts', href: '/strategy/performance-contracts', icon: FileText },
            { name: 'Workplans', href: '/strategy/workplans', icon: ClipboardList },
            { name: 'Performance Reports', href: '/strategy/reports', icon: BarChart3 },
          ],
        }]
      : []),
    {
      name: 'Reports',
      icon: BarChart3,
      submenu: [
        { name: 'Employee Reports', href: '/reports', icon: Users },
        { name: 'Attendance Reports', href: '/reports/attendance', icon: CalendarCheck },
        { name: 'Leave Reports', href: '/leave/reports', icon: FileBarChart2 },
      ]
    },
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
        <nav className="h-[calc(100vh-4rem)] overflow-y-auto p-4 space-y-1">
          {navigation.map((item) => {
            if (item.submenu) {
              const isExpanded = expandedParent === item.name
              return (
                <div key={item.name}>
                  <button
                    onClick={() => toggleParent(item.name)}
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
      </div>
    </>
  )
}

export default Sidebar
