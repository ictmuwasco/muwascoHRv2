# PHASE L0 — Stabilize & Secure (pre-migration hardening)

> Branch: `feature/phase5-backend-domain-architecture`
> Objective: land the in-flight AI module safely, remediate the committed-secret
> finding (S1), reconcile the failing test baseline, and establish the verified
> starting point for the Laravel migration (phases L1–L12 per the migration
> blueprint in this repository's audit).

## Phase report (mandate §15 template)

```text
Phase:              L0 — Stabilize & Secure
Objective:          Green test baseline + clean tree + secret redaction before any Laravel work
Files inspected:    api.php, backend/config/permissions.php, backend/app/Services/DelegationService.php,
                    backend/tests/Unit/{Authorization,Delegation,Security,Controllers}, .env.example,
                    .gitignore, run_tests.php, phpunit.xml.dist, git history (nvapi- key search)
Files created:      docs/PHASE_L0_REPORT.md (this file)
Files modified:     backend/config/permissions.php (+5 minutes.* actions)
                    backend/tests/Unit/Authorization/PermissionCatalogTest.php (module count 26→27)
                    backend/tests/Unit/Delegation/DelegationWorkflowTest.php (self-approval test setup)
                    backend/app/Services/DelegationService.php (scopeLabel static-SQL)
                    backend/run_tests.php (canonical phpunit.xml.dist)
                    .env.example (API key redaction), .gitignore (probe artifacts)
Files committed:    f286083 fix: close permission-catalog drift, delegation test setup and static-SQL scope label
                    9e94e3f feat(ai): AI assistant module with controlled tools, provider abstraction
                            and performance instrumentation (64 files, +6,943)
Database changes:   none (migrations 041/042 were already applied by the AI feature work)
Business rules      none changed — the delegation self-approval guard (§32) was correct and is
affected:           now covered by a correctly-constructed positive test
Security impact:    S1 CLOSED at repo level: the AI provider API key present in the working copy
                    of .env.example was REMOVED and never existed in git history (verified via
                    `git log --all -S 'nvapi-'` → empty). MANUAL ACTION REQUIRED: rotate/revoke
                    the key at build.nvidia.com (the value circulated in local files/chat).
                    .gitignore now blocks backend/storage/*.out|*.err probe artifacts.
Performance impact: none; instrumentation (PerfTiming, InstrumentedMysqli, migration 042) is now
                    committed and forms the baseline measurement layer for later phases
Tests performed:    Backend suite (phpunit.xml.dist): 270 tests / 1187 assertions / 0 failures /
                    1 documented skip (EmployeeController HTTP-coverage notice)
                    Frontend (vitest): 12 files / 80 tests passed; npm run build green
Results:            Working tree CLEAN at 9e94e3f; suite green; pre-existing failures reconciled
Remaining risks:    Key rotation is a human step; HTTP/feature-layer test gap (tracked, closes in
                    L1+ via Laravel feature tests); duplicate-email K6 still blocks F4 wave
Next recommended    L1 — Laravel skeleton on portable PHP 8.3 (tools/php83), XAMPP untouched
phase:
```

## Findings reconciled in this phase

| ID | Finding | Root cause | Resolution |
|---|---|---|---|
| L0-1 | `PermissionCatalogTest::testCatalogDefinesModules` failed (27 vs 26) | The `delegations` module from the RBAC acting-authority commit was never added to the drift guard | Guard updated to 27 with provenance comment |
| L0-2 | `testRolePermissionRowsStayInsideTheCatalog` failed | Migration 034 seeds `role_permissions` with `meetings.minutes.{view,create,update,publish,amend}` and `MeetingMinutesService` resolves them, but the catalog never defined these actions | 5 actions added to the catalog `meetings` module (labels + `type: action`) |
| L0-3 | `DelegationWorkflowTest::testDelegateCanApproveInScopeApplication` failed (`null is not null`) | The positive test linked the delegate user to the applicant's own employee record, so the (correct) self-approval guard refused — the test duplicated `testDelegateCannotDecideOwnApplication` | Positive scenario now uses a second active employee from the SAME section (`anotherActiveEmployeeInSection` helper); skips honestly if none exists |
| L0-4 | `SqlInjectionSurfaceTest::test_no_interpolated_variables_inside_mysqli_query_strings` failed | `DelegationService::scopeLabel()` interpolated `{$table}` + `{$scopeId}` into `mysqli::query()` (safe in practice — inline whitelist + int cast — but a violation of the static-SQL invariant) | Per-scope-type static prepared statements via `match`; id bound as `i` |
| L0-5 | `run_tests.php` silently ran only 3 of 9 Unit suites | Narrow `backend/phpunit.xml` (Services/Repositories/Controllers only) | Runner now uses the canonical `phpunit.xml.dist` |
| L0-6 | S1 — AI provider API key in working copy of `.env.example` (twice) | Key pasted during local AI development; never committed (verified) | Redacted to empty placeholders; rotation documented as mandatory manual step |

## Verification evidence

```text
git log:
  9e94e3f feat(ai): AI assistant module ... (64 files, +6,943)
  f286083 fix: close permission-catalog drift, delegation test setup and static-SQL scope label (5 files)
git status: clean
Backend:  OK — Tests: 270, Assertions: 1187, Skipped: 1 (documented HTTP-coverage notice)
Frontend: 12 files / 80 tests passed (vitest)
```
