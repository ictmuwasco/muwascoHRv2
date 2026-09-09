# PHASE L2 — Authentication & Authorization

> Branch: `feature/phase5-backend-domain-architecture`
> Prerequisite: L1 complete
> Objective: Sanctum token-based auth, login/logout/user endpoints, Form Requests, rate limiting.

## Phase report (mandate §15 template)

```text
Phase:              L2 — Authentication & Authorization
Objective:          Sanctum token-based auth, login/logout/user endpoints,
                    Form Requests, rate limiting, account-status checks,
                    unauthenticated envelope, green test suite (13 tests).
Files created:      laravel/app/Http/Controllers/Api/Auth/AuthController.php,
                    laravel/app/Http/Requests/Auth/LoginFormRequest.php,
                    laravel/app/Http/Requests/Auth/ChangePasswordFormRequest.php,
                    laravel/tests/Feature/Auth/AuthControllerTest.php
Files modified:     laravel/bootstrap/app.php, laravel/config/auth.php,
                    laravel/config/sanctum.php, laravel/routes/api.php,
                    laravel/app/Models/User.php,
                    laravel/database/factories/UserFactory.php,
                    laravel/phpunit.xml
Database changes:   personal_access_tokens table (Sanctum migration)
Business rules:     email normalized; password verified before status check;
                    inactive accounts get same message; rate limiting;
                    logout revokes token; minimal /auth/user payload
Security impact:    Sanctum token auth; envelope for 401s; no user enumeration
Tests performed:    Laravel: 13 tests / 63 assertions / 0 failures
Results:           Green; all auth endpoints return the unified envelope
Next recommended    L3 — Core Configuration & Employees
phase:
```