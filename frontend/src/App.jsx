import { Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider } from './context/AuthContext'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import ConnectionStatus from './components/ConnectionStatus'

// Pages
import Login from './pages/Login'
import Dashboard from './pages/Dashboard.tsx'
import Employees from './pages/Employees'
import EmployeeProfile from './pages/EmployeeProfile'
import EmployeeForm from './pages/EmployeeForm'
import Departments from './pages/Departments'
import Attendance from './pages/Attendance'
import Leave from './pages/Leave'
import LeaveApplication from './pages/LeaveApplication'
import ManageLeaveLayout from './pages/ManageLeaveLayout'
import ManageLeavePendingTab from './pages/ManageLeavePendingTab'
import ManageLeaveApprovedTab from './pages/ManageLeaveApprovedTab'
import ManageLeaveRejectedTab from './pages/ManageLeaveRejectedTab'
import SettingsLayout from './pages/SettingsLayout'
import SettingsProfileTab from './pages/SettingsProfileTab'
import SettingsNotificationsTab from './pages/SettingsNotificationsTab'
import SettingsSecurityTab from './pages/SettingsSecurityTab'
import SettingsAuditTab from './pages/SettingsAuditTab'
import SettingsUsersTab from './pages/SettingsUsersTab'
import SettingsPermissionsTab from './pages/SettingsPermissionsTab'
import Admin from './pages/Admin'
import Appraisal from './pages/Appraisal.tsx'
import Audit from './pages/Audit'
import Consent from './pages/Consent'
import ConsentManagement from './pages/Consent'
import DataProtectionConsent from './pages/DataProtectionConsent'
import FinancialYear from './pages/FinancialYear'
import Reports from './pages/Reports'
import Profile from './pages/Profile'
import StrategicPlan from './pages/StrategicPlan'
import Holidays from './pages/Holidays'

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
          <Route path="consent_management" element={<ConsentManagement />} />
          <Route path="attendance" element={<Attendance />} />
          <Route path="leave" element={<Leave />} />
          <Route path="leave/apply" element={<LeaveApplication />} />
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
            <Route path="audit" element={<SettingsAuditTab />} />
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
        </Route>

        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </AuthProvider>
  )
}

export default App