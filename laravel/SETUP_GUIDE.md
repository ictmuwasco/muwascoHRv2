# Laravel Backend Setup Guide

## Prerequisites
- PHP 8.0 or higher
- Composer
- MySQL database
- XAMPP or similar local server

## Installation Steps

### 1. Install Dependencies
```bash
cd laravel
composer install
```

### 2. Configure Environment
The `.env` file has been configured with:
- Database: `muwasco` (MySQL)
- Username: `root`
- Password: `root`
- App URL: `http://localhost/hrdemo`

Update these values if your database credentials differ.

### 3. Generate Application Key
```bash
php artisan key:generate
```

### 4. Run Migrations

**Note:** If you're using an existing database (like `muwasco`), the tables may already exist. In that case, you can skip migrations or run:

```bash
php artisan migrate --force
```

If tables already exist and you want to reset them (WARNING: this will delete all data):
```bash
php artisan migrate:fresh --force
```

The migrations create the following tables:
- users
- departments
- employees
- sections
- leave_types
- leave_applications
- attendance
- offices
- employee_leave_balances
- leave_history
- personal_access_tokens (for Sanctum API authentication)

### 5. Start Development Server
```bash
php artisan serve
```

The API will be available at `http://localhost:8000`

## API Endpoints

### Authentication
- `POST /api/auth/login` - Login
- `POST /api/auth/logout` - Logout
- `GET /api/auth/user` - Get authenticated user

### Resources (all require authentication)
- `GET /api/employees` - List employees
- `POST /api/employees` - Create employee
- `GET /api/employees/{id}` - Show employee
- `PUT /api/employees/{id}` - Update employee
- `DELETE /api/employees/{id}` - Delete employee

- `GET /api/departments` - List departments
- `POST /api/departments` - Create department
- `GET /api/departments/{id}` - Show department
- `PUT /api/departments/{id}` - Update department
- `DELETE /api/departments/{id}` - Delete department

- `GET /api/sections` - List sections
- `POST /api/sections` - Create section
- `GET /api/sections/{id}` - Show section
- `PUT /api/sections/{id}` - Update section
- `DELETE /api/sections/{id}` - Delete section

- `GET /api/leave` - List leave applications
- `POST /api/leave` - Create leave application
- `GET /api/leave/{id}` - Show leave application
- `PUT /api/leave/{id}` - Update leave application
- `DELETE /api/leave/{id}` - Delete leave application
- `POST /api/leave/{id}/approve` - Approve leave
- `POST /api/leave/{id}/reject` - Reject leave

- `GET /api/attendance` - List attendance records
- `POST /api/attendance` - Create attendance record
- `GET /api/attendance/{id}` - Show attendance record
- `PUT /api/attendance/{id}` - Update attendance record
- `DELETE /api/attendance/{id}` - Delete attendance record

- `GET /api/users` - List users
- `GET /api/users/{id}` - Show user
- `PUT /api/users/{id}` - Update user

## Frontend Configuration

The frontend should be configured to make API requests to:
- Development: `http://localhost:8000/api`
- Production: Update the `APP_URL` in `.env` and configure frontend accordingly

## CORS Configuration

CORS is configured in `config/cors.php` to allow requests from:
- `http://localhost:5173` (Vite default)
- `http://127.0.0.1:5173`
- `http://localhost:3000`

Update these values if your frontend runs on a different port.

## Database

The migrations are already created in `database/migrations/`. To reset the database:
```bash
php artisan migrate:fresh
```

## Notes

- The backend uses Laravel Sanctum for API authentication
- All API routes are prefixed with `/api`
- Authentication middleware is applied to all resource routes
- The existing database `muwasco` can be used directly with the provided migrations