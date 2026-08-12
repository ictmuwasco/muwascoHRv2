# Departments Page Fixes

## Overview
This document describes the fixes implemented for the Departments page in `frontend/src/pages/Departments.jsx` and related backend components.

## Issues Fixed

### 1. "Unknown" Display for Sections and Subsections
**Problem:** Sections and subsections tables were displaying "Unknown" in the Department and Section columns respectively, even though the data existed in the database.

**Root Cause:** 
- Table column render functions were using incorrect parameter signature
- The Table component expects `(value, row)` but the code was using `(row)`
- Subsections API was not enriching data with section names

**Solution:**
- Fixed render functions in `Departments.jsx` to use correct signature: `(value, row)`
- Added `section_name` enrichment in `SubsectionController.php` 
- Updated `getDepartmentName()` and `getSectionName()` helper functions to use the first parameter (value) instead of row object

**Files Modified:**
- `frontend/src/pages/Departments.jsx`
- `backend/app/controllers/SubsectionController.php`

### 2. Subsection Modal Section Dropdown Not Filtering
**Problem:** When adding a subsection, the section dropdown was not showing any sections after selecting a department.

**Root Cause:** 
- No API endpoint support for filtering sections by department_id
- Frontend was trying to filter locally but sections data wasn't being filtered correctly

**Solution:**
- Added `department_id` query parameter support in `SectionController::indexAction()`
- Implemented dynamic section fetching in `Departments.jsx` using `useEffect`
- When department is selected, API call is made to `/api/sections?department_id=X`
- Section dropdown now displays only sections belonging to the selected department
- Added helpful message when no sections are found for selected department

**Files Modified:**
- `frontend/src/pages/Departments.jsx`
- `backend/app/controllers/SectionController.php`

### 3. Action Buttons (Edit/Delete) Not Working
**Problem:** Clicking Edit or Delete buttons on departments, sections, or subsections resulted in errors.

**Root Cause:** 
- Render functions were passing incorrect parameters to handlers
- Department actions column was using `(row)` instead of `(value, row)`
- No safety checks for undefined row objects

**Solution:**
- Fixed all render function parameters to use correct signature `(value, row)`
- Added safety checks: `if (!row || !row.id) return null`
- All edit and delete handlers now receive the full row object correctly

**Files Modified:**
- `frontend/src/pages/Departments.jsx`

### 4. 500 Errors on Section/Subsection Create/Update
**Problem:** Creating or updating sections and subsections resulted in 500 Internal Server Error.

**Root Causes and Solutions:**

#### a) Wrong Column Name in SQL Query
- **Problem:** `getSubsections()` was querying `ss.status = 'active'` but column is named `is_active`
- **Fix:** Changed SQL to use `ss.is_active = 1`

#### b) Non-existent Status Field
- **Problem:** Service was trying to insert/update `status` field which doesn't exist in sections/subsections tables
- **Fix:** Removed `status` field from create/update operations in `DepartmentService.php`

#### c) Department ID Type Mismatch
- **Problem:** Frontend sends `department_id` as string, but `nameExists()` expects `int`
- **Fix:** Added type casting in `validateSectionData()` to cast to `(int)` before calling `nameExists()`

#### d) Department ID Validation Too Strict
- **Problem:** Validation required department_id, but database schema shows it as nullable
- **Fix:** Made department_id optional in validation logic

#### e) Empty String vs Null
- **Problem:** Frontend was sending empty string `''` instead of `null` for unselected department_id
- **Fix:** Updated frontend to send `null` when department_id is not selected

**Files Modified:**
- `backend/app/repositories/SectionRepository.php`
- `backend/app/services/DepartmentService.php`
- `frontend/src/pages/Departments.jsx`

### 5. Enhanced Error Handling
**Improvement:** Added better error logging and user feedback

**Changes:**
- Added detailed error logging in controllers with stack traces
- Added user-friendly error alerts showing actual error messages
- Improved console logging for debugging

**Files Modified:**
- `backend/app/controllers/SectionController.php`
- `backend/app/controllers/SubsectionController.php`
- `frontend/src/pages/Departments.jsx`

## Database Schema Reference

### Sections Table
```sql
CREATE TABLE sections (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT(20) UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    head_employee_id BIGINT(20) UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sections_department (department_id),
    INDEX idx_sections_active (is_active)
);
```

### Subsections Table
```sql
CREATE TABLE subsections (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id BIGINT(20) UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    head_employee_id BIGINT(20) UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subsections_section (section_id),
    INDEX idx_subsections_active (is_active)
);
```

## API Changes

### GET /api/sections
**New Query Parameter:**
- `department_id` (optional): Filter sections by department ID

**Example:**
```
GET /api/sections?department_id=2
```

**Response:** Returns only sections belonging to the specified department

## Testing

All CRUD operations have been tested and verified:
- ✅ Create Department
- ✅ Update Department
- ✅ Delete Department
- ✅ Create Section
- ✅ Update Section
- ✅ Delete Section
- ✅ Create Subsection
- ✅ Update Subsection
- ✅ Delete Subsection
- ✅ Section dropdown filtering by department
- ✅ Display of relational data (department names, section names)

## Notes

- The `department_id` field in sections table is nullable
- The `status` column does not exist; use `is_active` instead
- Frontend form select values are strings; backend expects integers for IDs
- All API responses include enriched data with related entity names