# Hybrid Authorization System - Permission Overrides

## Overview

This document describes the Hybrid Authorization System implementation that combines Role-Based Access Control (RBAC) with user-specific permission overrides.

## Architecture

### Authorization Hierarchy (Priority Order)

1. **Explicit User Deny** (Highest Priority) - If a page is explicitly denied for a user, access is always denied
2. **Explicit User Grant** - If a page is explicitly granted to a user, access is allowed even if role doesn't allow it
3. **Role Permissions** - Fall back to the user's assigned role permissions
4. **Default Deny** - If no permission exists, deny access

### Components

#### 1. Database Layer
- **Table**: `user_page_permissions`
- **Purpose**: Stores user-specific permission overrides (allow/deny)
- **Location**: `backend/database/migrations/003_user_page_permissions.sql`

#### 2. Model Layer
- **File**: `backend/app/Models/UserPagePermission.php`
- **Purpose**: Data access layer for user page permissions
- **Key Methods**:
  - `getByUserId()` - Get all overrides for a user
  - `getByUserAndPage()` - Get specific permission override
  - `setPermission()` - Create or update override
  - `removePermission()` - Remove override (soft delete)
  - `getAllWithUserInfo()` - Get permissions with user details for audit

#### 3. Service Layer
- **File**: `backend/app/Helpers/AuthorizationService.php`
- **Purpose**: Centralized authorization logic implementing hybrid RBAC + overrides
- **Key Methods**:
  - `hasPageAccess()` - Check if user has access to a page
  - `getEffectivePermission()` - Get permission with source information
  - `getAllEffectivePermissions()` - Get all permissions for preview
  - `setPermissionOverride()` - Set a permission override
  - `removePermissionOverride()` - Remove a permission override
  - `clearCache()` - Clear permission cache

#### 4. Controller Layer
- **File**: `backend/app/Controllers/PermissionOverridesController.php`
- **Purpose**: Handle HTTP requests for permission management
- **Routes**:
  - `GET /admin/permission-overrides` - List employees with search/filters
  - `GET /admin/permission-overrides/manage/{userId}` - Manage employee permissions
  - `POST /admin/permission-overrides/save/{userId}` - Save permission changes
  - `GET /admin/permission-overrides/effective/{userId}` - Get effective permissions (AJAX)
  - `GET /admin/permission-overrides/search` - Search employees (AJAX)

#### 5. View Layer
- **File**: `backend/app/Views/admin/permission_overrides/index.php` - Employee list with filters
- **File**: `backend/app/Views/admin/permission_overrides/manage.php` - Permission management interface

#### 6. Integration Layer
- **File**: `backend/app/Helpers/Auth.php` - Updated to use hybrid authorization
- **File**: `backend/app/Core/Application.php` - Routes for permission overrides
- **File**: `backend/app/Views/components/admin_tabs.php` - New tab added

## Installation

### Step 1: Run Database Migration

Execute the migration SQL file to create the `user_page_permissions` table:

```bash
mysql -u username -p database_name < backend/database/migrations/003_user_page_permissions.sql
```

Or via phpMyAdmin:
1. Open phpMyAdmin
2. Select your database
3. Go to "Import" tab
4. Choose file: `backend/database/migrations/003_user_page_permissions.sql`
5. Click "Go"

### Step 2: Verify Installation

Check that the table was created:

```sql
DESCRIBE user_page_permissions;
```

You should see the table structure with columns:
- id
- user_id
- page_id
- permission_type (allow/deny)
- granted_by
- granted_at
- updated_at
- active
- notes

## Usage

### For Super Administrators

1. Navigate to **Admin → Permission Overrides**
2. Search for an employee using:
   - Employee Number
   - Full Name
   - Department
   - Section
   - Role
   - Employment Status
3. Click "Manage" next to the employee
4. Set permissions for each page:
   - **Inherit** - Use role's default permission
   - **Allow** - Explicitly grant access
   - **Deny** - Explicitly deny access
5. Review the "Effective Permissions Preview" sidebar
6. Click "Save Changes"

### Permission States

#### Inherit (Default)
- No entry in `user_page_permissions` table
- Access determined by role permissions
- Recommended for most cases

#### Explicitly Allow
- Entry in `user_page_permissions` with `permission_type = 'allow'`
- Overrides role deny
- Grants access even if role doesn't have permission

#### Explicitly Deny
- Entry in `user_page_permissions` with `permission_type = 'deny'`
- Overrides role allow
- Denies access even if role has permission

### Example Scenarios

#### Scenario 1: Grant Access Beyond Role
```
Role: Employee (no Reports access)
Override: Reports → Allow
Result: User can access Reports
```

#### Scenario 2: Deny Access Within Role
```
Role: HR Manager (has Payroll access)
Override: Payroll → Deny
Result: User cannot access Payroll
```

#### Scenario 3: Restore Role Default
```
Role: Manager (has Employees access)
Override: Employees → Deny
Action: Change to Inherit
Result: User gets role's Employees access back
```

## Authorization Flow

Every request follows this centralized process:

