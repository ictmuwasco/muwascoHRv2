# Leave Application Enhancement

## Implementation Summary

### Backend Services Created
- `backend/app/Services/LeaveCalculationService.php` - Eligible days and deduction calculations
- `backend/app/Services/LeaveDocumentService.php` - Document upload/validation for Sick/Study Leave
- `backend/app/Services/LeaveWorkflowService.php` - Approval hierarchy logic
- `backend/app/Services/LeaveApplicationService.php` - Orchestrates submission with DB transactions

### Database
- Migration `011_leave_application_documents.sql` creates `leave_application_documents` table
- Verified via `backend/database/run_migration.php`

### API Endpoints
- `POST /api/leave/applications` - submit application with optional document
- `GET /api/leave/types` - leave types with balances for employee
- `POST /api/leave/calculate` - preview eligible days and deductions
- `GET /api/leave/applications/{id}/documents` - list documents
- `GET /api/leave/applications/{id}/documents/{documentId}` - view document

### Frontend
- `frontend/src/pages/LeaveApplication.jsx` - new application form
- `frontend/src/pages/Leave.jsx` - updated to navigate to `/leave/apply`
- `frontend/src/App.jsx` - added `/leave/apply` route

## Business Rules Preserved
- Leave balances sourced from `employee_leave_balances`
- Weekend/holiday rules via `counts_weekends` and `count_holidays`
- Approval hierarchy preserved
- Claim a Day and Leave of Absence rules preserved
- Overlap detection preserved

## New Behavior
- Zero eligible days rejected before insert
- Sick/Study Leave require supporting documents
- Backend is authoritative for calculations
- Delegate selection required for all leave applications

## Delegate Feature
- `GET /api/leave/delegates` returns eligible delegate candidates based on applicant role:
  - Managing Director → Dept Heads
  - Dept Head → Section Heads in their department
  - Section Head → Subsection Heads in their section
  - Subsection Head → Employees in their subsection
  - Regular employee → Peers in their subsection
- `delegate_emp_id` stored on `leave_applications` (migration 012)
- Delegate notified via in-app notification when assigned
- Delegate column shown in Leave.jsx table

## Next Steps
- Add automated tests for zero-day validation and document requirements
- Add feature flag in `.env` for gradual rollout
- Grant delegate temporary role permissions when leave is approved
