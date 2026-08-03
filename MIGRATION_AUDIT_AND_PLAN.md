# HRMS Migration Audit and Implementation Plan

## Executive Summary

This document provides a comprehensive analysis of the current state of the HR Management System migration from legacy PHP to Laravel, identifies gaps, and outlines the implementation plan to complete the migration.

## Current State Analysis

### 1. Legacy PHP Backend (`backend/`)
**Status**: Partially implemented custom PHP framework

**Existing Components:**
- ✅ Custom routing system (`api.php`)
- ✅ Base controller with JSON responses
- ✅ Authentication controller with JWT
- ✅ Employee controller (CRUD + search + reference)
- ✅ Service layer (Auth, Employee, Department, Attendance, Leave, User)
- ✅ Repository layer with interfaces
- ✅ Validator layer
- ✅ Response resources
- ✅ Middleware (Authentication, Authorization)
- ✅ Helpers (Auth, Database, Hash, Session, RBAC, Authorization)
- ✅ Email templates for leave management
- ✅ Database migrations (SQL files)
- ✅ Models (User, Attendance, Consent, Notification, UserPagePermission)

**Missing Controllers:**
- ❌ DepartmentController (directory exists but empty)
- ❌ AttendanceController (directory exists but empty)
- ❌ LeaveController (directory exists but empty)
- ❌ UserController (directory exists but empty)
- ❌ SectionController (directory exists but empty)
- ❌ DashboardController (exists but not routed)
- ❌ ReportsController (exists but not routed)
- ❌ AppraisalController
- ❌ StrategicPlanController
- ❌ ComplaintController
- ❌ PayrollController
- ❌ SettingController

**Current Routes (api.php):**
```
POST   /api/auth/login
POST   /api/auth/logout
POST   /api/auth/refresh
GET    /api/auth/me
POST   /api/auth/change-password
GET    /api/employees
POST   /api/employees
GET    /api/employees/{id}
PUT    /api/employees/{id}
DELETE /api/employees/{id}
GET    /api/employees/search
GET    /api/employees/reference
```

### 2. Laravel Backend (`laravel/`)
**Status**: Basic structure established, needs completion

**Existing Components:**
- ✅ Laravel project structure
- ✅ Composer configuration
- ✅ Migrations for core tables (users, departments, employees, sections, leave_types, leave_applications, attendance, offices, employee_leave_balances, leave_history)
- ✅ Models for core entities
- ✅ Controllers (Auth, Employee, Department, Attendance, Leave, User, Section)
- ✅ Routes defined (`laravel/routes/api.php`)
- ✅ Auth configuration
- ✅ CORS configuration
- ✅ Database dump (`admin_hrmuwasco.sql`)

**Issues:**
- ❌ Using Sanctum instead of JWT (must use firebase/php-jwt or tymon/jwt-auth)
- ❌ Controllers likely incomplete (need verification)
- ❌ Missing services, repositories for most modules
- ❌ Missing migrations for: financial_years, complaints, appraisals, performance, kpis, work_plans, notifications, audit_logs
- ❌ Missing middleware for JWT authentication
- ❌ Missing controllers for: Dashboard, Reports, StrategicPlan, Appraisal, Complaint, Payroll, Settings, Audit, Consent, Profile

**Current Laravel Routes:**
```php
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/user

// Protected routes (using sanctum - NEEDS CHANGE)
GET    /api/employees
POST   /api/employees
GET    /api/employees/{id}
PUT    /api/employees/{id}
DELETE /api/employees/{id}

GET    /api/departments
POST   /api/departments
... (similar for leave, attendance, users, sections)
```

### 3. React Frontend (`frontend/`)
**Status**: Well-structured, mostly compatible

**Existing API Services:**
- ✅ authService.ts (login, logout, getProfile, refreshToken, forgotPassword, resetPassword)
- ✅ employeeService.ts
- ✅ departmentService.ts
- ✅ attendanceService.ts
- ✅ leaveService.ts
- ✅ userService.ts
- ✅ dashboardService.ts
- ✅ appraisalService.ts
- ✅ reportService.ts
- ✅ strategicPlanService.ts

