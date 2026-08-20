# Page Documentation

This document provides detailed documentation for all application pages, organized by feature area.

## Table of Contents

- [Frontend Pages](#frontend-pages)
  - [Auth Pages](#auth-pages)
  - [Employee Pages](#employee-pages)
  - [Leave Pages](#leave-pages)
  - [HR Admin Pages](#hr-admin-pages)
  - [Meetings Pages](#meetings-pages)
  - [Settings Pages](#settings-pages)
  - [Standalone Pages](#standalone-pages)
- [Backend Controllers](#backend-controllers)
  - [Auth Controllers](#auth-controllers)
  - [Employee Controllers](#employee-controllers)
  - [Leave Controllers](#leave-controllers)
  - [HR Controllers](#hr-controllers)
  - [Meeting Controllers](#meeting-controllers)
  - [Settings Controllers](#settings-controllers)
  - [Standalone Controllers](#standalone-controllers)

---

## Frontend Pages

### Auth Pages

Located in `frontend/src/pages/auth/`

#### Login (`Login.jsx`)
- **Purpose**: User authentication entry point
- **Features**:
  - Email/password login form
  - JWT token storage via AuthContext
  - Redirects to dashboard on successful login
  - Error handling for invalid credentials
- **Dependencies**: `AuthContext`, `Logo` component, `lucide-react` icons

#### Data Protection Consent (`DataProtectionConsent.tsx`)
- **Purpose**: GDPR/data protection consent form for new employees
- **Features**:
  - Employee ID verification
  - Consent form with terms and conditions
  - Consent submission and storage
  - Responsive form layout
- **Dependencies**: `AuthContext`, `consentService`, `lucide-react` icons

### Employee Pages

Located in `frontend/src/pages/employee/`

#### Employees (`Employees.jsx`)
- **Purpose**: List and manage all employees
- **Features**:
  - Paginated employee table
  - Search and filter functionality
  - Add/edit/delete employee actions
  - Employee status management
- **Dependencies**: `api` client, `EmployeeTabs` component, UI components

#### Employee Profile (`EmployeeProfile.jsx`)
- **Purpose**: View detailed employee profile
- **Features**:
  - Employee personal information display
  - Contact details
  - Employment information
  - Profile document management
- **Dependencies**: `api` client, `EmployeeTabs` component, UI components

#### Employee Form (`EmployeeForm.tsx`)
- **Purpose**: Create and edit employee records
- **Features**:
  - Form with validation
  - Employee tab-based sections
  - File upload for profile documents
  - Save and cancel actions
- **Dependencies**: `api` client, `EmployeeTabs` component, UI components

#### Profile (`Profile.tsx`)
- **Purpose**: User's own profile management
- **Features**:
  - View and edit personal profile
  - Password change functionality
  - Profile picture upload
  - Contact information management
- **Dependencies**: `api/client`, UI components, `lucide-react` icons

### Leave Pages

Located in `frontend/src/pages/leave/`

#### Leave (`Leave.jsx`)
- **Purpose**: Main leave management dashboard
- **Features**:
  - Leave balance overview
  - Leave type listing
  - Apply for leave button
  - Leave history table
- **Dependencies**: `api` client, UI components, `lucide-react` icons

#### Leave Application (`LeaveApplication.jsx`)
- **Purpose**: Submit new leave applications
- **Features**:
  - Leave type selection
  - Date range picker
  - Reason/description input
  - Delegate selection for approval
  - Document attachment
- **Dependencies**: `AuthContext`, `api` client, UI components, `lucide-react` icons

#### Leave Roster (`LeaveRoster.jsx`)
- **Purpose**: View leave roster and scheduling
- **Features**:
  - Month-based navigation
  - Employee roster table
  - Coverage bar visualization
  - Planning matrix view
  - Schedule slide-over panel
- **Dependencies**: `api` client, leave components, `leaveConstants`, `lucide-react` icons

#### Leave Oversight (`LeaveOversight.jsx`)
- **Purpose**: HR oversight of leave applications
- **Features**:
  - Leave statistics dashboard
  - Coverage analysis
  - Planning matrix
  - Distribution charts
  - Department-level views
- **Dependencies**: `api` client, leave components, `leaveConstants`, `lucide-react` icons

#### Manage Leave Layout (`ManageLeaveLayout.jsx`)
- **Purpose**: Layout wrapper for managing leave applications
- **Features**:
  - Tab-based navigation (Pending, Approved, Rejected)
  - Context provider for shared state
  - Alert banners for important notifications
- **Dependencies**: `AuthContext`, `api` client, UI components, `lucide-react` icons

#### Manage Leave Pending Tab (`ManageLeavePendingTab.jsx`)
- **Purpose**: View and manage pending leave applications
- **Features**:
  - List of pending applications
  - Approve/reject actions
  - Pagination
  - Status badges
- **Dependencies**: `api` client, `leaveManageShared.jsx`, UI components, `lucide-react` icons

#### Manage Leave Approved Tab (`ManageLeaveApprovedTab.jsx`)
- **Purpose**: View approved leave applications
- **Features**:
  - List of approved applications
  - Export functionality
  - Download options
  - Calendar view
- **Dependencies**: `api` client, `leaveManageShared.jsx`, UI components, `lucide-react` icons

#### Manage Leave Rejected Tab (`ManageLeaveRejectedTab.jsx`)
- **Purpose**: View rejected leave applications
- **Features**:
  - List of rejected applications
  - Status display
  - Pagination
- **Dependencies**: `api` client, `leaveManageShared.jsx`, UI components, `lucide-react` icons

#### Leave Manage Shared (`leaveManageShared.jsx`)
- **Purpose**: Shared utilities for Manage Leave tabs
- **Features**:
  - Badge class helpers
  - Date formatting utilities
  - Status formatting
  - Pagination component
  - Constants (ROWS_PER_PAGE)
- **Dependencies**: UI components, `lucide-react` icons

### HR Admin Pages

Located in `frontend/src/pages/hr-admin/`

#### Financial Year (`FinancialYear.jsx`)
- **Purpose**: Manage financial years and leave allocations
- **Features**:
  - Financial year status cards
  - Create new financial year
  - Leave allocation management
  - Financial year table view
- **Dependencies**: `financialYearService`, financial-year components, `lucide-react` icons

#### Consent (`Consent.tsx`)
- **Purpose**: HR consent management dashboard
- **Features**:
  - Consent status overview
  - Employee consent tracking
  - Consent verification
  - Dashboard statistics
- **Dependencies**: `consentService`, UI components, `lucide-react` icons

#### Holidays (`Holidays.jsx`)
- **Purpose**: Manage company holidays
- **Features**:
  - Holiday list with search
  - Add/edit/delete holidays
  - Holiday status management
  - Calendar view
- **Dependencies**: `api` client, UI components, `lucide-react` icons

### Meetings Pages

Located in `frontend/src/pages/meetings/`

#### Meetings Dashboard (`MeetingsDashboard.tsx`)
- **Purpose**: Overview of all meetings
- **Features**:
  - Meeting list with status
  - Meeting creation
  - Participant management
  - Meeting details view
- **Dependencies**: `api` client, UI components, `lucide-react` icons

#### Create Meeting (`CreateMeeting.tsx`)
- **Purpose**: Create and edit meetings
- **Features**:
  - Meeting form with validation
  - Participant selection
  - Date/time picker
  - Meeting type selection
  - Save and cancel actions
- **Dependencies**: `api` client, UI components, `lucide-react` icons

#### My Meetings (`MyMeetings.tsx`)
- **Purpose**: View meetings assigned to the current user
- **Features**:
  - Personal meeting list
  - Meeting status tracking
  - Attendance confirmation
  - Meeting details
- **Dependencies**: `api` client, UI components, `lucide-react` icons

### Settings Pages

Located in `frontend/src/pages/settings/`

#### Admin (`Admin.tsx`)
- **Purpose**: Admin panel for system management
- **Features**:
  - Financial year management
  - System configuration
  - Admin tools
- **Dependencies**: `api/client`, UI components, `lucide-react` icons

#### Users (`Users.jsx`)
- **Purpose**: Manage system users
- **Features**:
  - User list with search
  - Add/edit/delete users
  - User status management
  - Password reset
- **Dependencies**: `api` client, UI components, `lucide-react` icons

#### Audit (`Audit.tsx`)
- **Purpose**: Audit log viewer
- **Features**:
  - Audit log table
  - Filter by status, user, date
  - Export functionality
  - Audit statistics
- **Dependencies**: `auditService`, `userService`, UI components, `lucide-react` icons

### Standalone Pages

Located in `frontend/src/pages/`

#### Dashboard (`Dashboard.tsx`)
- **Purpose**: Main application dashboard
- **Features**:
  - Quick stats overview
  - Attendance summary
  - Leave summary
  - Recent activity
  - Quick action buttons
- **Dependencies**: `api` client, `geolocation` utilities, UI components, `lucide-react` icons

#### Departments (`Departments.jsx`)
- **Purpose**: Manage organizational departments
- **Features**:
  - Department list
  - Add/edit/delete departments
  - Department hierarchy
- **Dependencies**: `api` client, UI components, `lucide-react` icons

#### Attendance (`Attendance.jsx`)
- **Purpose**: View and manage attendance records
- **Features**:
  - Attendance table with pagination
  - Clock in/out status
  - Date filtering
  - Employee attendance view
- **Dependencies**: `api` client, UI components, `lucide-react` icons

#### Appraisal (`Appraisal.tsx`)
- **Purpose**: Manage employee appraisals
- **Features**:
  - Appraisal list
  - Approve/reject functionality
  - Appraisal details
  - Status tracking
- **Dependencies**: `api/client`, UI components, `lucide-react` icons

#### Reports (`Reports.tsx`)
- **Purpose**: Generate and view reports
- **Features**:
  - Employee reports
  - Leave reports
  - Attendance reports
  - Appraisal reports
  - Export functionality
- **Dependencies**: `api/client`, UI components, `lucide-react` icons

#### Strategic Plan (`StrategicPlan.tsx`)
- **Purpose**: Manage strategic plans and KPIs
- **Features**:
  - Strategic plan list
  - Workplan management
  - KPI tracking
  - Progress visualization
- **Dependencies**: `api/client`, UI components, `lucide-react` icons

---

## Backend Controllers

### Auth Controllers

Located in `backend/app/Controllers/Auth/`

#### AuthController (`Auth/AuthController.php`)
- **Namespace**: `App\Controllers\Auth`
- **Purpose**: Handle authentication operations
- **Methods**:
  - `loginAction()` - User login with JWT generation
  - `logoutAction()` - User logout and token invalidation
  - `refreshAction()` - Refresh JWT token
  - `meAction()` - Get current authenticated user
  - `changePasswordAction()` - Change user password
- **Dependencies**: `AuthService`, `AuthServiceInterface`

### Employee Controllers

Located in `backend/app/Controllers/Employee/`

#### EmployeeController (`Employee/EmployeeController.php`)
- **Namespace**: `App\Controllers\Employee`
- **Purpose**: Handle employee CRUD operations
- **Methods**:
  - `indexAction()` - List all employees (paginated)
  - `storeAction()` - Create new employee
  - `showAction($id)` - Get employee details
  - `updateAction($id)` - Update employee
  - `destroyAction($id)` - Delete employee
  - `searchAction()` - Search employees
  - `profileAction()` - Get current user profile
  - `updateProfileAction()` - Update current user profile
  - `uploadProfileDocumentAction()` - Upload profile document
  - `deleteProfileDocumentAction($id)` - Delete profile document
- **Dependencies**: `EmployeeService`, `EmployeeServiceInterface`, `EmployeeValidator`

#### UserController (`Employee/UserController.php`)
- **Namespace**: `App\Controllers\Employee`
- **Purpose**: Handle user management
- **Methods**:
  - `indexAction()` - List all users
  - `storeAction()` - Create new user
  - `showAction($id)` - Get user details
  - `updateAction($id)` - Update user
  - `destroyAction($id)` - Delete user
  - `toggleStatusAction($id)` - Toggle user active status
  - `changePasswordAction($id)` - Change user password
- **Dependencies**: `UserService`, `UserServiceInterface`, `UserValidator`

### Leave Controllers

Located in `backend/app/Controllers/Leave/`

#### LeaveController (`Leave/LeaveController.php`)
- **Namespace**: `App\Controllers\Leave`
- **Purpose**: Handle leave application management
- **Methods**:
  - `indexAction()` - List leave applications
  - `applyAction()` - Apply for leave
  - `typesAction()` - Get leave types
  - `eligibleEmployeesAction()` - Get eligible employees
  - `eligibleDelegatesAction()` - Get eligible delegates
  - `manageAction()` - Manage leave applications
  - `delegatesAction()` - Get delegates
  - `calculateAction()` - Calculate leave
  - `listDocumentsAction($id)` - List leave documents
  - `viewDocumentAction($id, $documentId)` - View leave document
  - `approveAction($id)` - Approve leave application
  - `rejectAction($id)` - Reject leave application
  - `invalidateAction($id)` - Invalidate leave application
  - `cancelAction($id)` - Cancel leave application
- **Dependencies**: `LeaveService`, `LeaveServiceInterface`, `LeaveValidator`

### HR Controllers

Located in `backend/app/Controllers/HR/`

#### DepartmentController (`HR/DepartmentController.php`)
- **Namespace**: `App\Controllers\HR`
- **Purpose**: Handle department management
- **Methods**: Standard CRUD (index, store, show, update, destroy)
- **Dependencies**: `DepartmentService`, `DepartmentServiceInterface`, `DepartmentValidator`

#### SectionController (`HR/SectionController.php`)
- **Namespace**: `App\Controllers\HR`
- **Purpose**: Handle section management
- **Methods**: Standard CRUD (index, store, show, update, destroy)
- **Dependencies**: `SectionRepository`, `SectionRepositoryInterface`

#### SubsectionController (`HR/SubsectionController.php`)
- **Namespace**: `App\Controllers\HR`
- **Purpose**: Handle subsection management
- **Methods**: Standard CRUD (index, store, show, update, destroy)
- **Dependencies**: `SectionRepository`, `SectionRepositoryInterface`

#### HolidayController (`HR/HolidayController.php`)
- **Namespace**: `App\Controllers\HR`
- **Purpose**: Handle holiday management
- **Methods**:
  - `indexAction()` - List all holidays
  - `upcomingAction()` - Get upcoming holidays
  - `showAction($id)` - Get holiday details
  - `storeAction()` - Create new holiday
  - `updateAction($id)` - Update holiday
  - `destroyAction($id)` - Delete holiday
- **Dependencies**: `HolidayService`, `HolidayRepository`

#### ConsentController (`HR/ConsentController.php`)
- **Namespace**: `App\Controllers\HR`
- **Purpose**: Handle consent management
- **Methods**:
  - `indexAction()` - List consents
  - `updateAction($id)` - Update consent
  - `statusAction()` - Get consent status
  - `verifyEmployeeIdAction()` - Verify employee ID
  - `storeConsentAction()` - Store consent
  - `dashboardAction()` - Consent dashboard
  - `employeesAction()` - Get employees for consent
- **Dependencies**: `consentService`

#### FinancialYearController (`HR/FinancialYearController.php`)
- **Namespace**: `App\Controllers\HR`
- **Purpose**: Handle financial year management
- **Methods**:
  - `indexAction()` - List financial years
  - `statusAction()` - Get financial year status
  - `storeAction()` - Create new financial year
  - `allocateLeaveAction()` - Allocate leave to financial year
  - `leaveTypesAction()` - Get leave types
  - `employeesAction()` - Get employees
- **Dependencies**: `financialYearService`

### Meeting Controllers

Located in `backend/app/Controllers/Meeting/`

#### MeetingController (`Meeting/MeetingController.php`)
- **Namespace**: `App\Controllers\Meeting`
- **Purpose**: Handle meeting management
- **Methods**:
  - `myMeetingsAction()` - Get current user's meetings
  - `indexAction()` - List all meetings
  - `eligibleEmployeesAction()` - Get eligible employees
  - `storeAction()` - Create new meeting
  - `showAction($id)` - Get meeting details
  - `updateAction($id)` - Update meeting
  - `destroyAction($id)` - Delete meeting
  - `cancelAction($id)` - Cancel meeting
  - `participantsAction($id)` - Get meeting participants
  - `addParticipantAction($id)` - Add participant to meeting
  - `removeParticipantAction($id, $employeeId)` - Remove participant
  - `confirmAction($id)` - Confirm meeting attendance
  - `declineAction($id)` - Decline meeting
  - `markAttendanceAction($id)` - Mark attendance
- **Dependencies**: `MeetingService`, `MeetingRepository`

### Settings Controllers

Located in `backend/app/Controllers/Settings/`

#### PermissionController (`Settings/PermissionController.php`)
- **Namespace**: `App\Controllers\Settings`
- **Purpose**: Handle permission management
- **Methods**:
  - `catalogAction()` - Get permission catalog
  - `statisticsAction()` - Get permission statistics
  - `rolesAction()` - List roles
  - `usersAction()` - List users with permissions
  - `userPermissionsAction($id)` - Get user permissions
  - `overridesAction()` - Get permission overrides
  - `setOverrideAction($id)` - Set permission override
  - `removeOverrideAction($id)` - Remove permission override
- **Dependencies**: `PermissionService`, `RBAC` helper

#### AuditLogController (`Settings/AuditLogController.php`)
- **Namespace**: `App\Controllers\Settings`
- **Purpose**: Handle audit log management
- **Methods**:
  - `indexAction()` - List audit logs
  - `statisticsAction()` - Get audit statistics
  - `filtersAction()` - Get audit filters
  - `exportAction()` - Export audit logs
  - `showAction($id)` - Get audit log details
- **Dependencies**: `AuditService`, `AuditServiceInterface`

#### NotificationController (`Settings/NotificationController.php`)
- **Namespace**: `App\Controllers\Settings`
- **Purpose**: Handle notification management
- **Methods**:
  - `indexAction()` - List notifications
  - `markAsReadAction($id)` - Mark notification as read
  - `markAllAsReadAction()` - Mark all notifications as read
- **Dependencies**: `NotificationService`

### Standalone Controllers

Located in `backend/app/Controllers/`

#### AttendanceController (`AttendanceController.php`)
- **Namespace**: `App\Controllers`
- **Purpose**: Handle attendance tracking
- **Methods**:
  - `todayAction()` - Today's attendance
  - `dashboardAction()` - Attendance dashboard
  - `myRecordsAction()` - Current user's records
  - `byEmployeeAction($id)` - Attendance by employee
  - `clockInAction()` - Clock in
  - `clockOutAction()` - Clock out
  - `autoClockOutAction()` - Auto clock out
  - Standard CRUD (index, store, show, update, destroy)
- **Dependencies**: `AttendanceService`, `AttendanceServiceInterface`, `AttendanceValidator`

#### DashboardController (`DashboardController.php`)
- **Namespace**: `App\Controllers`
- **Purpose**: Handle dashboard data
- **Methods**:
  - `indexAction()` - Dashboard overview
  - `statsAction()` - Dashboard statistics
  - `chartsAttendanceAction()` - Attendance charts
  - `chartsDepartmentsAction()` - Department charts
  - `chartsLeaveAction()` - Leave charts
- **Dependencies**: Various services for data aggregation

#### BaseController (`BaseController.php`)
- **Namespace**: `App\Controllers`
- **Purpose**: Base controller class for all controllers
- **Features**:
  - JSON response helper methods
  - Error handling
  - Request data parsing
- **Dependencies**: `JsonResponse`

---

## API Routes

### Auth Routes
- `POST /auth/login` - Login user
- `POST /auth/logout` - Logout user
- `POST /auth/refresh` - Refresh JWT token
- `GET /auth/user` - Get current user
- `POST /auth/change-password` - Change password

### Employee Routes
- `GET /employees` - List employees
- `POST /employees` - Create employee
- `GET /employees/{id}` - Get employee
- `PUT /employees/{id}` - Update employee
- `DELETE /employees/{id}` - Delete employee
- `GET /employees/search` - Search employees
- `GET /profile` - Get current user profile
- `PUT /profile` - Update current user profile

### User Routes
- `GET /users` - List users
- `POST /users` - Create user
- `GET /users/{id}` - Get user
- `PUT /users/{id}` - Update user
- `DELETE /users/{id}` - Delete user
- `PUT /users/{id}/toggle-status` - Toggle user status
- `POST /users/{id}/change-password` - Change user password

### Leave Routes
- `GET /leave` - List leave applications
- `POST /leave/apply` - Apply for leave
- `GET /leave/types` - Get leave types
- `GET /leave/eligible-employees` - Get eligible employees
- `GET /leave/eligible-delegates` - Get eligible delegates
- `GET /leave/manage` - Manage leave applications
- `GET /leave/delegates` - Get delegates
- `GET /leave/calculate` - Calculate leave
- `GET /leave/{id}/documents` - List leave documents
- `GET /leave/{id}/documents/{documentId}` - View leave document
- `PUT /leave/{id}/approve` - Approve leave
- `PUT /leave/{id}/reject` - Reject leave
- `PUT /leave/{id}/invalidate` - Invalidate leave
- `PUT /leave/{id}/cancel` - Cancel leave

### Leave Roster Routes
- `GET /leave/roster` - List leave roster
- `POST /leave/roster` - Create leave roster
- `PUT /leave/roster/{id}` - Update leave roster
- `DELETE /leave/roster/{id}` - Delete leave roster
- `GET /leave/roster/stats` - Get roster statistics
- `GET /leave/roster/distribution` - Get roster distribution
- `GET /leave/roster/upcoming` - Get upcoming roster
- `GET /leave/roster/departments` - Get departments for roster
- `GET /leave/roster/matrix` - Get roster matrix
- `GET /leave/roster/export` - Export roster
- `GET /leave/roster/employees` - Get employees for roster
- `GET /leave/roster/financial-years` - Get financial years for roster

### Department Routes
- `GET /departments` - List departments
- `POST /departments` - Create department
- `GET /departments/{id}` - Get department
- `PUT /departments/{id}` - Update department
- `DELETE /departments/{id}` - Delete department

### Section Routes
- `GET /sections` - List sections
- `POST /sections` - Create section
- `GET /sections/{id}` - Get section
- `PUT /sections/{id}` - Update section
- `DELETE /sections/{id}` - Delete section

### Attendance Routes
- `GET /attendance` - List attendance
- `POST /attendance` - Create attendance
- `GET /attendance/{id}` - Get attendance
- `PUT /attendance/{id}` - Update attendance
- `DELETE /attendance/{id}` - Delete attendance
- `GET /attendance/today` - Today's attendance
- `GET /attendance/dashboard` - Attendance dashboard
- `GET /attendance/my-records` - My attendance records
- `GET /attendance/employee/{id}` - Attendance by employee
- `POST /attendance/clock-in` - Clock in
- `POST /attendance/clock-out` - Clock out
- `POST /attendance/auto-clockout` - Auto clock out

### Holiday Routes
- `GET /holidays` - List holidays
- `POST /holidays` - Create holiday
- `GET /holidays/{id}` - Get holiday
- `PUT /holidays/{id}` - Update holiday
- `DELETE /holidays/{id}` - Delete holiday
- `GET /holidays/upcoming` - Upcoming holidays

### Consent Routes
- `GET /consents` - List consents
- `PUT /consents/{id}` - Update consent
- `GET /consent/status` - Get consent status
- `POST /consent/verify-employee` - Verify employee ID
- `POST /consent` - Store consent
- `GET /consent/dashboard` - Consent dashboard
- `GET /consent/employees` - Get employees for consent

### Financial Year Routes
- `GET /admin/financial-years` - List financial years
- `GET /admin/financial-years/status` - Get financial year status
- `POST /admin/financial-year/add` - Add financial year
- `POST /admin/financial-year/allocate` - Allocate leave
- `GET /admin/financial-years/leave-types` - Get leave types
- `GET /admin/financial-years/employees` - Get employees

### Meeting Routes
- `GET /meetings` - List meetings
- `POST /meetings` - Create meeting
- `GET /meetings/{id}` - Get meeting
- `PUT /meetings/{id}` - Update meeting
- `DELETE /meetings/{id}` - Delete meeting
- `GET /my-meetings` - Get my meetings
- `GET /meetings/eligible-employees` - Get eligible employees
- `POST /meetings/{id}/cancel` - Cancel meeting
- `GET /meetings/{id}/participants` - Get participants
- `POST /meetings/{id}/participants` - Add participant
- `DELETE /meetings/{id}/participants/{employeeId}` - Remove participant
- `POST /meetings/{id}/confirm` - Confirm meeting
- `POST /meetings/{id}/decline` - Decline meeting
- `POST /meetings/{id}/attendance` - Mark attendance

### Notification Routes
- `GET /notifications` - List notifications
- `POST /notifications/{id}/read` - Mark as read
- `POST /notifications/read-all` - Mark all as read

### Audit Routes
- `GET /audit` - List audit logs
- `GET /audit/statistics` - Audit statistics
- `GET /audit/filters` - Audit filters
- `GET /audit/export` - Export audit logs
- `GET /audit/{id}` - Get audit log
- `GET /audit-logs` - List audit logs (alias)

### Permission Routes
- `GET /permissions/catalog` - Get permission catalog
- `GET /permissions/statistics` - Get permission statistics
- `GET /permissions/roles` - List roles
- `GET /permissions/users` - List users with permissions
- `GET /permissions/users/{id}` - Get user permissions
- `GET /permissions/overrides` - Get permission overrides
- `POST /permissions/users/{id}/overrides` - Set permission override
- `DELETE /permissions/users/{id}/overrides` - Remove permission override

### Dashboard Routes
- `GET /dashboard` - Dashboard overview
- `GET /dashboard/stats` - Dashboard statistics
- `GET /dashboard/charts/attendance` - Attendance charts
- `GET /dashboard/charts/departments` - Department charts
- `GET /dashboard/charts/leave` - Leave charts

### Settings Routes
- `GET /settings` - Get settings
- `PUT /settings` - Update settings

---

## Frontend Page Structure

```
frontend/src/pages/
├── auth/                    # Authentication pages
│   ├── Login.jsx
│   └── DataProtectionConsent.tsx
├── employee/                # Employee management pages
│   ├── Employees.jsx
│   ├── EmployeeProfile.jsx
│   ├── EmployeeForm.tsx
│   └── Profile.tsx
├── leave/                   # Leave management pages
│   ├── Leave.jsx
│   ├── LeaveApplication.jsx
│   ├── LeaveRoster.jsx
│   ├── LeaveOversight.jsx
│   ├── ManageLeaveLayout.jsx
│   ├── ManageLeavePendingTab.jsx
│   ├── ManageLeaveApprovedTab.jsx
│   ├── ManageLeaveRejectedTab.jsx
│   └── leaveManageShared.jsx
├── hr-admin/                # HR administration pages
│   ├── FinancialYear.jsx
│   ├── Consent.tsx
│   └── Holidays.jsx
├── meetings/                # Meeting management pages
│   ├── MeetingsDashboard.tsx
│   ├── CreateMeeting.tsx
│   └── MyMeetings.tsx
├── settings/                # Settings and admin pages
│   ├── Admin.tsx
│   ├── Users.jsx
│   └── Audit.tsx
├── Attendance.jsx           # Standalone pages
├── Dashboard.tsx
├── Departments.jsx
├── Appraisal.tsx
├── Reports.tsx
└── StrategicPlan.tsx
```

## Backend Controller Structure

```
backend/app/Controllers/
├── Auth/                    # Authentication controllers
│   └── AuthController.php
├── Employee/                # Employee & user management controllers
│   ├── EmployeeController.php
│   └── UserController.php
├── Leave/                   # Leave management controllers
│   └── LeaveController.php
├── HR/                      # HR administration controllers
│   ├── ConsentController.php
│   ├── DepartmentController.php
│   ├── FinancialYearController.php
│   ├── HolidayController.php
│   ├── SectionController.php
│   └── SubsectionController.php
├── Meeting/                 # Meeting management controllers
│   └── MeetingController.php
├── Reports/                 # Reports controllers
│   └── ReportsController.php
├── Settings/                # Settings & admin controllers
│   ├── AuditLogController.php
│   ├── NotificationController.php
│   └── PermissionController.php
├── AttendanceController.php # Standalone controllers
├── BaseController.php
└── DashboardController.php
```
</arg_value>
<task_progress>
- [x] Review documentation to identify documented vs undocumented pages
- [x] Analyze frontend pages structure
- [x] Analyze backend structure
- [x] Create new GitHub branch
- [x] Group related pages into folders (frontend)
- [x] Group related pages into folders (backend)
- [x] Update imports across all affected files
- [x] Create documentation for undocumented pages
- [ ] Test the application
- [ ] Commit and push changes to GitHub
</task_progress>
</write_to_file></tool_call>