```php
// 1. Authenticate the user
$userId = $_SESSION['user_id'];

// 2. Load user's assigned role
$role = $_SESSION['user_role'];

// 3. Load all role permissions (from RBAC)
$rolePermissions = RBAC::getInstance()->getRolePermissions($role);

// 4. Load all user-specific permission overrides
$userOverrides = UserPagePermission::getByUserId($userId);

// 5. Check for explicit deny
if (isset($userOverrides[$pageId]) && $userOverrides[$pageId] === 'deny') {
    return DENY;
}

// 6. Check for explicit grant
if (isset($userOverrides[$pageId]) && $userOverrides[$pageId] === 'allow') {
    return ALLOW;
}

// 7. Fall back to role permissions
if (isset($rolePermissions[$pageId])) {
    return ALLOW;
}

// 8. Default deny
return DENY;
```

## Security

### Access Control
- Only Super Administrators can:
  - View permission overrides
  - Grant permissions
  - Deny permissions
  - Remove overrides
  - View effective permissions

### Audit Logging
Every permission modification is logged with:
- Administrator making the change
- Employee affected
- Page modified
- Previous permission
- New permission
- Action (Grant, Deny, Remove Override)
- Timestamp
- IP Address
- Device Information

### CSRF Protection
All POST requests require valid CSRF tokens.

## Performance

### Caching Strategy
- Permissions are cached after first load per request
- Cache is cleared when permissions are modified
- Single permission load per session/JWT refresh
- No repetitive database queries on every request

### Cache Invalidation
Cache is automatically cleared when:
- Role permissions change
- User overrides change

## API Endpoints

### GET /admin/permission-overrides
List all employees with search and filters

**Query Parameters**:
- `search` - Search by employee ID, name, or email
- `department` - Filter by department
- `section` - Filter by section
- `role` - Filter by role
- `status` - Filter by employment status
- `page` - Page number for pagination

**Response**: HTML page with employee table

### GET /admin/permission-overrides/manage/{userId}
Manage permissions for a specific employee

**Response**: HTML page with permission management interface

### POST /admin/permission-overrides/save/{userId}
Save permission overrides for a user

**Request Body**:
```json
{
  "csrf_token": "...",
  "permissions": {
    "dashboard": "inherit",
    "payroll": "allow",
    "reports": "deny"
  }
}
```

**Response**:
```json
{
  "success": true,
  "message": "Permissions updated successfully. 1 override(s) set, 0 override(s) removed.",
  "saved_count": 1,
  "removed_count": 0
}
```

### GET /admin/permission-overrides/effective/{userId}
Get effective permissions for a user (AJAX)

**Response**:
```json
{
  "success": true,
  "permissions": [
    {
      "page_id": "dashboard",
      "page_name": "Dashboard",
      "allowed": true,
      "source": "Role",
      "permission_type": null
    },
    {
      "page_id": "payroll",
      "page_name": "Payroll",
      "allowed": true,
      "source": "User Grant",
      "permission_type": "allow"
    }
  ]
}
```

### GET /admin/permission-overrides/search
Search employees (AJAX)

**Query Parameters**:
- `q` - Search query (minimum 2 characters)

**Response**:
```json
{
  "success": true,
  "employees": [
    {
      "user_id": 123,
      "employee_id": "EMP001",
      "full_name": "John Doe",
      "department": "IT",
      "section": "Development",
      "role": "manager"
    }
  ]
}
```

## Extensibility

The system is designed to support future enhancements:

### Planned Features
- Action-level permissions (View, Create, Edit, Delete, Approve, Export)
- Temporary permissions with expiration dates
- Scheduled permissions
- Department-based overrides
- Branch/location-specific permissions
- Project-specific permissions
- Delegated access during employee leave
- Emergency "break-glass" access with automatic expiration
- Approval workflows for granting sensitive permissions
- Permission inheritance across organizational structures
- Permission templates for common exception scenarios

### How to Extend

#### Adding Action-Level Permissions
1. Update `user_page_permissions` table to include `action` column
2. Modify `AuthorizationService::hasPageAccess()` to accept action parameter
3. Update views to show action-level radio buttons
4. Update `PermissionOverridesController` to handle action parameter

#### Adding Temporary Permissions
1. Add `expires_at` column to `user_page_permissions` table
2. Update `UserPagePermission` model to check expiration
3. Add expiration date picker to manage view
4. Implement automatic cleanup of expired permissions

#### Adding Approval Workflows
1. Create `permission_requests` table
2. Create approval workflow service
3. Add notification triggers
4. Implement approval/rejection endpoints
5. Add approval dashboard for managers

## Troubleshooting

### Permission Changes Not Taking Effect
- Clear browser cache
- Verify cache was cleared in AuthorizationService
- Check that permission was saved to database
- Verify user is logged in with correct session

### Cannot Access Permission Overrides
- Verify user has `super_admin` role
- Check session is valid
- Verify route is registered in Application.php

### Migration Fails
- Check database user has CREATE TABLE privileges
- Verify no existing table with same name
- Check foreign key constraints

## Maintenance

### Regular Tasks
- Review audit logs for unusual permission changes
- Clean up expired temporary permissions (if implemented)
- Archive old audit logs periodically
- Monitor permission override statistics

### Database Optimization
- Indexes are already created on frequently queried columns
- Consider partitioning `audit_logs` table if it grows large
- Regular VACUUM/OPTIMIZE on MySQL

## Support

For issues or questions:
1. Check this documentation
2. Review audit logs in Admin → Audit Trail
3. Check application logs in `backend/storage/logs/`
4. Contact system administrator

## Version History

- **v1.0** (2026-07-07) - Initial implementation
  - Basic allow/deny overrides
  - Super admin only access
  - Audit logging
  - Search and filters
  - Effective permissions preview