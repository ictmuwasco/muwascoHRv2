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
  const { can, canAny } = useAuth()
  const location = useLocation()
  const [expandedParent, setExpandedParent] = useState(null)

  // Phase 2 (§11–12): sidebar visibility follows the CENTRALIZED effective
  // permission set from /auth/user — not hardcoded role arrays. These were
  // the exact hardcoded-role checks the audit flagged (super_admin,
  // hr_manager, dept_head, section_head, sub_section_head, managing_director).
  const canManageLeave = canAny([['leave', 'approve'], ['leave', 'manage']])
  const canViewLeave = can('leave', 'view') || canManageLeave
  const canViewStrategy = canAny([
    ['strategic_plan', 'view'],
    ['performance_contract', 'view'],
    ['workplan', 'view'],
    ['kpi', 'view'],
    ['sectional_objective', 'view'],
  ])
  const canViewAttendance = can('attendance', 'view')
  const canViewMeetings = can('meetings', 'view')
  const canViewReports = can('reports', 'view')
  const canViewHrAdmin = canAny([
    ['financial_year', 'view'],
    ['performance', 'view'],
    ['consent', 'view'],
    ['holidays', 'view'],
  ])
  const canViewAppraisal = can('performance', 'view')

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

  // Navigation items are gated by the centralized effective permission set.
  // Group headers render only when at least one visible child exists.
  const allNavigation = [
    { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard, visible: () => can('dashboard', 'view') },
    { name: 'Employees', href: '/employees', icon: Users, visible: () => can('employees', 'view') },
    { name: 'Profile', href: '/profile', icon: User, visible: () => can('profile', 'view') },
    { name: 'Departments', href: '/departments', icon: Building2, visible: () => can('departments', 'view') },
    {
      name: 'HR Admin',
      icon: UserCog,
      visible: () => canViewHrAdmin,
      submenu: [
        { name: 'Financial Year', href: '/financial_year', icon: DollarSign, visible: () => can('financial_year', 'view') },
        { name: 'Appraisal Cycles', href: '/hr_admin/appraisal-cycles', icon: CalendarRange, visible: () => can('performance', 'view') },
        { name: 'Consent Management', href: '/consent_management', icon: ClipboardList, visible: () => can('consent', 'view') },
        { name: 'Holidays', href: '/holidays', icon: PartyPopper, visible: () => can('holidays', 'view') },
      ],
    },
    {
      name: 'Attendance',
      icon: CalendarCheck,
      visible: () => canViewAttendance,
      submenu: [
        { name: 'Attendance Dashboard', href: '/attendance/dashboard', icon: LayoutDashboard, visible: () => can('attendance', 'view') },
        { name: 'Attendance Records', href: '/attendance', icon: CalendarCheck, visible: () => can('attendance', 'view') },
      ],
    },
    {
      name: 'Meetings',
      icon: CalendarDays,
      visible: () => canViewMeetings,
      submenu: [
        { name: 'Create Meeting', href: '/meetings/create', icon: Calendar, visible: () => can('meetings', 'create') },
        { name: 'My Meetings', href: '/my-meetings', icon: CalendarCheck, visible: () => can('meetings', 'view') },
        { name: 'Dashboard', href: '/meetings', icon: LayoutDashboard, visible: () => can('meetings', 'view') },
      ],
    },
    canManageLeave
      ? {
          name: 'LEAVE MANAGEMENT',
          icon: Calendar,
          visible: () => canViewLeave,
          submenu: [
            { name: 'Leave Applications', href: '/leave', icon: Calendar, visible: () => can('leave', 'view') },
            { name: 'Manage Leave', href: '/leave/manage', icon: ClipboardList, visible: () => can('leave', 'manage') },
            { name: 'Employee Leave Profile', href: '/leave/profile', icon: User, visible: () => can('leave', 'view') },
          ],
        }
      : { name: 'Leave', href: '/leave', icon: Calendar, visible: () => canViewLeave },
    {
      name: 'Roster',
      icon: CalendarRange,
      visible: () => canViewLeave,
      submenu: [
        { name: 'Leave Roster', href: '/leave/roster', icon: CalendarRange, visible: () => can('leave', 'view') },
        { name: 'Leave Oversight', href: '/leave/oversight', icon: BarChart3, visible: () => can('leave', 'view') },
      ],
    },
    { name: 'Appraisal', href: '/appraisal', icon: Star, visible: () => canViewAppraisal },
    ...(canViewStrategy
      ? [{
          name: 'Strategy & Performance',
          icon: Target,
          visible: () => canViewStrategy,
          submenu: [
            { name: 'Strategic Plan', href: '/strategy/strategic-plan', icon: Target, visible: () => can('strategic_plan', 'view') },
            { name: 'Performance Contracts', href: '/strategy/performance-contracts', icon: FileText, visible: () => can('performance_contract', 'view') },
            { name: 'Workplans', href: '/strategy/workplans', icon: ClipboardList, visible: () => can('workplan', 'view') },
            { name: 'Performance Reports', href: '/strategy/reports', icon: BarChart3, visible: () => can('strategic_plan', 'view') },
          ],
        }]
      : []),
    {
      name: 'Reports',
      icon: BarChart3,
      visible: () => canViewReports,
      submenu: [
        { name: 'Employee Reports', href: '/reports', icon: Users, visible: () => can('reports', 'view') },
        { name: 'Attendance Reports', href: '/reports/attendance', icon: CalendarCheck, visible: () => can('reports', 'view') },
        { name: 'Leave Reports', href: '/leave/reports', icon: FileBarChart2, visible: () => can('reports', 'view') },
      ],
    },
    { name: 'Settings', href: '/settings', icon: Settings, visible: () => true },
  ]

  // Groups only render when a child is visible; drop fully-hidden groups.
  const navigation = allNavigation
    .filter((item) => (item.submenu ? item.submenu.some((sub) => sub.visible()) : item.visible()))
    .map((item) => ({
      ...item,
      submenu: item.submenu ? item.submenu.filter((sub) => sub.visible()) : undefined,
    }))

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
