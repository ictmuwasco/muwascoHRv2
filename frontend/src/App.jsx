import { Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider } from './context/AuthContext'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import ConnectionStatus from './components/ConnectionStatus'

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

// Pages - Standalone
import Dashboard from './pages/dashboard/Dashboard'
import Reports from './pages/reports/Reports'
import StrategicPlan from './pages/strategic-plan/StrategicPlan'

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
          <Route path="consent_management" element={<Consent />} />
          <Route path="attendance/dashboard" element={<AttendanceDashboard />} />
          <Route path="attendance" element={<Attendance />} />
          <Route path="leave" element={<Leave />} />
          <Route path="leave/apply" element={<LeaveApplication />} />
          <Route path="leave/profile" element={<LeaveProfile />} />
          <Route path="leave/roster" element={<LeaveRoster />} />
          <Route path="leave/oversight" element={<LeaveOversight />} />
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
          </Route>
          <Route path="admin" element={<Admin />} />
          <Route path="appraisal" element={<Appraisal />} />
          <Route path="audit" element={<Audit />} />
          <Route path="consent" element={<Consent />} />
          <Route path="reports" element={<Reports />} />
          <Route path="profile" element={<Profile />} />
          <Route path="strategic-plan" element={<StrategicPlan />} />
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
    </AuthProvider>
  )
}

export default App