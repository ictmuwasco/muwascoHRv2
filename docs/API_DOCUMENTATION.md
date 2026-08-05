# API Documentation

## Base URL
```
http://localhost/hrdemo/api
```

## Authentication

All API endpoints require authentication via JWT token or session cookie.

### Headers
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

---

## Authentication Flow Diagram

```mermaid
sequenceDiagram
    participant Client as Frontend (React)
    participant Vite as Vite Dev Server :5173
    participant Apache as XAMPP Apache :80
    participant API as api.php Router
    participant Auth as AuthController
    participant Service as AuthService
    participant DB as MySQL Database

    Client->>Vite: POST /api/auth/login
    Vite->>Apache: POST /hrdemo/api/auth/login (proxy rewrite)
    Apache->>API: RewriteRule to api.php
    API->>Auth: AuthController->loginAction()
    Auth->>Service: authService->login(email, password)
    Service->>DB: userRepository->findByEmail(email)
    DB-->>Service: User record (or null)
    Service->>DB: Check user is_active
    Service->>Service: Verify password (hash->verify)
    Service->>DB: Update last_activity column
    Service-->>Auth: {user, token}
    Auth-->>Client: {success: true, data: {user, token}}
```

### Login Process (Fixed)

#### POST /auth/login
Login user and get JWT token.

**Request:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "role": "admin"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
  }
}
```

**Error Responses:**
- `400` - Email and password are required
- `401` - Invalid credentials / User account is inactive
- `500` - Database connection failed or server error

**Bug Fix Applied:** The `updateLastLogin()` method in `AuthService` was updating a non-existent `last_login` column. It now correctly updates the `last_activity` column that exists in the `users` table schema.

#### POST /auth/logout
Logout user and invalidate token.

**Response:**
```json
{
  "success": true,
  "message": "Logout successful"
}
```

---

## Dashboard Flow Diagram

```mermaid
sequenceDiagram
    participant Client as Frontend (React)
    participant Vite as Vite Dev Server :5173
    participant Apache as XAMPP Apache :80
    participant API as api.php Router
    participant Dash as DashboardController
    participant DB as MySQL Database

    Client->>Vite: GET /api/dashboard/stats
    Vite->>Apache: GET /hrdemo/api/dashboard/stats (proxy rewrite)
    Apache->>API: RewriteRule to api.php
    API->>Dash: DashboardController->statsAction()
    Dash->>DB: Count active employees
    Dash->>DB: Count today's attendance
    Dash->>DB: Count employees on approved leave
    Dash->>DB: Count pending leave approvals
    DB-->>Dash: Statistics
    Dash-->>Client: {success: true, data: {totalEmployees, presentToday, onLeave, pendingApprovals}}
```

### Dashboard Endpoints (Fixed)

#### GET /api/dashboard/stats
Get dashboard statistics.

**Response:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "totalEmployees": 150,
    "presentToday": 142,
    "onLeave": 5,
    "pendingApprovals": 12,
    "lateToday": 0
  }
}
```

**Bug Fixes Applied:**
1. Added missing dashboard routes in `api.php` - routes were not defined so requests fell through to the 404 handler
2. Fixed Vite proxy configuration in `frontend/vite.config.js`:
   - Changed target from `http://127.0.0.1:8000` to `http://localhost`
   - Added rewrite rule to prefix `/hrdemo` to proxied API paths
3. Fixed `statsAction()` in `DashboardController`:
   - Response format now matches frontend expectations (`totalEmployees`, `presentToday`, `onLeave`, `pendingApprovals`)
   - Uses correct `leave_applications` table instead of non-existent `leave_requests` table
   - Added try/catch error handling so database errors return defaults instead of 500

#### GET /api/dashboard/charts/attendance
Get attendance chart data.

**Response:**
```json
{
  "success": true,
  "data": {
    "records": [],
    "stats": {
      "total": 10,
      "clocked_in": 8,
      "clocked_out": 2,
      "late": 1
    }
  }
}
```

#### GET /api/dashboard/charts/departments
Get employee distribution by department.

**Response:**
```json
{
  "success": true,
  "data": {
    "total": 150,
    "by_department": [
      {
        "department": "IT",
        "count": 25
      }
    ]
  }
}
```

---

## Vite Proxy Configuration Diagram

```mermaid
flowchart LR
    A[Browser<br/>localhost:5173] -->|/api/dashboard/stats| B[Vite Dev Server<br/>Proxy]
    B -->|rewrite: /hrdemo/api/dashboard/stats| C[XAMPP Apache<br/>localhost:80]
    C -->|.htaccess RewriteRule| D[api.php]
    D -->|/dashboard/stats| E[DashboardController]
    E -->|statsAction| F[Database]
```

### Vite Proxy Configuration (Fixed)

```js
// frontend/vite.config.js
proxy: {
  '/api': {
    target: 'http://localhost',       // XAMPP Apache
    changeOrigin: true,
    secure: false,
    rewrite: (path) => `/hrdemo${path}`,  // Prefix with /hrdemo
  },
},
```

---

## API Request Flow Diagram

```mermaid
flowchart TD
    A[HTTP Request] --> B{Path starts with /api?}
    B -->|Yes| C[api.php]
    B -->|No| D[index.php SPA]
    C --> E{Route Match}
    E -->|/auth/*| F[AuthController]
    E -->|/employees/*| G[EmployeeController]
    E -->|/dashboard/*| H[DashboardController]
    E -->|/departments/*| I[DepartmentController]
    E -->|No Match| J[404 Not Found]
    F --> K[AuthService]
    K --> L[UserRepository]
    K --> M[EmployeeRepository]
    H --> N[Database Queries]
```

---

## Endpoints

### Employees

