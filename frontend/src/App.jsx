import { Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import ConnectionStatus from './components/ConnectionStatus'
import ErrorBoundary from './components/ErrorBoundary'
import { PAGE_PERMISSIONS, firstPermittedRoute } from './config/pagePermissions'

// Pages - Auth
import Login from './pages/auth/Login'
import DataProtectionConsent from './pages/auth/DataProtectionConsent'

// Pages - Employee
import Employees from './pages/employee/Employees'
import EmployeeProfile from './pages/employee/EmployeeProfile'
import EmployeeForm from './pages/employee/EmployeeForm'
import Profile from './pages/employee/Profile'

// Pages - Leave
import Leave from './pages/leave/Leave'
import LeaveApplication from './pages/leave/LeaveApplication'
import LeaveRoster from './pages/leave/LeaveRoster'
import LeaveOversight from './pages/leave/LeaveOversight'
import LeaveProfile from './pages/leave/LeaveProfile'
import ManageLeaveLayout from './pages/leave/ManageLeaveLayout'
import ManageLeavePendingTab from './pages/leave/ManageLeavePendingTab'
import ManageLeaveApprovedTab from './pages/leave/ManageLeaveApprovedTab'
import ManageLeaveRejectedTab from './pages/leave/ManageLeaveRejectedTab'
import Delegations from './pages/delegations/Delegations'

// Pages - HR Admin
import FinancialYear from './pages/hr-admin/FinancialYear'
import Consent from './pages/hr-admin/Consent'
import Holidays from './pages/hr-admin/Holidays'
import AppraisalCycles from './pages/hr-admin/AppraisalCycles'
import Departments from './pages/hr-admin/Departments'
import Attendance from './pages/attendance/Attendance'
import AttendanceDashboard from './pages/attendance/AttendanceDashboard'
import Appraisal from './pages/hr-admin/Appraisal'

// Pages - Meetings
import MeetingsDashboard from './pages/meetings/MeetingsDashboard'
import CreateMeeting from './pages/meetings/CreateMeeting'
import MyMeetings from './pages/meetings/MyMeetings'

// Pages - Settings
import Admin from './pages/settings/Admin'
import Audit from './pages/settings/Audit'
import ErrorMonitoring from './pages/settings/ErrorMonitoring'

// Pages - Standalone
import Dashboard from './pages/dashboard/Dashboard'
import Reports from './pages/reports/Reports'
import AttendanceReport from './pages/reports/AttendanceReport'
import LeaveReports from './pages/reports/LeaveReports'
import StrategicPlan from './pages/strategic-plan/StrategicPlan'

// Pages - Strategy & Performance
import PerformanceContracts from './pages/strategy/PerformanceContracts'
import Workplans from './pages/strategy/Workplans'
import StrategyReports from './pages/strategy/StrategyReports'
import { WorkplanTierRedirect } from './pages/strategy/workplans/tierRouting'
import ManagingDirectorWorkplan from './pages/strategy/workplans/ManagingDirectorWorkplan'
import DepartmentHeadWorkplan from './pages/strategy/workplans/DepartmentHeadWorkplan'
import SectionHeadWorkplan from './pages/strategy/workplans/SectionHeadWorkplan'
import SubsectionHeadWorkplan from './pages/strategy/workplans/SubsectionHeadWorkplan'

// Settings components
import SettingsLayout, { SettingsIndexRedirect } from './components/settings/SettingsLayout'
import SettingsProfileTab from './components/settings/SettingsProfileTab'
import SettingsNotificationsTab from './components/settings/SettingsNotificationsTab'
import SettingsSecurityTab from './components/settings/SettingsSecurityTab'
import SettingsUsersTab from './components/settings/SettingsUsersTab'
import SettingsPermissionsTab from './components/settings/SettingsPermissionsTab'

/**
 * Guarded route element (Phase: Role, Page & Permission restriction).
 *
 * Every route declares its required permission in the central Page Permission
 * Registry (config/pagePermissions.jsx) - the single source of truth shared
 * with the Sidebar and the Settings layout. The guard renders an Access
 * Denied screen when the effective permission set (from /auth/user) does not
 * include it. UX ONLY: the backend authorizes every API request
 * independently, so direct URL access can never fetch unauthorized data.
 */
const Guarded = ({ route, children }) => {
  const entry = PAGE_PERMISSIONS[route]
  return <ProtectedRoute permission={entry?.permission}>{children}</ProtectedRoute>
}

/**
 * Safe fallback for unknown routes: land the user on the first route their
 * effective permissions allow (never a redirect loop into a denied page).
 */
const SafeFallback = () => {
  const { can } = useAuth()
  return <Navigate to={firstPermittedRoute(can)} replace />
}

function App() {
  return (
    <AuthProvider>
      {/* Global crash catcher: any uncaught render error becomes a friendly,
          reference-coded screen instead of a blank page. */}
      <ErrorBoundary>
      <ConnectionStatus />
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/data-protection-consent" element={<DataProtectionConsent />} />

        <Route path="/" element={
          <ProtectedRoute>
            <Layout />
          </ProtectedRoute>
        }>
          <Route index element={<Navigate to="/dashboard" replace />} />
          <Route path="dashboard" element={<Guarded route="/dashboard"><Dashboard /></Guarded>} />
          <Route path="employees" element={<Guarded route="/employees"><Employees /></Guarded>} />
          <Route path="employees/add" element={<Guarded route="/employees/add"><EmployeeForm /></Guarded>} />
          <Route path="employees/:id/edit" element={<Guarded route="/employees/:id/edit"><EmployeeForm /></Guarded>} />
          <Route path="employees/:id/profile" element={<Guarded route="/employees/:id/profile"><EmployeeProfile /></Guarded>} />
          <Route path="departments" element={<Guarded route="/departments"><Departments /></Guarded>} />
          <Route path="financial_year" element={<Guarded route="/financial_year"><FinancialYear /></Guarded>} />
          <Route path="appraisal_cycles" element={<Guarded route="/appraisal_cycles"><AppraisalCycles /></Guarded>} />
          <Route path="hr_admin/appraisal-cycles" element={<Guarded route="/hr_admin/appraisal-cycles"><AppraisalCycles /></Guarded>} />
          <Route path="consent_management" element={<Guarded route="/consent_management"><Consent /></Guarded>} />
          <Route path="attendance/dashboard" element={<Guarded route="/attendance/dashboard"><AttendanceDashboard /></Guarded>} />
          <Route path="attendance" element={<Guarded route="/attendance"><Attendance /></Guarded>} />
          <Route path="leave" element={<Guarded route="/leave"><Leave /></Guarded>} />
          <Route path="leave/apply" element={<Guarded route="/leave/apply"><LeaveApplication /></Guarded>} />
          <Route path="leave/profile" element={<Guarded route="/leave/profile"><LeaveProfile /></Guarded>} />
          <Route path="leave/roster" element={<Guarded route="/leave/roster"><LeaveRoster /></Guarded>} />
          <Route path="leave/oversight" element={<Guarded route="/leave/oversight"><LeaveOversight /></Guarded>} />
          <Route path="leave/reports" element={<Guarded route="/leave/reports"><LeaveReports /></Guarded>} />
          <Route path="leave/manage" element={<Guarded route="/leave/manage"><ManageLeaveLayout /></Guarded>}>
            <Route index element={<Navigate to="pending" replace />} />
            <Route path="pending" element={<ManageLeavePendingTab />} />
            <Route path="approved" element={<ManageLeaveApprovedTab />} />
            <Route path="rejected" element={<ManageLeaveRejectedTab />} />
          </Route>

          {/* Temporary Delegation / Acting Authority: supervisors appoint a
              delegate whose effective permissions temporarily include the
              delegated authority (backend-enforced, time-bound, scope-aware).
              Visibility/route gate: delegations:view (all roles — delegates
              need to see their grants; creation is gated inside the page). */}
          <Route path="delegations" element={<Guarded route="/delegations"><Delegations /></Guarded>} />

          {/* Settings module: individually protected tabs (Section 27). The
              layout picks the landing tab; each tab route is guarded with its
              own settings:<tab> permission. */}
          <Route path="settings" element={<SettingsLayout />}>
            <Route index element={<SettingsIndexRedirect />} />
            <Route path="profile" element={<Guarded route="/settings/profile"><SettingsProfileTab /></Guarded>} />
            <Route path="notifications" element={<Guarded route="/settings/notifications"><SettingsNotificationsTab /></Guarded>} />
            <Route path="security" element={<Guarded route="/settings/security"><SettingsSecurityTab /></Guarded>} />
            <Route path="audit" element={<Guarded route="/settings/audit"><Audit /></Guarded>} />
            <Route path="users" element={<Guarded route="/settings/users"><SettingsUsersTab /></Guarded>} />
            <Route path="permissions" element={<Guarded route="/settings/permissions"><SettingsPermissionsTab /></Guarded>} />
            <Route path="monitoring" element={<Guarded route="/settings/monitoring"><ErrorMonitoring /></Guarded>} />
          </Route>

          <Route path="admin" element={<Guarded route="/admin"><Admin /></Guarded>} />
          <Route path="appraisal" element={<Guarded route="/appraisal"><Appraisal /></Guarded>} />
          <Route path="audit" element={<Guarded route="/audit"><Audit /></Guarded>} />
          <Route path="consent" element={<Guarded route="/consent"><Consent /></Guarded>} />
          <Route path="reports/attendance" element={<Guarded route="/reports/attendance"><AttendanceReport /></Guarded>} />
          <Route path="reports" element={<Guarded route="/reports"><Reports /></Guarded>} />
          <Route path="profile" element={<Guarded route="/profile"><Profile /></Guarded>} />
          <Route path="strategic-plan" element={<Guarded route="/strategic-plan"><StrategicPlan /></Guarded>} />
          <Route path="strategy/strategic-plan" element={<Guarded route="/strategy/strategic-plan"><StrategicPlan /></Guarded>} />
          <Route path="strategy/performance-contracts" element={<Guarded route="/strategy/performance-contracts"><PerformanceContracts /></Guarded>} />
          {/* Cascading workplan system: one module, four role-scoped tiers.
              The index route bounces each user to their own level; the
              backend WorkplanController::availableViews() remains the
              authorization authority for tier data. */}
          <Route path="strategy/workplans" element={<Guarded route="/strategy/workplans"><Workplans /></Guarded>}>
            <Route index element={<WorkplanTierRedirect />} />
            <Route path="managing-director" element={<Guarded route="/strategy/workplans/managing-director"><ManagingDirectorWorkplan /></Guarded>} />
            <Route path="department-head" element={<Guarded route="/strategy/workplans/department-head"><DepartmentHeadWorkplan /></Guarded>} />
            <Route path="section-head" element={<Guarded route="/strategy/workplans/section-head"><SectionHeadWorkplan /></Guarded>} />
            <Route path="subsection-head" element={<Guarded route="/strategy/workplans/subsection-head"><SubsectionHeadWorkplan /></Guarded>} />
          </Route>
          <Route path="strategy/reports" element={<Guarded route="/strategy/reports"><StrategyReports /></Guarded>} />
          <Route path="holidays" element={<Guarded route="/holidays"><Holidays /></Guarded>} />
          <Route path="meetings" element={<Guarded route="/meetings"><MeetingsDashboard /></Guarded>} />
          <Route path="meetings/create" element={<Guarded route="/meetings/create"><CreateMeeting /></Guarded>} />
          <Route path="meetings/:id/edit" element={<Guarded route="/meetings/:id/edit"><CreateMeeting /></Guarded>} />
          <Route path="my-meetings" element={<Guarded route="/my-meetings"><MyMeetings /></Guarded>} />
          <Route path="meetings/:id/details" element={<Guarded route="/meetings/:id/details"><MeetingsDashboard /></Guarded>} />
          <Route path="meetings/:id/confirm" element={<Guarded route="/meetings/:id/confirm"><MeetingsDashboard /></Guarded>} />
        </Route>

        <Route path="*" element={<SafeFallback />} />
      </Routes>
      </ErrorBoundary>
    </AuthProvider>
  )
}

export default App
