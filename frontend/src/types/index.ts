// ============================================================
// MUWASCO HR Management System - TypeScript Type Definitions
// ============================================================

// --- Authentication ---
export interface User {
  id: number;
  employee_id: string;
  first_name: string;
  last_name: string;
  surname: string;
  email: string;
  role: string;
  designation: string;
  phone: string;
  address: string;
  gender: string;
  is_active: boolean;
  last_login: string | null;
  created_at: string;
  updated_at: string;
}

export interface LoginResponse {
  token: string;
  user: User;
}

export interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  loading: boolean;
}

// --- Employee ---
export interface Employee {
  id: number;
  employee_id: string;
  first_name: string;
  last_name: string;
  surname: string;
  email: string;
  phone: string;
  national_id: string;
  gender: string;
  marital_status: string;
  employee_type: string;
  employee_status: string;
  employment_date: string;
  department_id: number;
  department: string;
  section_id: number;
  section: string;
  office_id: number;
  office: string;
  designation: string;
  address: string;
  created_at: string;
  updated_at: string;
}

export interface EmployeeFormData {
  employee_id: string;
  first_name: string;
  last_name: string;
  surname: string;
  email: string;
  phone: string;
  national_id: string;
  gender: string;
  marital_status: string;
  employee_type: string;
  employee_status: string;
  employment_date: string;
  department_id: number;
  section_id: number;
  office_id: number;
  designation: string;
  address: string;
}

// --- Department ---
export interface Department {
  id: number;
  name: string;
  description: string;
  status: string;
  created_at: string;
  updated_at: string;
}

export interface DepartmentFormData {
  name: string;
  description: string;
  status: string;
}

// --- Section ---
export interface Section {
  id: number;
  department_id: number;
  name: string;
  description: string;
  status: string;
  created_at: string;
  updated_at: string;
}

// --- Office ---
export interface Office {
  id: number;
  section_id: number;
  name: string;
  description: string;
  status: string;
  created_at: string;
  updated_at: string;
}

// --- Attendance ---
export interface Attendance {
  id: number;
  employee_id: number;
  employee_name: string;
  date: string;
  clock_in_time: string;
  clock_out_time: string;
  status: 'Present' | 'Late' | 'Absent' | 'Half-Day';
  notes: string;
  created_at: string;
  updated_at: string;
}

export interface AttendanceFormData {
  employee_id: number;
  date: string;
  clock_in_time: string;
  clock_out_time: string;
  status: string;
  notes: string;
}

// --- Leave ---
export interface LeaveRequest {
  id: number;
  employee_id: number;
  employee_name: string;
  leave_type_id: number;
  leave_type: string;
  start_date: string;
  end_date: string;
  days_requested: number;
  reason: string;
  status: 'Pending' | 'Approved' | 'Rejected' | 'Cancelled';
  applied_at: string;
  approved_at: string | null;
  approved_by: string | null;
  created_at: string;
  updated_at: string;
}

export interface LeaveFormData {
  leave_type_id: number;
  start_date: string;
  end_date: string;
  reason: string;
}

export interface LeaveType {
  id: number;
  name: string;
  days_allowed: number;
  description: string;
}

export interface LeaveBalance {
  leave_type_id: number;
  leave_type: string;
  total_days: number;
  used_days: number;
  remaining_days: number;
}

// --- Holiday ---
export interface Holiday {
  id: number;
  name: string;
  date: string;
  type: string;
  created_at: string;
}

// --- Appraisal ---
export interface Appraisal {
  id: number;
  employee_id: number;
  employee_name: string;
  cycle_id: number;
  cycle_name: string;
  supervisor_id: number;
  supervisor_name: string;
  overall_score: number;
  status: 'Pending' | 'In Progress' | 'Completed' | 'Escalated';
  period_start: string;
  period_end: string;
  created_at: string;
  updated_at: string;
}

export interface AppraisalFormData {
  employee_id: number;
  cycle_id: number;
  supervisor_id: number;
  scores: Record<string, number>;
  comments: string;
}

// --- Strategic Plan ---
export interface StrategicPlan {
  id: number;
  name: string;
  description: string;
  start_date: string;
  end_date: string;
  status: string;
  created_at: string;
}

export interface Workplan {
  id: number;
  strategic_plan_id: number;
  employee_id: number;
  objective: string;
  activities: string;
  target_date: string;
  status: string;
  created_at: string;
}

export interface KPI {
  id: number;
  workplan_id: number;
  name: string;
  target: number;
  actual: number;
  weight: number;
  status: string;
  created_at: string;
}

// --- Financial Year ---
export interface FinancialYear {
  id: number;
  year: string;
  start_date: string;
  end_date: string;
  days: number;
  status: string;
  period: string;
  created_at: string;
}

export interface FinancialYearFormData {
  start_date: string;
  end_date: string;
}

// --- Notification ---
export interface Notification {
  id: number;
  user_id: number;
  title: string;
  message: string;
  type: 'info' | 'success' | 'warning' | 'error';
  is_read: boolean;
  created_at: string;
}

// --- Audit Log ---
export interface AuditLog {
  id: number;
  user_id: number;
  user_name: string;
  action: string;
  resource: string;
  resource_id: number;
  details: string;
  ip_address: string;
  created_at: string;
}

// --- Permission ---
export interface Permission {
  id: number;
  role: string;
  resource: string;
  action: string;
  created_at: string;
}

export interface RolePermission {
  role: string;
  permissions: Permission[];
}

// --- Report ---
export interface Report {
  id: number;
  name: string;
  type: string;
  parameters: Record<string, any>;
  generated_by: number;
  generated_at: string;
  file_path: string;
  status: string;
}

// --- Dashboard ---
export interface DashboardStats {
  total_employees: number;
  present_today: number;
  on_leave: number;
  pending_approvals: number;
  new_hires: number;
  departures: number;
  attendance_rate: number;
}

export interface ChartData {
  labels: string[];
  datasets: {
    label: string;
    data: number[];
    backgroundColor?: string;
    borderColor?: string;
  }[];
}

// --- API Response ---
export interface ApiResponse<T = any> {
  success: boolean;
  message: string;
  data: T;
  errors?: Record<string, string[]>;
}

export interface PaginatedResponse<T = any> {
  success: boolean;
  message: string;
  data: T[];
  pagination: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
    from: number;
    to: number;
  };
}

// --- Consent ---
export interface Consent {
  id: number;
  user_id: number;
  type: string;
  status: boolean;
  agreed_at: string;
  ip_address: string;
}

// --- Profile ---
export interface EmployeeProfile {
  personal: Employee;
  employment: {
    department: string;
    section: string;
    office: string;
    designation: string;
    employee_type: string;
    employee_status: string;
    employment_date: string;
  };
  next_of_kin: {
    name: string;
    relationship: string;
    phone: string;
    email: string;
    address: string;
  };
  documents: Document[];
}

export interface Document {
  id: number;
  employee_id: number;
  name: string;
  type: string;
  file_path: string;
  uploaded_at: string;
}

// --- Delegate ---
export interface Delegate {
  id: number;
  employee_id: number;
  delegate_id: number;
  delegate_name: string;
  start_date: string;
  end_date: string;
  is_active: boolean;
  created_at: string;
}