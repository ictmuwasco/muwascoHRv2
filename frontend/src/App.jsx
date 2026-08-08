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
import Users from './pages/Users'
import Settings from './pages/Settings'
import Admin from './pages/Admin'
import Appraisal from './pages/Appraisal.tsx'
import Audit from './pages/Audit'
import Consent from './pages/Consent'
import ConsentManagement from './pages/Consent'
import FinancialYear from './pages/FinancialYear'
import Reports from './pages/Reports'
import Profile from './pages/Profile'
import StrategicPlan from './pages/StrategicPlan'

function App() {
  return (
    <AuthProvider>
      <ConnectionStatus />
      <Routes>
        <Route path="/login" element={<Login />} />
        
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
          <Route path="users" element={<Users />} />
          <Route path="settings" element={<Settings />} />
          <Route path="admin" element={<Admin />} />
          <Route path="appraisal" element={<Appraisal />} />
          <Route path="audit" element={<Audit />} />
          <Route path="consent" element={<Consent />} />
          <Route path="reports" element={<Reports />} />
          <Route path="profile" element={<Profile />} />
          <Route path="strategic-plan" element={<StrategicPlan />} />
        </Route>

        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </AuthProvider>
  )
}

export default App