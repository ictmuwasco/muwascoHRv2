# Leave Management Module

> Status: **Implemented.** Audited against `api.php`, `backend/app/Controllers/Leave/*`, the 12 `Leave*` services, migrations and the leave test suites.

## Overview

Leave applications with document attachments, delegate selection, financial-year balances, a team leave roster, and a full reporting module.

| | |
|---|---|
| Controllers | `LeaveController`, `LeaveRosterController`, `LeaveReportController`, `HR\FinancialYearController`, `HR\HolidayController` |
| Services | `LeaveService`, `LeaveApplicationService`, `LeaveApprovalService`, `LeaveWorkflowService`, `LeaveCalculationService`, `LeaveAttachmentService`, `LeaveDocumentService`, `LeaveProfileService`, `DelegateService`, `FinancialYearService`, `HolidayService` |
| Repositories | `LeaveRepository`, `HolidayRepository` |
| Migrations | 001 (attachments), 007 (financial years), 011 (application documents), 012 (delegates), 018 (leave roster), 032 (report indexes) |
| Tests | `LeaveValidationTest`, `LeaveDateCalculationTest`, `LeaveReportQueryServiceTest`, `LeaveRoleBasedAccessTest` |
| Frontend | Leave pages (apply / my leave / manage), Leave Reports |

## Workflow

1. **Apply** — `POST /leave/apply`: employee picks leave type and dates; `LeaveCalculationService` computes **working days** (weekends and configured holidays excluded via `HolidayRepository`); supporting documents may be attached (`LeaveAttachmentService`, tables from migrations 001/011); a delegate may be nominated (`DelegateService`, migration 012).
2. **Approve** — approvers act from `GET /leave/manage`; routing and state transitions live in `LeaveWorkflowService` / `LeaveApprovalService`. Approval history is kept per application.
3. **Balance** — entitlements are tracked per employee, leave type and **financial year** (migration 007; allocations via `POST /admin/financial-year/allocate`).
4. **Documents** — supporting documents listed per application (`GET /leave/{id}/documents`, `LeaveDocumentService`).
5. **Roster** — `LeaveRosterController` (migration 018) publishes who is away and when, so teams can plan cover.
6. **Report** — the Leave Reports module (see [reports.md](reports.md)) summarises by type / department / status / duration with export.

> The authoritative endpoint list (apply, manage, delegates, calculate, documents, plus approval transitions) is in the *Leave* section of [API_REFERENCE.md](API_REFERENCE.md).

## Eligibility & calculation

- `GET /leave/eligible-employees` and `GET /leave/eligible-delegates` back the apply-form pickers.
- `GET /leave/calculate` previews working-day counts and balance impact **before** submission (same engine as final validation — `LeaveValidationTest`, `LeaveDateCalculationTest`).

## Roles & permissions

- Employees see and manage **their own** applications; approvers see their scope via `GET /leave/manage`.
- Role-based access is enforced server-side (`LeaveRoleBasedAccessTest` pins this down) through the hybrid RBAC used across the app.
- `LeaveProfileService` resolves each employee's entitlement profile.

## Financial years & holidays

- Leave balances are scoped to financial years (`admin/financial-years*` endpoints, `FinancialYearService`); year rollover and allocations are admin operations.
- Public holidays come from the Holidays module (`HolidayController`, migration 010) and are excluded from working-day calculations and roster views.

## Related historical notes

The one-off fix log `LEAVE_APPLICATION_ENHANCEMENT.md` (archived under [archive/](archive/)) describes an earlier round of application-form fixes; this page is the living reference.

## Known gaps / notes

- Approval routing supports delegates, but there is no configurable multi-level approval chain UI — the chain is enforced in `LeaveWorkflowService`.
- No leave cancellation-after-approval workflow beyond admin correction; balances reconcile through the financial-year allocation endpoints.