**Frontend Features:**
- ✅ JWT authentication with localStorage
- ✅ Axios interceptors for auth tokens
- ✅ Protected routes
- ✅ Context API for auth state
- ✅ TypeScript types defined
- ✅ UI component library

**API Endpoints Expected by Frontend:**
```
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/profile (expects this, legacy has /auth/me)
POST   /api/auth/refresh
POST   /api/auth/forgot-password
POST   /api/auth/reset-password

GET    /api/employees
POST   /api/employees
GET    /api/employees/{id}
PUT    /api/employees/{id}
DELETE /api/employees/{id}

GET    /api/departments
... (similar pattern for all resources)
```

## Database Schema Analysis

### Tables in `admin_hrmuwasco.sql`:
1. absent_deductions
2. absent_exemptions
3. activities
4. appraisal_cycles
5. (and many more - need complete analysis)

### Existing Laravel Migrations:
1. ✅ users
2. ✅ departments
3. ✅ employees
4. ✅ sections
5. ✅ leave_types
6. ✅ leave_applications
7. ✅ attendance
8. ✅ offices
9. ✅ employee_leave_balances
10. ✅ leave_history

### Missing Migrations:
- ❌ subsections
- ❌ roles
- ❌ permissions
- ❌ role_permissions
- ❌ user_roles
- ❌ financial_years
- ❌ complaints
- ❌ appraisals
- ❌ performance_indicators
- ❌ strategic_plans
- ❌ kpis
- ❌ work_plans
- ❌ notifications
- ❌ audit_logs
- ❌ settings
- ❌ consent_records
- ❌ delegates
- ❌ leave_attachments

## Route Comparison

### Legacy Routes vs Laravel Routes

| Legacy Route | Laravel Route | Status |
|-------------|---------------|--------|
| POST /api/auth/login | POST /api/auth/login | ✅ Match |
| POST /api/auth/logout | POST /api/auth/logout | ✅ Match |
| POST /api/auth/refresh | POST /api/auth/refresh | ❌ Missing |
| GET /api/auth/me | GET /api/auth/user | ⚠️ Different endpoint |
| POST /api/auth/change-password | - | ❌ Missing |
| GET /api/employees | GET /api/employees | ✅ Match |
| POST /api/employees | POST /api/employees | ✅ Match |
| GET /api/employees/{id} | GET /api/employees/{id} | ✅ Match |
| PUT /api/employees/{id} | PUT /api/employees/{id} | ✅ Match |
| DELETE /api/employees/{id} | DELETE /api/employees/{id} | ✅ Match |
| GET /api/employees/search | - | ❌ Missing |
| GET /api/employees/reference | - | ❌ Missing |
| - | All department routes | ❌ Not in legacy |
| - | All attendance routes | ❌ Not in legacy |
| - | All leave routes | ❌ Not in legacy |
| - | All user routes | ❌ Not in legacy |
| - | All section routes | ❌ Not in legacy |

### Frontend Expected Routes vs Current Implementation

| Frontend Expects | Legacy Has | Laravel Has | Status |
|-----------------|------------|--------------|--------|
| POST /api/auth/login | ✅ | ✅ | ✅ OK |
| POST /api/auth/logout | ✅ | ✅ | ✅ OK |
| GET /api/auth/profile | ❌ (/auth/me) | ❌ | ❌ **MISMATCH** |
| POST /api/auth/refresh | ✅ | ❌ | ❌ Missing |
| POST /api/auth/forgot-password | ❌ | ❌ | ❌ Missing |
| POST /api/auth/reset-password | ❌ | ❌ | ❌ Missing |
| All CRUD routes | Partial | Partial | ⚠️ Incomplete |

## Critical Issues to Fix

### 1. Authentication System
**Issue**: Laravel configured to use Sanctum, but requirement is JWT
**Solution**: 
- Remove Sanctum
- Install `firebase/php-jwt` or `tymon/jwt-auth`
- Implement JWT middleware
- Ensure compatibility with React frontend

