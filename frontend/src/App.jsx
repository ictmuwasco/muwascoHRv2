import { Routes, Route, Navigate } from 'react-router-dom'
import React, { lazy, Suspense } from 'react'
import { AuthProvider, useAuth } from './context/AuthContext'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import ConnectionStatus from './components/ConnectionStatus'
import ErrorBoundary from './components/ErrorBoundary'
import PageLoader from './components/PageLoader'
import { PAGE_PERMISSIONS, firstPermittedRoute } from './config/pagePermissions'

// Eagerly loaded - needed immediately for initial render
import Login from './pages/auth/Login'
import DataProtectionConsent from './pages/auth/DataProtectionConsent'
import Dashboard from './pages/dashboard/Dashboard'

// Lazy loaded - Employee pages
const Employees = lazy(() => import('./pages/employee/Employees'))
const EmployeeProfile = lazy(() => import('./pages/employee/EmployeeProfile'))
const EmployeeForm = lazy(() => import('./pages/employee/EmployeeForm'))
const Profile = lazy(() => import('./pages/employee/Profile'))

// Lazy loaded - Leave pages
const Leave = lazy(() => import('./pages/leave/Leave'))
const LeaveApplication = lazy(() => import('./pages/leave/LeaveApplication'))
const LeaveRoster = lazy(() => import('./pages/leave/LeaveRoster'))
const LeaveOversight = lazy(() => import('./pages/leave/LeaveOversight'))
const LeaveProfile = lazy(() => import('./pages/leave/LeaveProfile'))
const ManageLeaveLayout = lazy(() => import('./pages/leave/ManageLeaveLayout'))
const ManageLeavePendingTab = lazy(() => import('./pages/leave/ManageLeavePendingTab'))
const ManageLeaveApprovedTab = lazy(() => import('./pages/leave/ManageLeaveApprovedTab'))
const ManageLeaveRejectedTab = lazy(() => import('./pages/leave/ManageLeaveRejectedTab'))
const Delegations = lazy(() => import('./pages/delegations/Delegations'))

// Lazy loaded - HR Admin pages
const FinancialYear = lazy(() => import('./pages/hr-admin/FinancialYear'))
const Consent = lazy(() => import('./pages/hr-admin/Consent'))
const Holidays = lazy(() => import('./pages/hr-admin/Holidays'))
const AppraisalCycles = lazy(() => import('./pages/hr-admin/AppraisalCycles'))
const Departments = lazy(() => import('./pages/hr-admin/Departments'))
const Attendance = lazy(() => import('./pages/attendance/Attendance'))
const AttendanceDashboard = lazy(() => import('./pages/attendance/AttendanceDashboard'))
const Appraisal = lazy(() => import('./pages/hr-admin/Appraisal'))

// Lazy loaded - Meeting pages
const MeetingsDashboard = lazy(() => import('./pages/meetings/MeetingsDashboard'))
const CreateMeeting = lazy(() => import('./pages/meetings/CreateMeeting'))
const MyMeetings = lazy(() => import('./pages/meetings/MyMeetings'))

// Lazy loaded - Settings pages
const Admin = lazy(() => import('./pages/settings/Admin'))
const Audit = lazy(() => import('./pages/settings/Audit'))
const ErrorMonitoring = lazy(() => import('./pages/settings/ErrorMonitoring'))

// Lazy loaded - Report pages
const Reports = lazy(() => import('./pages/reports/Reports'))
const AttendanceReport = lazy(() => import('./pages/reports/AttendanceReport'))
const LeaveReports = lazy(() => import('./pages/reports/LeaveReports'))
const StrategicPlan = lazy(() => import('./pages/strategic-plan/StrategicPlan'))

// Lazy loaded - Strategy & Performance pages
const PerformanceContracts = lazy(() => import('./pages/strategy/PerformanceContracts'))
const Workplans = lazy(() => import('./pages/strategy/Workplans'))
const StrategyReports = lazy(() => import('./pages/strategy/StrategyReports'))
const WorkplanTierRedirect = lazy(() => import('./pages/strategy/workplans/tierRouting').then(m => ({ default: m.WorkplanTierRedirect })))
const ManagingDirectorWorkplan = lazy(() => import('./pages/strategy/workplans/ManagingDirectorWorkplan'))
const DepartmentHeadWorkplan = lazy(() => import('./pages/strategy/workplans/DepartmentHeadWorkplan'))
const SectionHeadWorkplan = lazy(() => import('./pages/strategy/workplans/SectionHeadWorkplan'))
const SubsectionHeadWorkplan = lazy(() => import('./pages/strategy/workplans/SubsectionHeadWorkplan'))

