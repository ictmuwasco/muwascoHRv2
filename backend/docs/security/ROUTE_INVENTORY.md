# Route Security Inventory (Phase 7, generated)

Generated from `api.php` registrations + `backend/config/authz_allowlist.php`.
Every route requires authentication **except** the three public
allowlist routes below. Permissions are the server-defined catalog pairs
enforced by `AuthorizationMiddleware`; routes without a permission are
categorized by their reviewed allowlist group (see `authz_allowlist.php`
for the per-entry rationale).

| Method | URI | Permission | Throttle | Access |
|---|---|---|---|---|
| POST | `/auth/login` | — | — | PUBLIC — credential exchange (rate limited in controller, 5/15min per IP+account) |
| POST | `/auth/logout` | — | — | authenticated-only — allowlist group: self_service |
| POST | `/auth/refresh` | — | — | authenticated-only — allowlist group: self_service |
| GET | `/auth/user` | — | — | authenticated-only — allowlist group: self_service |
| POST | `/auth/change-password` | — | — | authenticated-only — allowlist group: self_service |
| GET | `/holidays` | — | — | authenticated-only — allowlist group: reference_data |
| GET | `/holidays/upcoming` | — | — | authenticated-only — allowlist group: reference_data |
| GET | `/holidays/{id}` | — | — | authenticated-only — allowlist group: reference_data |
| POST | `/holidays` | holidays:create | — | holidays:create (RBAC: holidays) |
| PUT | `/holidays/{id}` | holidays:edit | — | holidays:edit (RBAC: holidays) |
| DELETE | `/holidays/{id}` | holidays:delete | — | holidays:delete (RBAC: holidays) |
| GET | `/employees/search` | employees:view | — | employees:view (RBAC: employees) |
| GET | `/employees` | employees:view | — | employees:view (RBAC: employees) |
| POST | `/employees` | employees:create | 30:300 | employees:create (RBAC: employees) |
| GET | `/employees/reference` | employees:view | — | employees:view (RBAC: employees) |
| GET | `/employees/{id}` | employees:view | — | employees:view (RBAC: employees) |
| PUT | `/employees/{id}` | employees:edit | 30:300 | employees:edit (RBAC: employees) |
| DELETE | `/employees/{id}` | employees:delete | — | employees:delete (RBAC: employees) |
| GET | `/departments` | — | — | authenticated-only — allowlist group: reference_data |
| POST | `/departments` | departments:create | — | departments:create (RBAC: departments) |
| GET | `/departments/{id}` | departments:view | — | departments:view (RBAC: departments) |
| PUT | `/departments/{id}` | departments:edit | — | departments:edit (RBAC: departments) |
| DELETE | `/departments/{id}` | departments:delete | — | departments:delete (RBAC: departments) |
| GET | `/sections` | — | — | authenticated-only — allowlist group: reference_data |
| POST | `/sections` | departments:create | — | departments:create (RBAC: departments) |
| GET | `/sections/{id}` | departments:view | — | departments:view (RBAC: departments) |
| PUT | `/sections/{id}` | departments:edit | — | departments:edit (RBAC: departments) |
| DELETE | `/sections/{id}` | departments:delete | — | departments:delete (RBAC: departments) |
| GET | `/subsections` | — | — | authenticated-only — allowlist group: reference_data |
| POST | `/subsections` | departments:create | — | departments:create (RBAC: departments) |
| GET | `/subsections/{id}` | departments:view | — | departments:view (RBAC: departments) |
| PUT | `/subsections/{id}` | departments:edit | — | departments:edit (RBAC: departments) |
| DELETE | `/subsections/{id}` | departments:delete | — | departments:delete (RBAC: departments) |
| GET | `/users` | users:view | — | users:view (RBAC: users) |
| POST | `/users` | users:create | 30:300 | users:create (RBAC: users) |
| GET | `/users/{id}` | users:view | — | users:view (RBAC: users) |
| PUT | `/users/{id}` | users:edit | 30:300 | users:edit (RBAC: users) |
| DELETE | `/users/{id}` | users:delete | 30:300 | users:delete (RBAC: users) |
| PUT | `/users/{id}/toggle-status` | users:edit | 30:300 | users:edit (RBAC: users) |
| POST | `/users/{id}/change-password` | users:edit | 10:900 | users:edit (RBAC: users) |
| GET | `/attendance/today` | attendance:view | — | attendance:view (RBAC: attendance) |
| GET | `/attendance/dashboard` | attendance:view | — | attendance:view (RBAC: attendance) |
| GET | `/attendance/hr-dashboard` | attendance:view | — | attendance:view (RBAC: attendance) |
| GET | `/attendance/hr-employee-history` | attendance:view | — | attendance:view (RBAC: attendance) |
| GET | `/attendance/my-records` | attendance:view | — | attendance:view (RBAC: attendance) |
| GET | `/attendance/employee/{id}` | attendance:view | — | attendance:view (RBAC: attendance) |
| POST | `/attendance/clock-in` | — | 10:300 | authenticated-only — allowlist group: self_service |
| POST | `/attendance/clock-out` | — | 10:300 | authenticated-only — allowlist group: self_service |
| POST | `/attendance/auto-clockout` | — | 10:300 | authenticated-only — allowlist group: self_service |
| GET | `/attendance` | attendance:view | — | attendance:view (RBAC: attendance) |
| GET | `/leave` | leave:view | — | leave:view (RBAC: leave) |
| POST | `/leave/apply` | leave:apply | — | leave:apply (RBAC: leave) |
| GET | `/leave/types` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/eligible-employees` | leave:apply | — | leave:apply (RBAC: leave) |
| GET | `/leave/eligible-delegates` | leave:apply | — | leave:apply (RBAC: leave) |
| GET | `/leave/manage` | leave:manage | — | leave:manage (RBAC: leave) |
| GET | `/leave/delegates` | leave:apply | — | leave:apply (RBAC: leave) |
| GET | `/leave/calculate` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/{id}/documents` | — | — | authenticated-only — allowlist group: mixed_scope |
| GET | `/leave/{id}/documents/{documentId}` | — | — | authenticated-only — allowlist group: mixed_scope |
| PUT | `/leave/{id}/approve` | leave:approve | 120:300 | leave:approve (RBAC: leave) |
| PUT | `/leave/{id}/reject` | leave:reject | 120:300 | leave:reject (RBAC: leave) |
| PUT | `/leave/{id}/invalidate` | leave:invalidate | 120:300 | leave:invalidate (RBAC: leave) |
| PUT | `/leave/{id}/cancel` | leave:apply | 120:300 | leave:apply (RBAC: leave) |
| GET | `/leave/profile/employees` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/profile/{id}` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/profile/{id}/balances` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/profile/{id}/applications` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/profile/{id}/timeline` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/profile/{id}/summary` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/profile/{id}/export` | leave:view | 20:300 | leave:view (RBAC: leave) |
| GET | `/leave/roster/stats` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/roster/distribution` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/roster/upcoming` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/roster/departments` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/roster/matrix` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/roster/export` | leave:manage | 20:300 | leave:manage (RBAC: leave) |
| GET | `/leave/roster/employees` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/roster/financial-years` | leave:view | — | leave:view (RBAC: leave) |
| GET | `/leave/roster` | leave:view | — | leave:view (RBAC: leave) |
| POST | `/leave/roster` | leave:manage | — | leave:manage (RBAC: leave) |
| PUT | `/leave/roster/{id}` | leave:manage | — | leave:manage (RBAC: leave) |
| DELETE | `/leave/roster/{id}` | leave:manage | — | leave:manage (RBAC: leave) |
| GET | `/dashboard` | dashboard:view | — | dashboard:view (RBAC: dashboard) |
| GET | `/dashboard/stats` | dashboard:view | — | dashboard:view (RBAC: dashboard) |
| GET | `/dashboard/charts/attendance` | dashboard:view | — | dashboard:view (RBAC: dashboard) |
| GET | `/dashboard/charts/departments` | dashboard:view | — | dashboard:view (RBAC: dashboard) |
| GET | `/dashboard/charts/leave` | dashboard:view | — | dashboard:view (RBAC: dashboard) |
| GET | `/reports/employees` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/appraisal` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/documentation` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/options` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/summary` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/trends` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/by-type` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/by-department` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/by-status` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/duration` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/insights` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/records` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/leave/export` | reports:export | 20:300 | reports:export (RBAC: reports) |
| GET | `/reports/attendance/options` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/summary` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/trends` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/by-status` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/by-department` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/late-arrivals` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/working-hours` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/insights` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/compliance` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/employees` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/records` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/reports/attendance/export` | reports:export | 20:300 | reports:export (RBAC: reports) |
| GET | `/reports/{type}/export/{format}` | reports:export | 20:300 | reports:export (RBAC: reports) |
| GET | `/payroll/periods` | payroll:view | — | payroll:view (RBAC: payroll) |
| POST | `/payroll/periods` | payroll:manage | — | payroll:manage (RBAC: payroll) |
| GET | `/payroll/records` | payroll:view | — | payroll:view (RBAC: payroll) |
| POST | `/payroll/records` | payroll:manage | — | payroll:manage (RBAC: payroll) |
| GET | `/complaints` | — | — | authenticated-only — allowlist group: mixed_scope |
| POST | `/complaints` | — | — | authenticated-only — allowlist group: mixed_scope |
| PUT | `/complaints/{id}` | complaints:view | — | complaints:view (RBAC: complaints) |
| GET | `/consents` | consent:view | — | consent:view (RBAC: consent) |
| PUT | `/consents/{id}` | consent:manage | — | consent:manage (RBAC: consent) |
| GET | `/consent/status` | — | — | PUBLIC — pre-session consent status |
| POST | `/consent/verify-employee` | — | — | authenticated-only — allowlist group: self_service |
| POST | `/consent` | — | — | authenticated-only — allowlist group: self_service |
| GET | `/consent/dashboard` | consent:view | — | consent:view (RBAC: consent) |
| GET | `/consent/employees` | consent:view | — | consent:view (RBAC: consent) |
| GET | `/admin/financial-years` | financial_year:view | — | financial_year:view (RBAC: financial_year) |
| GET | `/admin/financial-years/status` | financial_year:view | — | financial_year:view (RBAC: financial_year) |
| POST | `/admin/financial-year/add` | financial_year:create | — | financial_year:create (RBAC: financial_year) |
| POST | `/admin/financial-year/allocate` | financial_year:edit | 10:300 | financial_year:edit (RBAC: financial_year) |
| GET | `/admin/financial-years/leave-types` | financial_year:view | — | financial_year:view (RBAC: financial_year) |
| GET | `/admin/financial-years/employees` | financial_year:edit | — | financial_year:edit (RBAC: financial_year) |
| GET | `/appraisals` | performance:view | — | performance:view (RBAC: performance) |
| POST | `/appraisals` | performance:manage | — | performance:manage (RBAC: performance) |
| GET | `/appraisals/{id}` | performance:view | — | performance:view (RBAC: performance) |
| PUT | `/appraisals/{id}` | performance:manage | — | performance:manage (RBAC: performance) |
| DELETE | `/appraisals/{id}` | performance:manage | — | performance:manage (RBAC: performance) |
| GET | `/appraisals/pending` | performance:view | — | performance:view (RBAC: performance) |
| GET | `/appraisals/employee/{id}` | performance:view | — | performance:view (RBAC: performance) |
| PUT | `/appraisals/{id}/submit` | performance:manage | — | performance:manage (RBAC: performance) |
| PUT | `/appraisals/{id}/approve` | performance:manage | — | performance:manage (RBAC: performance) |
| GET | `/strategic-plans` | strategic_plan:view | — | strategic_plan:view (RBAC: strategic_plan) |
| POST | `/strategic-plans` | strategic_plan:manage | — | strategic_plan:manage (RBAC: strategic_plan) |
| PUT | `/strategic-plans/{id}` | strategic_plan:manage | — | strategic_plan:manage (RBAC: strategic_plan) |
| DELETE | `/strategic-plans/{id}` | strategic_plan:manage | — | strategic_plan:manage (RBAC: strategic_plan) |
| POST | `/strategic-plans/{id}/goals` | strategic_plan:manage | — | strategic_plan:manage (RBAC: strategic_plan) |
| PUT | `/goals/{id}` | strategic_plan:manage | — | strategic_plan:manage (RBAC: strategic_plan) |
| DELETE | `/goals/{id}` | strategic_plan:manage | — | strategic_plan:manage (RBAC: strategic_plan) |
| POST | `/strategic-plans/{id}/targets` | strategic_plan:manage | — | strategic_plan:manage (RBAC: strategic_plan) |
| PUT | `/targets/{id}` | strategic_plan:manage | — | strategic_plan:manage (RBAC: strategic_plan) |
| DELETE | `/targets/{id}` | strategic_plan:manage | — | strategic_plan:manage (RBAC: strategic_plan) |
| GET | `/performance-contracts` | performance_contract:view | — | performance_contract:view (RBAC: performance_contract) |
| POST | `/performance-contracts` | performance_contract:manage | — | performance_contract:manage (RBAC: performance_contract) |
| GET | `/performance-contracts/{id}` | performance_contract:view | — | performance_contract:view (RBAC: performance_contract) |
| PUT | `/performance-contracts/{id}` | performance_contract:manage | — | performance_contract:manage (RBAC: performance_contract) |
| DELETE | `/performance-contracts/{id}` | performance_contract:manage | — | performance_contract:manage (RBAC: performance_contract) |
| GET | `/appraisal-cycles` | — | — | authenticated-only — allowlist group: reference_data |
| POST | `/appraisal-cycles` | performance:manage | — | performance:manage (RBAC: performance) |
| PUT | `/appraisal-cycles/{id}` | performance:manage | — | performance:manage (RBAC: performance) |
| DELETE | `/appraisal-cycles/{id}` | performance:manage | — | performance:manage (RBAC: performance) |
| GET | `/strategic-plans/{id}/workplans` | workplan:view | — | workplan:view (RBAC: workplan) |
| GET | `/workplans` | workplan:view | — | workplan:view (RBAC: workplan) |
| POST | `/workplans` | workplan:manage | — | workplan:manage (RBAC: workplan) |
| POST | `/workplans/bulk` | workplan:manage | — | workplan:manage (RBAC: workplan) |
| GET | `/workplans/integrated-view` | workplan:view | — | workplan:view (RBAC: workplan) |
| GET | `/workplans/export` | workplan:view | 20:300 | workplan:view (RBAC: workplan) |
| GET | `/workplans/summary` | workplan:view | — | workplan:view (RBAC: workplan) |
| GET | `/workplans/section-sources` | workplan:view | — | workplan:view (RBAC: workplan) |
| POST | `/workplans/{id}/cascade` | workplan:manage | — | workplan:manage (RBAC: workplan) |
| GET | `/workplans/{id}/traceability` | workplan:view | — | workplan:view (RBAC: workplan) |
| GET | `/workplans/{id}/progress-history` | workplan:view | — | workplan:view (RBAC: workplan) |
| PUT | `/workplans/{id}/progress` | workplan:manage | — | workplan:manage (RBAC: workplan) |
| GET | `/workplans/{id}/dependencies` | workplan:view | — | workplan:view (RBAC: workplan) |
| GET | `/workplans/{id}` | workplan:view | — | workplan:view (RBAC: workplan) |
| PUT | `/workplans/{id}` | workplan:manage | — | workplan:manage (RBAC: workplan) |
| DELETE | `/workplans/{id}` | workplan:manage | — | workplan:manage (RBAC: workplan) |
| GET | `/contracts/{id}/kpis` | kpi:view | — | kpi:view (RBAC: kpi) |
| POST | `/contracts/{id}/kpis` | kpi:manage | — | kpi:manage (RBAC: kpi) |
| GET | `/kpis` | kpi:view | — | kpi:view (RBAC: kpi) |
| PUT | `/kpis/{id}` | kpi:manage | — | kpi:manage (RBAC: kpi) |
| DELETE | `/kpis/{id}` | kpi:manage | — | kpi:manage (RBAC: kpi) |
| GET | `/sectional-objectives` | sectional_objective:view | — | sectional_objective:view (RBAC: sectional_objective) |
| POST | `/sectional-objectives` | sectional_objective:manage | — | sectional_objective:manage (RBAC: sectional_objective) |
| GET | `/sectional-objectives/{id}` | sectional_objective:view | — | sectional_objective:view (RBAC: sectional_objective) |
| PUT | `/sectional-objectives/{id}` | sectional_objective:manage | — | sectional_objective:manage (RBAC: sectional_objective) |
| DELETE | `/sectional-objectives/{id}` | sectional_objective:manage | — | sectional_objective:manage (RBAC: sectional_objective) |
| GET | `/dashboard/strategic-performance` | dashboard:view | — | dashboard:view (RBAC: dashboard) |
| GET | `/reports/strategic-performance` | reports:view | — | reports:view (RBAC: reports) |
| GET | `/payroll/periods` | payroll:view | — | payroll:view (RBAC: payroll) |
| POST | `/payroll/periods` | payroll:manage | — | payroll:manage (RBAC: payroll) |
| GET | `/payroll/records` | payroll:view | — | payroll:view (RBAC: payroll) |
| POST | `/payroll/records` | payroll:manage | — | payroll:manage (RBAC: payroll) |
| GET | `/complaints` | — | — | authenticated-only — allowlist group: mixed_scope |
| POST | `/complaints` | — | — | authenticated-only — allowlist group: mixed_scope |
| PUT | `/complaints/{id}` | complaints:view | — | complaints:view (RBAC: complaints) |
| GET | `/notifications` | — | — | authenticated-only — allowlist group: self_service |
| POST | `/notifications/{id}/read` | — | — | authenticated-only — allowlist group: self_service |
| POST | `/notifications/read-all` | — | — | authenticated-only — allowlist group: self_service |
| GET | `/audit` | audit:view | — | audit:view (RBAC: audit) |
| GET | `/audit/statistics` | audit:view | — | audit:view (RBAC: audit) |
| GET | `/audit/filters` | audit:view | — | audit:view (RBAC: audit) |
| GET | `/audit/export` | audit:export | 20:300 | audit:export (RBAC: audit) |
| GET | `/audit/{id}` | audit:view | — | audit:view (RBAC: audit) |
| GET | `/audit-logs` | audit:view | — | audit:view (RBAC: audit) |
| GET | `/settings` | admin:view | — | admin:view (RBAC: admin) |
| PUT | `/settings` | admin:manage | — | admin:manage (RBAC: admin) |
| GET | `/profile` | profile:view | — | profile:view (RBAC: profile) |
| PUT | `/profile` | profile:edit | — | profile:edit (RBAC: profile) |
| POST | `/profile/documents` | profile:edit | 20:300 | profile:edit (RBAC: profile) |
| GET | `/profile/documents/{id}` | — | — | authenticated-only — allowlist group: self_service |
| GET | `/profile/documents/{id}/view` | — | — | authenticated-only — allowlist group: self_service |
| DELETE | `/profile/documents/{id}` | profile:edit | — | profile:edit (RBAC: profile) |
| POST | `/profile/profile-image` | profile:edit | 20:300 | profile:edit (RBAC: profile) |
| GET | `/profile/profile-image` | profile:view | — | profile:view (RBAC: profile) |
| POST | `/employees/{id}/profile-image` | employees:edit | 20:300 | employees:edit (RBAC: employees) |
| GET | `/employees/{id}/profile-image` | employees:view | — | employees:view (RBAC: employees) |
| GET | `/permissions/catalog` | permission_overrides:view | — | permission_overrides:view (RBAC: permission_overrides) |
| GET | `/permissions/statistics` | permission_overrides:view | — | permission_overrides:view (RBAC: permission_overrides) |
| GET | `/permissions/roles` | permission_overrides:view | — | permission_overrides:view (RBAC: permission_overrides) |
| GET | `/permissions/users` | permission_overrides:view | — | permission_overrides:view (RBAC: permission_overrides) |
| GET | `/permissions/users/{id}` | permission_overrides:view | — | permission_overrides:view (RBAC: permission_overrides) |
| GET | `/permissions/overrides` | permission_overrides:view | — | permission_overrides:view (RBAC: permission_overrides) |
| POST | `/permissions/users/{id}/overrides` | permission_overrides:manage | 30:300 | permission_overrides:manage (RBAC: permission_overrides) |
| DELETE | `/permissions/users/{id}/overrides` | permission_overrides:manage | 30:300 | permission_overrides:manage (RBAC: permission_overrides) |
| GET | `/my-meetings` | meetings:view | — | meetings:view (RBAC: meetings) |
| GET | `/meetings` | meetings:view | — | meetings:view (RBAC: meetings) |
| GET | `/meetings/stats` | meetings:view | — | meetings:view (RBAC: meetings) |
| GET | `/meetings/eligible-employees` | meetings:view | — | meetings:view (RBAC: meetings) |
| POST | `/meetings` | meetings:create | — | meetings:create (RBAC: meetings) |
| GET | `/meetings/{id}` | meetings:view | — | meetings:view (RBAC: meetings) |
| PUT | `/meetings/{id}` | meetings:edit | — | meetings:edit (RBAC: meetings) |
| DELETE | `/meetings/{id}` | meetings:delete | — | meetings:delete (RBAC: meetings) |
| POST | `/meetings/{id}/cancel` | meetings:edit | — | meetings:edit (RBAC: meetings) |
| GET | `/meetings/{id}/participants` | meetings:view | — | meetings:view (RBAC: meetings) |
| POST | `/meetings/{id}/participants` | meetings:invite | — | meetings:invite (RBAC: meetings) |
| DELETE | `/meetings/{id}/participants/{employeeId}` | meetings:invite | — | meetings:invite (RBAC: meetings) |
| POST | `/meetings/{id}/confirm` | meetings:confirm | — | meetings:confirm (RBAC: meetings) |
| POST | `/meetings/{id}/decline` | meetings:confirm | — | meetings:confirm (RBAC: meetings) |
| POST | `/meetings/{id}/attendance` | meetings:view_attendance | — | meetings:view_attendance (RBAC: meetings) |
| GET | `/meetings/{id}/minutes/status` | meetings:view | — | meetings:view (RBAC: meetings) |
| GET | `/meetings/{id}/minutes/options` | meetings:manage | — | meetings:manage (RBAC: meetings) |
| POST | `/meetings/{id}/minutes` | meetings:manage | — | meetings:manage (RBAC: meetings) |
| GET | `/meetings/{id}/minutes` | meetings:view | — | meetings:view (RBAC: meetings) |
| PUT | `/meetings/{id}/minutes` | meetings:manage | — | meetings:manage (RBAC: meetings) |
| POST | `/meetings/{id}/minutes/publish` | meetings:manage | — | meetings:manage (RBAC: meetings) |
| POST | `/meetings/{id}/minutes/reopen` | meetings:manage | — | meetings:manage (RBAC: meetings) |
| GET | `/notification-preferences` | — | — | authenticated-only — allowlist group: self_service |
| PUT | `/notification-preferences` | — | — | authenticated-only — allowlist group: self_service |
| GET | `/push/vapid-public-key` | — | — | authenticated-only — allowlist group: self_service |
| GET | `/push/subscriptions` | — | — | authenticated-only — allowlist group: self_service |
| POST | `/push/subscribe` | — | — | authenticated-only — allowlist group: self_service |
| DELETE | `/push/subscribe` | — | — | authenticated-only — allowlist group: self_service |
| GET | `/admin/notifications/stats` | notifications:view | — | notifications:view (RBAC: notifications) |
| GET | `/admin/notifications/audit/{employeeId}` | notifications:view | — | notifications:view (RBAC: notifications) |
| POST | `/admin/notifications/test-send` | notifications:manage | — | notifications:manage (RBAC: notifications) |
| GET | `/system/errors/stats` | system_errors:view | — | system_errors:view (RBAC: system_errors) |
| GET | `/system/errors/groups` | system_errors:view | — | system_errors:view (RBAC: system_errors) |
| POST | `/system/errors/groups/{id}/manage` | system_errors:manage | — | system_errors:manage (RBAC: system_errors) |
| GET | `/system/errors/{uuid}` | system_errors:view | — | system_errors:view (RBAC: system_errors) |
| POST | `/system/client-errors` | — | — | PUBLIC — pre-login browser error collector |
| GET | `/system/performance` | system_errors:view | — | system_errors:view (RBAC: system_errors) |
| GET | `/system/health` | system_errors:view | — | system_errors:view (RBAC: system_errors) |

## Notes

- Public surface is exactly **3 routes** (pinned by `AuthenticationGateTest`).
- `authenticated-only` self-service routes still enforce per-record scope
  in the controller/service (IDOR pins in `IdorOwnershipEnforcementTest`).
- Throttle format `max:windowSeconds`, enforced per authenticated user + IP
  after the permission gate; governance list `backend/config/rate_limits.php`
  is enforced by `RoutePermissionMapTest`.
- Sensitive-data & audit requirements per endpoint: see module docs in
  `docs/` (leave.md, attendance.md, meetings.md, reports.md, payroll.md,
  audit) and `AUDIT_LOGGING.md`.