### 2. Route Endpoint Mismatch
**Issue**: Frontend expects `/api/auth/profile`, legacy has `/api/auth/me`
**Solution**: 
- Add `/api/auth/profile` route
- Keep `/api/auth/me` for backward compatibility
- Or update frontend to use `/api/auth/me`

### 3. Missing Features in Legacy
**Issue**: Legacy backend incomplete (only auth and employees implemented)
**Solution**: Complete all controllers in legacy backend first, then migrate to Laravel

### 4. Missing Controllers in Laravel
**Issue**: Many controllers exist but likely empty or incomplete
**Solution**: Implement all controllers with proper business logic

### 5. Database Migrations
**Issue**: Missing migrations for many tables
**Solution**: Create migrations for all missing tables based on `admin_hrmuwasco.sql`

## Implementation Strategy

### Phase 1: Complete Legacy Backend (Week 1-2)
**Objective**: Make legacy backend 100% functional

**Tasks:**
1. Complete all missing controllers in `backend/app/Controllers/`
   - DepartmentController
   - AttendanceController
   - LeaveController
   - UserController
   - SectionController
   - DashboardController
   - ReportsController
   - AppraisalController
   - StrategicPlanController
   - ComplaintController
   - PayrollController
   - SettingController

2. Complete service layer for all modules
3. Complete repository layer for all modules
4. Add missing routes to `api.php`
5. Test all endpoints with Postman/Thunder Client

**Module Order:**
1. Authentication (complete existing)
2. Users & Roles
3. Departments
4. Sections
5. Employees
6. Leave Management
7. Attendance
8. Dashboard
9. Reports
10. Appraisals
11. Strategic Plans
12. Complaints
13. Payroll
14. Settings

### Phase 2: Laravel Setup (Week 3)
**Objective**: Prepare Laravel for migration

**Tasks:**
1. Remove Sanctum
2. Install and configure JWT (tymon/jwt-auth recommended for Laravel)
3. Create JWT authentication middleware
4. Create base controller for Laravel
5. Set up CORS properly
6. Create service provider bindings

### Phase 3: Migrate to Laravel (Week 4-8)
**Objective**: Port all functionality from legacy to Laravel

**Module Order (same as suggested):**
1. Authentication (WEEK 4)
   - Migrate JWT setup
   - Migrate AuthController
   - Migrate AuthService
   - Test login/logout/token refresh
   - **TEST**: Login works, tokens work

2. Users & Roles (WEEK 4)
   - Migrate UserController
   - Migrate UserService
   - Migrate role/permission middleware
   - **TEST**: User CRUD works

3. Departments (WEEK 5)
   - Migrate DepartmentController
   - **TEST**: Department CRUD works

4. Sections (WEEK 5)
   - Migrate SectionController
   - **TEST**: Section CRUD works

5. Employees (WEEK 5-6)
   - Migrate EmployeeController
   - Migrate EmployeeService
   - Migrate EmployeeRepository
   - **TEST**: Employee CRUD works

6. Leave Management (WEEK 6)
   - Migrate LeaveController
   - Migrate LeaveService
   - Migrate leave attachments
   - Migrate delegates
   - **TEST**: Leave workflow works

7. Attendance (WEEK 6-7)
   - Migrate AttendanceController
   - Migrate AttendanceService
   - **TEST**: Attendance tracking works

8. Dashboard (WEEK 7)
   - Migrate DashboardController
   - **TEST**: Dashboard data loads

9. Reports (WEEK 7)
   - Migrate ReportsController
   - **TEST**: Reports generate

10. Appraisals (WEEK 8)
    - Migrate AppraisalController
    - **TEST**: Appraisal workflow works

11. Strategic Plans (WEEK 8)
    - Migrate StrategicPlanController
    - **TEST**: Strategic plans work

12. Complaints (WEEK 9)
    - Migrate ComplaintController
    - **TEST**: Complaint system works

13. Payroll (WEEK 9)
    - Migrate PayrollController
    - **TEST**: Payroll calculations work

14. Settings (WEEK 10)
    - Migrate SettingController
    - **TEST**: Settings save/load

15. Notifications (WEEK 10)
    - Migrate NotificationController
    - **TEST**: Notifications work

