import { Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider } from './context/AuthContext'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import ConnectionStatus from './components/ConnectionStatus'
import ErrorBoundary from './components/ErrorBoundary'

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
import SettingsLayout from './components/settings/SettingsLayout'
import SettingsProfileTab from './components/settings/SettingsProfileTab'
import SettingsNotificationsTab from './components/settings/SettingsNotificationsTab'
import SettingsSecurityTab from './components/settings/SettingsSecurityTab'
import SettingsUsersTab from './components/settings/SettingsUsersTab'
import SettingsPermissionsTab from './components/settings/SettingsPermissionsTab'

function App() {
  return (
    <AuthProvider>
      {/* Global crash catcher: any uncaught render error becomes a friendly,
          reference-coded screen instead of a blank page (§33). */}
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
          <Route path="dashboard" element={<Dashboard />} />
          <Route path="employees" element={<Employees />} />
          <Route path="employees/add" element={<EmployeeForm />} />
          <Route path="employees/:id/edit" element={<EmployeeForm />} />
          <Route path="employees/:id/profile" element={<EmployeeProfile />} />
          <Route path="departments" element={<Departments />} />
          <Route path="financial_year" element={<FinancialYear />} />
          <Route path="appraisal_cycles" element={<AppraisalCycles />} />
          <Route path="hr_admin/appraisal-cycles" element={<AppraisalCycles />} />
          <Route path="consent_management" element={<Consent />} />
          <Route path="attendance/dashboard" element={<AttendanceDashboard />} />
          <Route path="attendance" element={<Attendance />} />
          <Route path="leave" element={<Leave />} />
          <Route path="leave/apply" element={<LeaveApplication />} />
          <Route path="leave/profile" element={<LeaveProfile />} />
          <Route path="leave/roster" element={<LeaveRoster />} />
          <Route path="leave/oversight" element={<LeaveOversight />} />
          <Route path="leave/reports" element={<LeaveReports />} />
          <Route path="leave/manage" element={<ManageLeaveLayout />}>
            <Route index element={<Navigate to="pending" replace />} />
            <Route path="pending" element={<ManageLeavePendingTab />} />
            <Route path="approved" element={<ManageLeaveApprovedTab />} />
            <Route path="rejected" element={<ManageLeaveRejectedTab />} />
          </Route>
          <Route path="settings" element={<SettingsLayout />}>
            <Route index element={<Navigate to="profile" replace />} />
            <Route path="profile" element={<SettingsProfileTab />} />
            <Route path="notifications" element={<SettingsNotificationsTab />} />
            <Route path="security" element={<SettingsSecurityTab />} />
            <Route path="audit" element={<Audit />} />
            <Route path="users" element={<SettingsUsersTab />} />
            <Route path="permissions" element={<SettingsPermissionsTab />} />
            <Route path="monitoring" element={<ErrorMonitoring />} />
          </Route>
          <Route path="admin" element={<Admin />} />
          <Route path="appraisal" element={<Appraisal />} />
          <Route path="audit" element={<Audit />} />
          <Route path="consent" element={<Consent />} />
          <Route path="reports/attendance" element={<AttendanceReport />} />
          <Route path="reports" element={<Reports />} />
          <Route path="profile" element={<Profile />} />
          <Route path="strategic-plan" element={<StrategicPlan />} />
          <Route path="strategy/strategic-plan" element={<StrategicPlan />} />
          <Route path="strategy/performance-contracts" element={<PerformanceContracts />} />
          {/* Cascading workplan system: one module, four role-scoped tiers.
              The index route bounces each user to their own level. */}
          <Route path="strategy/workplans" element={<Workplans />}>
            <Route index element={<WorkplanTierRedirect />} />
            <Route path="managing-director" element={<ManagingDirectorWorkplan />} />
            <Route path="department-head" element={<DepartmentHeadWorkplan />} />
            <Route path="section-head" element={<SectionHeadWorkplan />} />
            <Route path="subsection-head" element={<SubsectionHeadWorkplan />} />
          </Route>
          <Route path="strategy/reports" element={<StrategyReports />} />
          <Route path="holidays" element={<Holidays />} />
          <Route path="meetings" element={<MeetingsDashboard />} />
          <Route path="meetings/create" element={<CreateMeeting />} />
          <Route path="meetings/:id/edit" element={<CreateMeeting />} />
          <Route path="my-meetings" element={<MyMeetings />} />
          <Route path="meetings/:id/details" element={<MeetingsDashboard />} />
          <Route path="meetings/:id/confirm" element={<MeetingsDashboard />} />
        </Route>

        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
      </ErrorBoundary>
    </AuthProvider>
  )
}

export default App