// Settings components (eagerly loaded - small and frequently used)
import SettingsLayout, { SettingsIndexRedirect } from './components/settings/SettingsLayout'
import SettingsProfileTab from './components/settings/SettingsProfileTab'
import SettingsNotificationsTab from './components/settings/SettingsNotificationsTab'
import SettingsSecurityTab from './components/settings/SettingsSecurityTab'
import SettingsUsersTab from './components/settings/SettingsUsersTab'
import SettingsPermissionsTab from './components/settings/SettingsPermissionsTab'

const Guarded = ({ route, children }) => {
  const entry = PAGE_PERMISSIONS[route]
  return <ProtectedRoute permission={entry?.permission}>{children}</ProtectedRoute>
}

const SafeFallback = () => {
  const { can } = useAuth()
  return <Navigate to={firstPermittedRoute(can)} replace />
}

function App() {
  return (
    <AuthProvider>
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
          
          <Route path="employees" element={<Guarded route="/employees"><Suspense fallback={<PageLoader />}><Employees /></Suspense></Guarded>} />
          <Route path="employees/add" element={<Guarded route="/employees/add"><Suspense fallback={<PageLoader />}><EmployeeForm /></Suspense></Guarded>} />
          <Route path="employees/:id/edit" element={<Guarded route="/employees/:id/edit"><Suspense fallback={<PageLoader />}><EmployeeForm /></Suspense></Guarded>} />
          <Route path="employees/:id/profile" element={<Guarded route="/employees/:id/profile"><Suspense fallback={<PageLoader />}><EmployeeProfile /></Suspense></Guarded>} />
          
          <Route path="departments" element={<Guarded route="/departments"><Suspense fallback={<PageLoader />}><Departments /></Suspense></Guarded>} />
          <Route path="financial_year" element={<Guarded route="/financial_year"><Suspense fallback={<PageLoader />}><FinancialYear /></Suspense></Guarded>} />
          <Route path="appraisal_cycles" element={<Guarded route="/appraisal_cycles"><Suspense fallback={<PageLoader />}><AppraisalCycles /></Suspense></Guarded>} />
          <Route path="hr_admin/appraisal-cycles" element={<Guarded route="/hr_admin/appraisal-cycles"><Suspense fallback={<PageLoader />}><AppraisalCycles /></Suspense></Guarded>} />
          <Route path="consent_management" element={<Guarded route="/consent_management"><Suspense fallback={<PageLoader />}><Consent /></Suspense></Guarded>} />
          <Route path="attendance/dashboard" element={<Guarded route="/attendance/dashboard"><Suspense fallback={<PageLoader />}><AttendanceDashboard /></Suspense></Guarded>} />
          <Route path="attendance" element={<Guarded route="/attendance"><Suspense fallback={<PageLoader />}><Attendance /></Suspense></Guarded>} />
          
          <Route path="leave" element={<Guarded route="/leave"><Suspense fallback={<PageLoader />}><Leave /></Suspense></Guarded>} />
          <Route path="leave/apply" element={<Guarded route="/leave/apply"><Suspense fallback={<PageLoader />}><LeaveApplication /></Suspense></Guarded>} />
          <Route path="leave/profile" element={<Guarded route="/leave/profile"><Suspense fallback={<PageLoader />}><LeaveProfile /></Suspense></Guarded>} />
          <Route path="leave/roster" element={<Guarded route="/leave/roster"><Suspense fallback={<PageLoader />}><LeaveRoster /></Suspense></Guarded>} />
          <Route path="leave/oversight" element={<Guarded route="/leave/oversight"><Suspense fallback={<PageLoader />}><LeaveOversight /></Suspense></Guarded>} />
          <Route path="leave/reports" element={<Guarded route="/leave/reports"><Suspense fallback={<PageLoader />}><LeaveReports /></Suspense></Guarded>} />
          <Route path="leave/manage" element={<Guarded route="/leave/manage"><Suspense fallback={<PageLoader />}><ManageLeaveLayout /></Suspense></Guarded>}>
            <Route index element={<Navigate to="pending" replace />} />
            <Route path="pending" element={<Suspense fallback={<PageLoader />}><ManageLeavePendingTab /></Suspense>} />
            <Route path="approved" element={<Suspense fallback={<PageLoader />}><ManageLeaveApprovedTab /></Suspense>} />
            <Route path="rejected" element={<Suspense fallback={<PageLoader />}><ManageLeaveRejectedTab /></Suspense>} />
          </Route>
          
          <Route path="delegations" element={<Guarded route="/delegations"><Suspense fallback={<PageLoader />}><Delegations /></Suspense></Guarded>} />

          <Route path="admin" element={<Guarded route="/admin"><Suspense fallback={<PageLoader />}><Admin /></Suspense></Guarded>} />
          <Route path="appraisal" element={<Guarded route="/appraisal"><Suspense fallback={<PageLoader />}><Appraisal /></Suspense></Guarded>} />
          <Route path="audit" element={<Guarded route="/audit"><Suspense fallback={<PageLoader />}><Audit /></Suspense></Guarded>} />
          <Route path="consent" element={<Guarded route="/consent"><Suspense fallback={<PageLoader />}><Consent /></Suspense></Guarded>} />
          <Route path="reports/attendance" element={<Guarded route="/reports/attendance"><Suspense fallback={<PageLoader />}><AttendanceReport /></Suspense></Guarded>} />
          <Route path="reports" element={<Guarded route="/reports"><Suspense fallback={<PageLoader />}><Reports /></Suspense></Guarded>} />
          <Route path="profile" element={<Guarded route="/profile"><Suspense fallback={<PageLoader />}><Profile /></Suspense></Guarded>} />
          <Route path="strategic-plan" element={<Guarded route="/strategic-plan"><Suspense fallback={<PageLoader />}><StrategicPlan /></Suspense></Guarded>} />
          <Route path="strategy/strategic-plan" element={<Guarded route="/strategy/strategic-plan"><Suspense fallback={<PageLoader />}><StrategicPlan /></Suspense></Guarded>} />
          <Route path="strategy/performance-contracts" element={<Guarded route="/strategy/performance-contracts"><Suspense fallback={<PageLoader />}><PerformanceContracts /></Suspense></Guarded>} />
          
          <Route path="strategy/workplans" element={<Guarded route="/strategy/workplans"><Suspense fallback={<PageLoader />}><Workplans /></Suspense></Guarded>}>
            <Route index element={<Suspense fallback={<PageLoader />}><WorkplanTierRedirect /></Suspense>} />
            <Route path="managing-director" element={<Suspense fallback={<PageLoader />}><ManagingDirectorWorkplan /></Suspense>} />
            <Route path="department-head" element={<Suspense fallback={<PageLoader />}><DepartmentHeadWorkplan /></Suspense>} />
            <Route path="section-head" element={<Suspense fallback={<PageLoader />}><SectionHeadWorkplan /></Suspense>} />
            <Route path="subsection-head" element={<Suspense fallback={<PageLoader />}><SubsectionHeadWorkplan /></Suspense>} />
          </Route>
          
          <Route path="strategy/reports" element={<Guarded route="/strategy/reports"><Suspense fallback={<PageLoader />}><StrategyReports /></Suspense></Guarded>} />
          <Route path="holidays" element={<Guarded route="/holidays"><Suspense fallback={<PageLoader />}><Holidays /></Suspense></Guarded>} />
          <Route path="meetings" element={<Guarded route="/meetings"><Suspense fallback={<PageLoader />}><MeetingsDashboard /></Suspense></Guarded>} />
          <Route path="meetings/create" element={<Guarded route="/meetings/create"><Suspense fallback={<PageLoader />}><CreateMeeting /></Suspense></Guarded>} />
          <Route path="meetings/:id/edit" element={<Guarded route="/meetings/:id/edit"><Suspense fallback={<PageLoader />}><CreateMeeting /></Suspense></Guarded>} />
          <Route path="my-meetings" element={<Guarded route="/my-meetings"><Suspense fallback={<PageLoader />}><MyMeetings /></Suspense></Guarded>} />
          <Route path="meetings/:id/details" element={<Guarded route="/meetings/:id/details"><Suspense fallback={<PageLoader />}><MeetingsDashboard /></Suspense></Guarded>} />
          <Route path="meetings/:id/confirm" element={<Guarded route="/meetings/:id/confirm"><Suspense fallback={<PageLoader />}><MeetingsDashboard /></Suspense></Guarded>} />
          
          <Route path="settings" element={<Guarded route="/settings"><SettingsLayout /></Guarded>}>
            <Route index element={<SettingsIndexRedirect />} />
            <Route path="profile" element={<Guarded route="/settings/profile"><SettingsProfileTab /></Guarded>} />
            <Route path="notifications" element={<Guarded route="/settings/notifications"><SettingsNotificationsTab /></Guarded>} />
            <Route path="security" element={<Guarded route="/settings/security"><SettingsSecurityTab /></Guarded>} />
            <Route path="audit" element={<Guarded route="/settings/audit"><Suspense fallback={<PageLoader />}><Audit /></Suspense></Guarded>} />
            <Route path="users" element={<Guarded route="/settings/users"><SettingsUsersTab /></Guarded>} />
            <Route path="permissions" element={<Guarded route="/settings/permissions"><SettingsPermissionsTab /></Guarded>} />
            <Route path="monitoring" element={<Guarded route="/settings/monitoring"><Suspense fallback={<PageLoader />}><ErrorMonitoring /></Suspense></Guarded>} />
          </Route>
        </Route>

        <Route path="*" element={<SafeFallback />} />
      </Routes>
      </ErrorBoundary>
    </AuthProvider>
  )
}

export default App