16. Audit Logs (WEEK 10)
    - Migrate AuditController
    - **TEST**: Audit trail works

### Phase 4: Frontend Integration (Week 11)
**Objective**: Ensure React frontend works with Laravel

**Tasks:**
1. Update API base URL if needed
2. Fix any endpoint mismatches
3. Test all frontend pages
4. Fix authentication flow
5. Test error handling

### Phase 5: Testing & Deployment (Week 12)
**Objective**: Production-ready system

**Tasks:**
1. Complete integration testing
2. Fix bugs
3. Performance optimization
4. Documentation
5. Deployment

## Detailed Module Breakdown

### 1. Authentication Module
**Legacy Files:**
- `backend/app/Controllers/AuthController.php`
- `backend/app/Services/AuthService.php`
- `backend/app/Helpers/Auth.php`
- `backend/app/Middleware/AuthenticationMiddleware.php`
- `backend/app/Middleware/AuthorizationMiddleware.php`

**Laravel Files to Create:**
- `laravel/app/Http/Controllers/Api/AuthController.php`
- `laravel/app/Services/AuthService.php`
- `laravel/app/Http/Middleware/JwtMiddleware.php`
- `laravel/config/jwt.php`

**API Endpoints:**
```
POST   /api/auth/login
POST   /api/auth/logout
POST   /api/auth/refresh
GET    /api/auth/profile
POST   /api/auth/change-password
POST   /api/auth/forgot-password
POST   /api/auth/reset-password
```

**Database Tables:**
- users (exists)
- password_resets (exists)
- refresh_tokens (need migration)

### 2. Users Module
**Legacy Files:**
- `backend/app/Controllers/UserController.php` (need to create)
- `backend/app/Services/UserService.php`
- `backend/app/Repositories/UserRepository.php`

**Laravel Files to Create:**
- `laravel/app/Http/Controllers/Api/UserController.php`
- `laravel/app/Services/UserService.php`
- `laravel/app/Repositories/UserRepository.php`
- `laravel/app/Policies/UserPolicy.php`

**API Endpoints:**
```
GET    /api/users
POST   /api/users
GET    /api/users/{id}
PUT    /api/users/{id}
DELETE /api/users/{id}
```

### 3. Departments Module
**Similar structure to Users**

### 4. Employees Module
**Most complex module - already partially migrated**

### 5. Leave Management Module
**Complex workflows:**
- Apply for leave
- Approve/Reject leave
- Cancel leave
- Return leave
- Delegate leave
- Leave attachments
- Leave balances
- Leave history

**Database Tables:**
- leave_types (exists)
- leave_applications (exists)
- employee_leave_balances (exists)
- leave_history (exists)
- leave_attachments (need migration)
- delegates (need migration)

### 6. Attendance Module
**Features:**
- Clock in/out
- Attendance records
- Absent deductions
- Absent exemptions

**Database Tables:**
- attendance (exists)
- absent_deductions (need migration)
- absent_exemptions (need migration)

### 7. Dashboard Module
**No database tables - aggregates data from other modules**

### 8. Reports Module
**Various reports:**
- Employee reports
- Attendance reports
- Leave reports
- Payroll reports

### 9. Appraisals Module
**Database Tables:**
- appraisal_cycles (exists in SQL)
- appraisals (need migration)
- appraisal_goals (need migration)
- appraisal_ratings (need migration)

### 10. Strategic Plans Module
**Database Tables:**
- strategic_plans (need migration)
- strategic_goals (need migration)
- strategic_objectives (need migration)

### 11. Complaints Module
**Database Tables:**
- complaints (need migration)
- complaint_responses (need migration)

### 12. Payroll Module
**Complex calculations**
**Database Tables:**
- payroll_periods (need migration)
- payroll_records (need migration)
- payroll_allowances (need migration)
- payroll_deductions (need migration)

### 13. Settings Module
**Key-value storage**
**Database Tables:**
- settings (need migration)

### 14. Notifications Module
**Database Tables:**
- notifications (exists in Laravel, need migration)
- notification_reads (need migration)

