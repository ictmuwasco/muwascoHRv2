# API Documentation

## Base URL
```
http://localhost/hr/api
```

## Authentication

All API endpoints require authentication via JWT token or session cookie.

### Headers
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

## Endpoints

### Authentication

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
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": {
    "id": 1,
    "email": "user@example.com",
    "first_name": "John",
    "last_name": "Doe",
    "role": "admin"
  }
}
```

#### POST /auth/logout
Logout user and invalidate token.

**Response:**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

### Employees

#### GET /employees
Get all employees with pagination.

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `per_page` (optional): Items per page (default: 10)
- `search` (optional): Search term
- `department_id` (optional): Filter by department
- `section_id` (optional): Filter by section

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "employee_id": "EMP001",
      "first_name": "John",
      "last_name": "Doe",
      "email": "john@example.com",
      "department": "IT",
      "section": "Development",
      "designation": "Software Engineer"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 10,
    "total": 50,
    "total_pages": 5
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
    "department": "IT",
    "section": "Development",
    "office": "Nairobi",
    "designation": "Software Engineer",
    "employee_type": "Permanent",
    "employment_date": "2023-01-01"
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
  "national_id": "12345678",
  "gender": "Male",
  "marital_status": "Single",
  "address": "123 Main St",
  "department_id": 1,
  "section_id": 1,
  "office_id": 1,
  "designation": "Software Engineer",
  "employee_type": "Permanent",
  "employment_date": "2023-01-01"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "employee_id": "EMP001",
    "first_name": "John",
    "last_name": "Doe"
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

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "first_name": "John Updated",
    "phone": "+254711111111"
  }
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
      "manager": "John Doe",
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
  "description": "Information Technology",
  "manager_id": 1
}
```

### Attendance

#### GET /attendance
Get attendance records.

**Query Parameters:**
- `employee_id` (optional): Filter by employee
- `date` (optional): Filter by date (YYYY-MM-DD)
- `start_date` (optional): Start date for range
- `end_date` (optional): End date for range

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "employee_id": "EMP001",
      "employee_name": "John Doe",
      "date": "2024-01-15",
      "check_in": "08:00:00",
      "check_out": "17:00:00",
      "status": "Present",
      "hours_worked": 9.0
    }
  ]
}
```

#### POST /attendance/check-in
Record check-in.

**Request:**
```json
{
  "employee_id": 1,
  "latitude": -1.2921,
  "longitude": 36.8219,
  "location": "Office"
}
```

#### POST /attendance/check-out
Record check-out.

**Request:**
```json
{
  "attendance_id": 1
}
```

### Leave

#### GET /leave
Get leave records.

**Query Parameters:**
- `employee_id` (optional): Filter by employee
- `status` (optional): Filter by status (Pending, Approved, Rejected)
- `type` (optional): Filter by type (Annual, Sick, Maternity, etc.)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "employee_id": "EMP001",
      "employee_name": "John Doe",
      "type": "Annual",
      "start_date": "2024-02-01",
      "end_date": "2024-02-05",
      "days": 5,
      "reason": "Vacation",
      "status": "Pending",
      "applied_on": "2024-01-20"
    }
  ]
}
```

#### POST /leave/apply
Apply for leave.

**Request:**
```json
{
  "type": "Annual",
  "start_date": "2024-02-01",
  "end_date": "2024-02-05",
  "reason": "Vacation"
}
```

#### PUT /leave/{id}/approve
Approve leave application.

**Request:**
```json
{
  "comments": "Approved"
}
```

#### PUT /leave/{id}/reject
Reject leave application.

**Request:**
```json
{
  "comments": "Insufficient leave balance"
}
```

### Dashboard

#### GET /dashboard
Get dashboard statistics.

**Response:**
```json
{
  "success": true,
  "data": {
    "total_employees": 150,
    "present_today": 142,
    "absent_today": 8,
    "on_leave_today": 5,
    "departments": 8,
    "new_employees_this_month": 3,
    "pending_leave_applications": 12,
    "upcoming_birthdays": [
      {
        "id": 1,
        "name": "John Doe",
        "department": "IT",
        "birthday": "2024-02-15"
      }
    ]
  }
}
```

### Reports

#### GET /reports/employees/export/{format}
Export employee report.

**Parameters:**
- `format`: pdf, csv, or excel

**Response:** Binary file download

#### GET /reports/attendance/export/{format}
Export attendance report.

**Parameters:**
- `format`: pdf, csv, or excel
- `start_date` (optional): Start date
- `end_date` (optional): End date

### Audit Logs

#### GET /audit-logs
Get audit trail.

**Query Parameters:**
- `user_id` (optional): Filter by user
- `action` (optional): Filter by action
- `resource` (optional): Filter by resource
- `start_date` (optional): Start date
- `end_date` (optional): End date

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "user_name": "John Doe",
      "action": "create",
      "resource": "employee",
      "resource_id": 1,
      "details": "Created employee EMP001",
      "ip_address": "192.168.1.1",
      "created_at": "2024-01-15 10:30:00"
    }
  ]
}
```

## Error Responses

All errors follow this format:

```json
{
  "success": false,
  "message": "Error description",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

### HTTP Status Codes

- `200 OK` - Request successful
- `201 Created` - Resource created
- `400 Bad Request` - Invalid request data
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `422 Validation Error` - Validation failed
- `500 Internal Server Error` - Server error

## Rate Limiting

API endpoints are rate limited to:
- 100 requests per minute per user
- 200 requests per minute per IP

Rate limit headers are included in responses:
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1640000000