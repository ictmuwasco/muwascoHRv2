# PHASE L1 — Laravel Foundation (skeleton, env, auth scaffolding)

> Branch: `feature/phase5-backend-domain-architecture`
> Prerequisite: L0 complete (green backend suite, clean tree, S1 closed)
> Objective: establish the Laravel backend skeleton as a live, green,
> git-tracked codebase that can serve the unified API envelope and run
> its own test suite against a MySQL test database.

## Phase report (mandate §15 template)

```text
Phase:              L1 — Laravel Foundation
Objective:          Portable PHP 8.3 + Laravel 11 skeleton, test DB, Sanctum,
                    unified API envelope, baseline migrations from live schema,
                    green test suite (5 tests).
Files inspected:    laravel/.env (live DB = muwasco), laravel/.env.example,
                    laravel/.gitignore, laravel/phpunit.xml, laravel/composer.json,
                    laravel/bootstrap/app.php, laravel/routes/api.php,
                    laravel/app/Support/ApiResponse.php,
                    laravel/app/Http/Middleware/EnsureRequestId.php,
                    laravel/app/Http/Controllers/Api/V1/PingController.php,
                    laravel/app/Models/User.php (updated),
                    laravel/database/migrations/*.php (baseline from live schema)
Files created:      laravel/.env.testing, laravel/app/Support/ApiResponse.php,
                    laravel/app/Http/Middleware/EnsureRequestId.php,
                    laravel/app/Http/Controllers/Api/V1/PingController.php,
                    laravel/tests/Feature/PingTest.php,
                    laravel/config/cors.php (SPA origin allowlist),
                    laravel/routes/api.php (v1 prefix, /ping route)
Files modified:     laravel/bootstrap/app.php (Sanctum middleware, proxy trust),
                    laravel/app/Models/User.php (map to live users table,
                    HasApiTokens, fillable/casts/hidden, relationships),
                    laravel/phpunit.xml (MySQL test DB instead of SQLite
                    in-memory — live migrations are MySQL-only),
                    laravel/.gitignore (added .env.testing, .env.staging),
                    .gitignore (added !laravel/phpunit.xml to prevent
                    global phpunit.xml rule from ignoring it),
                    laravel/.gitignore (added .env.testing/.env.staging)
Database changes:   110 baseline migrations from live schema executed against
                    admin_hrdemo_test; all down() methods guarded with
                    RuntimeException (schema is shared/inherited — never
                    drop or alter in rollback). personal_access_tokens
                    migration deduplicated.
Business rules:     none changed (baseline = read-only representation of live data)
Security impact:    Sanctum installed (SPA cookie auth); CORS restricted to
                    Vite dev origin + prod origin; X-Request-ID on every
                    response for audit correlation; .env testing config
                    isolated from live prod credentials
Performance impact: none (skeleton phase — envelope + ping only)
Tests performed:    Laravel: 5 tests / 19 assertions / 0 failures (PingTest x3,
                    ExampleTest Unit x1, ExampleTest Feature x1)
Results:           Green; /api/v1/ping returns {success:true, message, data:
                    {service,version,time,framework}} with X-Request-ID header
Remaining risks:   User model references Employee/Department/Section/etc.
                    models that don't exist yet (created lazily in L2/L3);
                    sessions table migration not run yet (SESSION_DRIVER=array
                    in tests)
Next recommended    L2 — Authentication & Authorization (login/logout/user
phase:               endpoint with Sanctum, AuthController, FormRequests,
                    AuthorizationService + Policies/Gates, OrgScope trait,
                    rate-limit + account-status checks, audit event logging)
```

## Key architectural decisions

1. **Schema reuse (D3)**: The 110 baseline migrations in `laravel/database/migrations/`
   are generated from the LIVE `admin_hrdemo` database — they represent the
   inherited schema exactly. Laravel queries the live `muwasco` database
   directly (no data migration). `down()` methods are all guarded with
   `RuntimeException` because this is a shared schema during the strangler
   cutover — destructive rollback is never safe.

2. **Portable PHP 8.3**: PHP 8.3.33 is under `tools/php83/`, driven via
   Composer. XAMPP's PHP (8.0.30) is untouched and continues serving the
   legacy PHP app.

3. **Sanctum**: Installed and auto-discovered. `EnsureFrontendRequestsAreStateful`
   middleware registered in `bootstrap/app.php`. The `users` table uses
   `personal_access_tokens` (no `remember_token` column in the live schema,
   so session-based auth via cookies would not match — Sanctum's token
   approach is the correct fit).

4. **Test database**: `phpunit.xml` configured to use `admin_hrdemo_test`
   (MySQL) instead of SQLite in-memory because the baseline migrations are
   MySQL-specific (`enum` types, `integer` PKs, engine-specific features).

5. **API envelope**: Every controller uses the `ApiResponse` trait so the
   `{success, message, data, errors}` shape the React app expects can never
   drift (fixes audit finding C4).

6. **CORS**: Restricted to Vite dev origin (`http://localhost:5173`) and
   the prod origin — never `*`. `supports_credentials=true` requires the
   explicit allowlist.