### 15. Audit Logs Module
**Database Tables:**
- audit_logs (need migration)

## File Structure

### Legacy Backend (Reference)
```
backend/
├── app/
│   ├── Bootstrap.php
│   ├── Controllers/
│   │   ├── BaseController.php
│   │   ├── AuthController.php
│   │   ├── EmployeeController.php
│   │   ├── DepartmentController/ (empty)
│   │   ├── AttendanceController/ (empty)
│   │   ├── LeaveController/ (empty)
│   │   ├── UserController/ (empty)
│   │   ├── SectionController/ (empty)
│   │   ├── DashboardController.php
│   │   ├── ReportsController.php
│   │   ├── Appraisal/ (empty)
│   │   ├── StrategicPlan/ (empty)
│   │   └── ...
│   ├── Core/
│   ├── Helpers/
│   │   ├── Auth.php
│   │   ├── Database.php
│   │   ├── Hash.php
│   │   ├── Session.php
│   │   └── AuthorizationService.php
│   ├── Middleware/
│   │   ├── BaseMiddleware.php
│   │   ├── AuthenticationMiddleware.php
│   │   └── AuthorizationMiddleware.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Attendance.php
│   │   ├── Consent.php
│   │   └── UserPagePermission.php
│   ├── Policies/
│   │   └── EmployeePolicy.php
│   ├── Repositories/
│   │   ├── Contracts/
│   │   └── [Various repositories]
│   ├── Responses/
│   │   ├── JsonResponse.php
│   │   └── [Various resources]
│   ├── Services/
│   │   ├── Contracts/
│   │   └── [Various services]
│   ├── Templates/
│   │   └── Emails/
│   └── Validators/
│       ├── Contracts/
│       └── [Various validators]
├── config/
│   └── database.php
├── database/
│   ├── migrations/
│   │   ├── 001_*.sql
│   │   ├── 002_*.sql
│   │   ├── 003_*.sql
│   │   └── 004_*.sql
│   └── muwasco (1).sql
├── routes/
│   └── api.php
├── storage/
├── tests/
├── api.php (entry point)
└── bootstrap.php
```

### Laravel Backend (Target)
```
laravel/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php
│   │   │   └── Api/
│   │   │       ├── AuthController.php
│   │   │       ├── UserController.php
│   │   │       ├── EmployeeController.php
│   │   │       ├── DepartmentController.php
│   │   │       ├── SectionController.php
│   │   │       ├── AttendanceController.php
│   │   │       ├── LeaveController.php
│   │   │       ├── DashboardController.php
│   │   │       ├── ReportsController.php
│   │   │       ├── AppraisalController.php
│   │   │       ├── StrategicPlanController.php
│   │   │       ├── ComplaintController.php
│   │   │       ├── PayrollController.php
│   │   │       ├── SettingController.php
│   │   │       └── NotificationController.php
│   │   ├── Middleware/
│   │   │   ├── JwtMiddleware.php
│   │   │   └── RoleMiddleware.php
│   │   └── Requests/
│   │       ├── Auth/
│   │       ├── EmployeeRequest.php
│   │       └── ...
│   ├── Models/
│   │   ├── User.php
│   │   ├── Employee.php
│   │   ├── Department.php
│   │   ├── Section.php
│   │   ├── Attendance.php
│   │   ├── LeaveApplication.php
│   │   ├── LeaveType.php
│   │   ├── Notification.php
│   │   └── ...
│   ├── Services/
│   │   ├── AuthService.php
│   │   ├── UserService.php
│   │   ├── EmployeeService.php
│   │   └── ...
│   ├── Repositories/
│   │   ├── Contracts/
│   │   └── [Various repositories]
│   ├── Resources/
│   │   ├── EmployeeResource.php
│   │   └── ...
│   ├── Policies/
│   │   ├── UserPolicy.php
│   │   └── ...
│   └── Helpers/
│       ├── JwtHelper.php
│       └── ...
├── database/
│   ├── migrations/
│   │   ├── 2024_01_01_000001_create_users_table.php
│   │   ├── [All existing migrations]
│   │   ├── [Missing migrations to be created]
│   │   └── ...
│   └── seeders/
│       ├── DatabaseSeeder.php
│       ├── UserSeeder.php
│       └── ...
├── routes/
│   ├── api.php
│   └── web.php
├── config/
│   ├── auth.php
│   ├── cors.php
│   └── jwt.php (new)
├── tests/
│   ├── Feature/
│   └── Unit/
└── ...
```