#### GET /employees
Get all employees with pagination.

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `limit` (optional): Items per page (default: 30)
- `search` (optional): Search term
- `department_id` (optional): Filter by department
- `section_id` (optional): Filter by section
- `employee_status` (optional): Filter by status

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "employee_id": "EMP001",
        "first_name": "John",
        "last_name": "Doe",
        "email": "john@example.com",
        "department_name": "IT",
        "section_name": "Development",
        "designation": "Software Engineer"
      }
    ],
    "total": 50,
    "page": 1,
    "limit": 30,
    "totalPages": 2
  }
}
```

#### GET /employees/{id}
Get single employee by ID.

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "employee_id": "EMP001",
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "phone": "+254700000000",
    "department_name": "IT",
    "section_name": "Development",
    "office_name": "Nairobi",
    "designation": "Software Engineer"
  }
}
```

#### POST /employees
Create new employee.

**Request:**
```json
{
  "employee_id": "EMP001",
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "phone": "+254700000000",
  "national_id": 12345678,
  "gender": "male",
  "department_id": 1,
  "section_id": 1,
  "office_id": 1,
  "designation": "Software Engineer"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Employee created successfully",
  "data": {
    "id": 1
  }
}
```

#### PUT /employees/{id}
Update employee.

**Request:**
```json
{
  "first_name": "John Updated",
  "phone": "+254711111111"
}
```

#### DELETE /employees/{id}
Delete employee.

**Response:**
```json
{
  "success": true,
  "message": "Employee deleted successfully"
}
```

### Departments

#### GET /departments
Get all departments.

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "IT",
      "description": "Information Technology",
      "employee_count": 25
    }
  ]
}
```

#### POST /departments
Create department.

**Request:**
```json
{
  "name": "IT",
  "description": "Information Technology"
}
```

### Attendance

#### GET /attendance
Get attendance records.

**Query Parameters:**
- `page` (optional): Page number
- `limit` (optional): Items per page
- `date` (optional): Filter by date (YYYY-MM-DD)

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [],
    "total": 0,
    "page": 1,
    "limit": 30,
    "totalPages": 0
  }
}
```

#### GET /attendance/today
Get today's attendance records.

#### GET /attendance/dashboard
Get attendance data for dashboard widgets.

#### GET /attendance/employee/{employeeId}
Get attendance for a specific employee.

### Leave

#### GET /leave
Get leave records.

**Query Parameters:**
- `page` (optional): Page number
- `limit` (optional): Items per page
- `status` (optional): Filter by status (pending, approved, rejected, cancelled)

#### GET /leave/types
Get all leave types.

#### GET /leave/holidays
Get public holidays.

#### POST /leave/apply
Apply for leave.

**Request:**
```json
{
  "leave_type_id": 1,
  "start_date": "2024-02-01",
  "end_date": "2024-02-05",
  "reason": "Vacation"
}
```

#### PUT /leave/{leaveApplication}/approve
Approve leave application.

#### PUT /leave/{leaveApplication}/reject
Reject leave application.

#### PUT /leave/{leaveApplication}/cancel
Cancel leave application.

---

## Reports

#### GET /reports/employees
Get employees report.

#### GET /reports/leave
Get leave report.

#### GET /reports/attendance
Get attendance report.

#### GET /reports/{type}/export/{format}
Export report.

**Parameters:**
- `type`: employees, leave, attendance, or appraisal
- `format`: pdf, csv, or excel

---

## Audit Logs

#### GET /audit-logs
Get audit trail.

**Query Parameters:**
- `user_id` (optional): Filter by user
- `action` (optional): Filter by action
- `resource` (optional): Filter by resource
- `start_date` (optional): Start date
- `end_date` (optional): End date

---

## Error Responses

All errors follow this format:

```json
{
  "success": false,
  "message": "Error description",
  "error": "ERROR_CODE"
}
```

### HTTP Status Codes

- `200 OK` - Request successful
- `201 Created` - Resource created
- `400 Bad Request` - Invalid request data
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `500 Internal Server Error` - Server error

---

## Rate Limiting

API endpoints are rate limited to:
- 100 requests per minute per user
- 200 requests per minute per IP

Rate limit headers are included in responses:
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1640000000
```

---

## Troubleshooting

### Dashboard returns 500 Internal Server Error

**Problem:** `GET /api/dashboard/stats` returns 500.

**Causes and fixes:**
1. **Missing route in `api.php`** - Ensure the dashboard routes are defined in the router:
   ```php
   elseif (strpos($endpoint, '/dashboard') === 0) {
       require_once __DIR__ . '/backend/app/Controllers/DashboardController.php';
       $controller = new \App\Controllers\DashboardController();
       if ($endpoint === '/dashboard/stats' && $requestMethod === 'GET') {
           $controller->statsAction();
       }
   }
   ```

2. **Wrong Vite proxy target** - Ensure `frontend/vite.config.js` proxies to the correct backend:
   ```js
   proxy: {
     '/api': {
       target: 'http://localhost',  // XAMPP runs on port 80
       rewrite: (path) => `/hrdemo${path}`,
     },
   },
   ```

3. **Database schema mismatch** - Ensure queries use the correct table names:
   - `leave_applications` (not `leave_requests`)
   - `attendance` with `attendance_date` and `clock_in` columns

### Login returns 500 Internal Server Error

**Problem:** `POST /api/auth/login` returns 500.

**Cause:** The `updateLastLogin()` method was referencing a `last_login` column that doesn't exist in the `users` table schema.

**Fix:** The method now updates the `last_activity` column:
```php
private function updateLastLogin(int $userId): void
{
    $this->userRepository->update($userId, [
        'last_activity' => date('Y-m-d H:i:s'),
    ]);
}