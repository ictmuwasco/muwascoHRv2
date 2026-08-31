# Payroll Module

> Status: **Implemented (minimal surface).** Audited against `api.php` and `backend/app/Controllers/HR/PayrollController.php`.

## Scope

A payroll **register**, not a full payroll engine: payroll periods and payroll records are created and listed. There is no payslip generation, statutory-deduction engine, or bank-file export in the current codebase.

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/payroll/periods` | List payroll periods |
| POST | `/payroll/periods` | Create a payroll period |
| GET | `/payroll/records` | List payroll records (filterable) |
| POST | `/payroll/records` | Create a payroll record |

Controller: `App\Controllers\HR\PayrollController`. No dedicated repository/service layer exists for payroll yet — it works directly over the payroll tables (see `database.md`).

## Known gaps

- No salary-structure / deduction / formula configuration.
- No integration with attendance or leave outcomes (e.g. unpaid-leave deductions) — any such logic is manual for now.
- No payslip generation or export.