## Missing Migrations

Create these migration files in `laravel/database/migrations/`:

1. `create_roles_table.php`
2. `create_permissions_table.php`
3. `create_role_permissions_table.php`
4. `create_user_roles_table.php`
5. `create_subsections_table.php`
6. `create_financial_years_table.php`
7. `create_complaints_table.php`
8. `create_complaint_responses_table.php`
9. `create_appraisals_table.php`
10. `create_appraisal_goals_table.php`
11. `create_appraisal_ratings_table.php`
12. `create_strategic_plans_table.php`
13. `create_strategic_goals_table.php`
14. `create_strategic_objectives_table.php`
15. `create_kpis_table.php`
16. `create_work_plans_table.php`
17. `create_work_plan_tasks_table.php`
18. `create_notifications_table.php`
19. `create_notification_reads_table.php`
20. `create_audit_logs_table.php`
21. `create_settings_table.php`
22. `create_consent_records_table.php`
23. `create_delegates_table.php`
24. `create_leave_attachments_table.php`
25. `create_payroll_periods_table.php`
26. `create_payroll_records_table.php`
27. `create_payroll_allowances_table.php`
28. `create_payroll_deductions_table.php`
29. `create_absent_deductions_table.php`
30. `create_absent_exemptions_table.php`
31. `create_activities_table.php`

## Git Workflow

```bash
# Create main migration branch
git checkout -b feature/laravel-migration

# Create sub-branches for each phase
git checkout -b feature/legacy-complete
# ... complete legacy
git checkout main
git merge feature/legacy-complete

git checkout -b feature/laravel-setup
# ... setup Laravel
git checkout main
git merge feature/laravel-setup

git checkout -b feature/migrate-authentication
# ... migrate auth
git checkout main
git merge feature/migrate-authentication

# Continue for each module...
```

## Testing Strategy

### After Each Module:
1. Run legacy tests: `cd backend && php run_tests.php`
2. Run Laravel tests: `cd laravel && php artisan test`
3. Test API endpoints with Postman
4. Test React frontend pages
5. Verify database integrity
6. Check for PHP errors
7. Check for console errors in frontend

### Test Checklist Per Module:
- [ ] Login/logout works
- [ ] JWT tokens work
- [ ] CRUD operations work
- [ ] Validation works
- [ ] Authorization works
- [ ] Database integrity maintained
- [ ] No PHP errors
- [ ] No SQL errors
- [ ] Frontend compatible

## Risks & Mitigation

| Risk | Impact | Mitigation |
|------|--------|------------|
| Legacy backend incomplete | High | Complete legacy first before migrating |
| JWT implementation issues | High | Use proven library (tymon/jwt-auth) |
| Data loss during migration | Critical | Backup database, test migrations thoroughly |
| Frontend incompatibility | Medium | Test after each module migration |
| Business logic changes | High | Document all legacy logic before migrating |
| Time overrun | Medium | Prioritize critical modules first |

## Success Criteria

1. ✅ All legacy functionality ported to Laravel
2. ✅ React frontend works without changes (or minimal changes)
3. ✅ JWT authentication working
4. ✅ All CRUD operations functional
5. ✅ All business rules preserved
6. ✅ Database integrity maintained
7. ✅ Tests passing
8. ✅ No console errors
9. ✅ Documentation complete
10. ✅ Performance acceptable

## Next Steps

1. **IMMEDIATE**: Review this plan and approve
2. Create git branch for migration
3. Start Phase 1: Complete legacy backend
4. Begin with Authentication module
5. Test thoroughly after each module
6. Commit after each completed module
7. Proceed to Laravel migration
8. Final testing and deployment

---

**Document Version**: 1.0  
**Date**: 2026-08-03  
**Status**: Ready for Review