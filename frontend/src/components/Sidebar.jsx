import { useState, useEffect } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import api from '../utils/api';
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
  FileBarChart2,
  UserCheck,
} from 'lucide-react';
import Logo from './Logo';
import { SETTINGS_VISIBILITY_PERMISSIONS, parsePermission } from '../config/pagePermissions';

const Sidebar = ({ isOpen = false, onClose = () => {} }) => {
  const { user, can, canAny } = useAuth();
  const location = useLocation();
  const [expandedParent, setExpandedParent] = useState(null);

  // Phase 2 (§11–12): sidebar visibility follows the CENTRALIZED effective
  // permission set from /auth/user — not hardcoded role arrays. These were
  // the exact hardcoded-role checks the audit flagged (super_admin,
  // hr_manager, dept_head, section_head, sub_section_head, managing_director).
  const canManageLeave = canAny([
    ['leave', 'approve'],
    ['leave', 'manage'],
  ]);
  const canViewLeave = can('leave', 'view') || canManageLeave;
  const canViewAttendance = can('attendance', 'view');
  const canViewMeetings = can('meetings', 'view');
  const canViewReports = can('reports', 'view');

  // Employees page — registry-aligned (§18/§29): the link follows the SAME
  // permission as the route, employees:view (PAGE_PERMISSIONS['/employees']).
  // The previous check required employees:create ("only hr_manager/super_admin
  // should see employees"), a pre-RBAC hardcode that hid the entry from anyone
  // granted read-only access — e.g. a user-level employees:view override set
  // on Settings → Permissions. View never unlocks mutations: Add/Edit/Delete
  // affordances INSIDE the page stay individually gated (employees:create /
  // :edit / :delete), so a view-only grant renders a read-only list.
  const canViewEmployees = can('employees', 'view');

  // HR Admin group: only for hr_admin role and hr_manager/super_admin
  // Check for hr_admin specific permissions
  const canViewHrAdmin = canAny([
    ['financial_year', 'view'],
    ['performance', 'cycles'],
    ['consent', 'view'],
    ['holidays', 'view'],
  ]);

  // Strategy & Performance: visible to roles with appropriate permissions
  // (hr_manager, super_admin, dept_head, section_head, sub_section_head, manager)
  // These roles now have strategic_plan:view, performance_contract:view, etc.
  const canViewStrategy = canAny([
    ['strategic_plan', 'view'],
    ['performance_contract', 'view'],
    ['kpi', 'view'],
    ['sectional_objective', 'view'],
  ]);

  // Workplans: visible to roles with workplan:view permission
  // (hr_manager, super_admin, dept_head, section_head, sub_section_head, manager)
  const canViewWorkplans = can('workplan', 'view');
  const canViewSupervisorAppraisals =
    !!user &&
    !['officer', 'employee', 'bod_chairman'].includes(String(user.role || '').toLowerCase()) &&
    can('performance', 'supervise');
  // Completed Appraisals is also open to officers/staff (performance:feedback).
  // The API pins them to their OWN appraisals server-side, so this only reveals
  // the menu entry - it grants no access to anybody else's records.
  const canViewCompletedAppraisals = canViewSupervisorAppraisals || can('performance', 'feedback');

  // Delegations / Acting Authority register.
  //
  // `delegations:view` alone is not enough to show this entry. The register is
  // an OVERSIGHT artefact — it lists who is covering whom across an org unit —
  // and officers are excluded from it entirely (migration 097 revokes
  // delegations:view for the role; DelegationService::REGISTER_EXCLUDED_ROLES
  // short-circuits the API as defence in depth).
  //
  // The explicit role check on top of the permission is deliberate: it mirrors
  // the canViewSupervisorAppraisals pattern above, and it means a stray
  // per-user 'delegations:view' ALLOW override cannot re-expose the menu entry
  // to an officer. UX only — the server still decides what rows come back.
  const userRole = String(user?.role || '').toLowerCase();
  const canViewDelegations = can('delegations', 'view') && !['officer'].includes(userRole);

  // Live badge counts for the Delegations entry.
  //
  // Fetched once from the lightweight /delegations/summary (integers only, not
  // whole delegation rows) and refreshed whenever the user navigates back to the
  // register, so the number reflects the state they are about to see rather
  // than whatever was true when the shell first mounted.
  const [delegationCounts, setDelegationCounts] = useState(null);

  useEffect(() => {
    if (!canViewDelegations) {
      setDelegationCounts(null);
      return undefined;
    }

    let cancelled = false;
    const load = async () => {
      try {
        const response = await api.get('/delegations/summary');
        if (!cancelled) {
          setDelegationCounts(response.data?.data?.counts || null);
        }
      } catch {
        // A missing badge must never break navigation — the link still works.
        if (!cancelled) setDelegationCounts(null);
      }
    };

    load();
    return () => {
      cancelled = true;
    };
  }, [canViewDelegations, location.pathname]);

  // Auto-expand the correct parent based on the current route.
  useEffect(() => {
    const path = location.pathname;
    if (path.startsWith('/leave/roster') || path.startsWith('/leave/oversight')) {
      setExpandedParent('Roster');
    } else if (path.startsWith('/leave/reports')) {
      setExpandedParent('Reports');
    } else if (path.startsWith('/leave')) {
      setExpandedParent('LEAVE MANAGEMENT');
    } else if (path.startsWith('/meetings') || path.startsWith('/my-meetings')) {
      setExpandedParent('Meetings');
    } else if (path.startsWith('/attendance')) {
      setExpandedParent('Attendance');
    } else if (
      path.startsWith('/financial_year') ||
      path.startsWith('/hr_admin') ||
      path.startsWith('/consent_management') ||
      path.startsWith('/holidays')
    ) {
      setExpandedParent('HR Admin');
    } else if (
      path.startsWith('/appraisal') ||
      path.startsWith('/strategy/performance-appraisals')
    ) {
      setExpandedParent('Appraisal');
    } else if (canViewStrategy && path.startsWith('/strategy')) {
      setExpandedParent('Strategy & Performance');
    } else if (path.startsWith('/reports')) {
      setExpandedParent('Reports');
    } else {
      setExpandedParent(null);
    }
  }, [location.pathname, canViewStrategy, canViewSupervisorAppraisals]);

  const toggleParent = (name) => {
    setExpandedParent((prev) => (prev === name ? null : name));
  };

  // Navigation items are gated by the centralized effective permission set.
  // Group headers render only when at least one visible child exists.
  const allNavigation = [
    {
      name: 'Dashboard',
      href: '/dashboard',
      icon: LayoutDashboard,
      visible: () => can('dashboard', 'view'),
    },
    { name: 'Employees', href: '/employees', icon: Users, visible: () => canViewEmployees },
    { name: 'Profile', href: '/profile', icon: User, visible: () => can('profile', 'view') },
    {
      name: 'Departments',
      href: '/departments',
      icon: Building2,
      visible: () => can('departments', 'view'),
    },
    {
      name: 'HR Admin',
      icon: UserCog,
      visible: () => canViewHrAdmin,
      submenu: [
        {
          name: 'Financial Year',
          href: '/financial_year',
          icon: DollarSign,
          visible: () => can('financial_year', 'view'),
        },
        {
          name: 'Appraisal Cycles',
          href: '/hr_admin/appraisal-cycles',
          icon: CalendarRange,
          visible: () => can('performance', 'cycles'),
        },
        {
          name: 'Consent Management',
          href: '/consent_management',
          icon: ClipboardList,
          visible: () => can('consent', 'view'),
        },
        {
          name: 'Holidays',
          href: '/holidays',
          icon: PartyPopper,
          visible: () => can('holidays', 'view'),
        },
      ],
    },
    {
      name: 'Attendance',
      icon: CalendarCheck,
      visible: () => canViewAttendance,
      submenu: [
        {
          name: 'Attendance Dashboard',
          href: '/attendance/dashboard',
          icon: LayoutDashboard,
          visible: () => can('attendance', 'manage'),
        },
        {
          name: 'Attendance Records',
          href: '/attendance',
          icon: CalendarCheck,
          visible: () => can('attendance', 'view'),
        },
      ],
    },
    {
      name: 'Meetings',
      icon: CalendarDays,
      visible: () => canViewMeetings,
      submenu: [
        {
          name: 'Create Meeting',
          href: '/meetings/create',
          icon: Calendar,
          visible: () => can('meetings', 'create'),
        },
        {
          name: 'My Meetings',
          href: '/my-meetings',
          icon: CalendarCheck,
          visible: () => can('meetings', 'view'),
        },
        // Org-wide dashboard: only hr_manager / managing_director / super_admin
        // hold meetings:dashboard (migration 038).
        {
          name: 'Dashboard',
          href: '/meetings',
          icon: LayoutDashboard,
          visible: () => can('meetings', 'dashboard'),
        },
      ],
    },
    canManageLeave
      ? {
          name: 'LEAVE MANAGEMENT',
          icon: Calendar,
          visible: () => canViewLeave,
          submenu: [
            {
              name: 'Leave Applications',
              href: '/leave',
              icon: Calendar,
              visible: () => can('leave', 'view'),
            },
            {
              name: 'Manage Leave',
              href: '/leave/manage',
              icon: ClipboardList,
              visible: () => can('leave', 'manage'),
            },
            // Leave Profile is available to EVERY role with leave:view (own
            // record is auto-selected; data scope enforced server-side).
            {
              name: 'Employee Leave Profile',
              href: '/leave/profile',
              icon: User,
              visible: () => can('leave', 'view'),
            },
            // NOTE: 'Delegations' is intentionally NOT here. It is a top-level
            // entry below, so its count badge is always on screen.
          ],
        }
      : {
          name: 'Leave',
          icon: Calendar,
          visible: () => canViewLeave,
          submenu: [
            {
              name: 'Leave Applications',
              href: '/leave',
              icon: Calendar,
              visible: () => can('leave', 'view'),
            },
            // Self-service leave profile for all non-manager roles too.
            {
              name: 'Leave Profile',
              href: '/leave/profile',
              icon: User,
              visible: () => can('leave', 'view'),
            },
          ],
        },
    // Temporary Delegation / Acting Authority register (§24).
    //
    // Deliberately a TOP-LEVEL entry rather than a child of the Leave group:
    // submenu children are only rendered while their parent is expanded, so
    // burying it there meant the register was invisible until the user guessed
    // to open Leave — and the count badge that makes it worth opening had
    // nowhere to live. Acting-authority cover is its own concern, independent
    // of leave administration.
    //
    // Visible to every role EXCEPT officer, and scoped server-side to the
    // viewer's own org unit (see canViewDelegations above).
    {
      name: 'Delegations',
      href: '/delegations',
      icon: UserCheck,
      visible: () => canViewDelegations,
      // "Live now" count — the number a supervisor actually opens this page for.
      // Pending is surfaced too, because an unapproved request is the one thing
      // that is waiting on someone.
      badge: () => {
        const counts = delegationCounts;
        if (!counts) return null;
        const live = (counts.active || 0) + (counts.upcoming || 0);
        if (live === 0 && (counts.pending || 0) === 0) return null;
        const urgent = (counts.pending || 0) > 0;
        return {
          value: urgent ? counts.pending : live,
          urgent,
          title: urgent
            ? `${counts.pending} awaiting approval`
            : `${counts.active} active now · ${counts.upcoming} upcoming`,
        };
      },
    },
    {
      name: 'Roster',
      icon: CalendarRange,
      // Roster/Oversight show other employees' planned leave (§33): approver/HR
      // only. Each child is independently permission-checked (§18).
      // Phase 10: Roster is an HR-only module gated by the dedicated
      // leave:roster permission (hr_manager + super_admin by default) -
      // NOT leave:manage, which heads hold for scoped Leave Management.
      visible: () => can('leave', 'roster'),
      submenu: [
        {
          name: 'Leave Roster',
          href: '/leave/roster',
          icon: CalendarRange,
          visible: () => can('leave', 'roster'),
        },
        {
          name: 'Leave Oversight',
          href: '/leave/oversight',
          icon: BarChart3,
          visible: () => can('leave', 'roster'),
        },
      ],
    },
    {
      name: 'Appraisal',
      icon: Star,
      visible: () => can('performance', 'feedback') || canViewSupervisorAppraisals,
      submenu: [
        {
          name: 'My Appraisals',
          href: '/appraisal/my',
          icon: FileText,
          visible: () => can('performance', 'feedback'),
        },
        {
          name: 'Supervisor Appraisals',
          href: '/strategy/performance-appraisals',
          icon: ClipboardList,
          visible: () => canViewSupervisorAppraisals,
        },
        {
          // Completed Appraisals: read-only archive of finalised appraisals
          // with score breakdown + PDF/Word/print export. Supervisors see their
          // whole authorised scope; officers and staff (performance:feedback)
          // see ONLY their own, with no filters - that self-scope is enforced
          // server-side in AppraisalReportService, never in the client.
          name: 'Completed Appraisals',
          href: '/appraisal/completed',
          icon: FileBarChart2,
          visible: () => canViewCompletedAppraisals,
        },
      ],
    },
    ...(canViewStrategy
      ? [
          {
            name: 'Strategy & Performance',
            icon: Target,
            visible: () => canViewStrategy,
            submenu: [
              {
                name: 'Strategic Plan',
                href: '/strategy/strategic-plan',
                icon: Target,
                visible: () => can('strategic_plan', 'view'),
              },
              {
                name: 'Performance Contracts',
                href: '/strategy/performance-contracts',
                icon: FileText,
                visible: () => can('performance_contract', 'view'),
              },
              {
                name: 'Workplans',
                href: '/strategy/workplans',
                icon: ClipboardList,
                visible: () => canViewWorkplans,
              },
              {
                name: 'Sectional Objectives (KPIs)',
                href: '/strategy/kpis',
                icon: BarChart3,
                visible: () => can('sectional_objective', 'view'),
              },
              {
                name: 'Performance Reports',
                href: '/strategy/reports',
                icon: BarChart3,
                visible: () => can('strategic_plan', 'view'),
              },
            ],
          },
        ]
      : []),
    {
      name: 'Reports',
      icon: BarChart3,
      visible: () => canViewReports,
      submenu: [
        {
          name: 'Employee Reports',
          href: '/reports',
          icon: Users,
          visible: () => can('reports', 'view'),
        },
        {
          name: 'Attendance Reports',
          href: '/reports/attendance',
          icon: CalendarCheck,
          visible: () => can('reports', 'view'),
        },
        {
          name: 'Leave Reports',
          href: '/leave/reports',
          icon: FileBarChart2,
          visible: () => can('reports', 'view'),
        },
        {
          // Company-wide appraisal analytics: performance trends, unit averages
          // and outliers. Server-side scoping pins this to the caller's
          // organisational scope, so a section head's figures describe their own
          // unit - the link only reveals that the report exists.
          name: 'Appraisal Reports',
          href: '/reports/appraisal',
          icon: Star,
          visible: () => can('reports', 'view'),
        },
      ],
    },
    {
      name: 'HR Policies',
      href: '/hr/policies',
      icon: FileText,
      visible: () => can('hr_policies', 'view'),
    },
    {
      name: 'Settings',
      href: '/settings',
      icon: Settings,
      // §27/§29: the Settings entry follows the central registry — shown only
      // when the user holds ANY settings-related permission (page shell or
      // self-service notifications tab). Routes remain guarded independently.
      visible: () =>
        SETTINGS_VISIBILITY_PERMISSIONS.some((perm) => {
          const [m, a] = parsePermission(perm);
          return can(m, a);
        }),
    },
  ];

  // Groups only render when a child is visible; drop fully-hidden groups.
  const navigation = allNavigation
    .filter((item) => (item.submenu ? item.submenu.some((sub) => sub.visible()) : item.visible()))
    .map((item) => ({
      ...item,
      submenu: item.submenu ? item.submenu.filter((sub) => sub.visible()) : undefined,
    }));

  return (
    <>
      {/* Mobile overlay */}
      {isOpen && <div className="fixed inset-0 z-40 bg-black/50 lg:hidden" onClick={onClose} />}

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
              const isExpanded = expandedParent === item.name;
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
              );
            }
            // For routes with children, mark active on the prefix so the parent item lights up.
            const routeIsPrefix = location.pathname.startsWith(item.href);
            const badge = item.badge ? item.badge() : null;
            return (
              <NavLink
                key={item.name}
                to={item.href}
                end={!item.href.startsWith('/settings')}
                onClick={onClose}
                title={badge?.title}
                className={({ isActive }) =>
                  `flex items-center space-x-3 px-4 py-3 rounded-lg transition-colors ${
                    isActive || (item.href === '/settings' && routeIsPrefix)
                      ? 'bg-primary-50 dark:bg-slate-700 text-primary-700 dark:text-primary-300'
                      : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700'
                  }`
                }
              >
                <item.icon className="h-5 w-5" />
                <span className="font-medium flex-1">{item.name}</span>
                {/* Count badge — e.g. how many duty-cover arrangements are live
                    right now. Red when something is waiting on an approver. */}
                {badge && (
                  <span
                    className={`ml-2 min-w-[1.25rem] px-1.5 py-0.5 rounded-full text-xs font-semibold text-center ${
                      badge.urgent
                        ? 'bg-red-600 text-white'
                        : 'bg-primary-100 text-primary-800 dark:bg-primary-500/25 dark:text-primary-200'
                    }`}
                  >
                    {badge.value}
                  </span>
                )}
              </NavLink>
            );
          })}
        </nav>
      </div>
    </>
  );
};

export default Sidebar;
