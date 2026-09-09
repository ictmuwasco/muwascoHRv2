# backend/_legacy

Deleted-from-active-tree files preserved here **only** because their working-tree
copies contained uncommitted fixes (corrected `AuthorizationService` calls,
fail-closed JWT secret comment) that must not be silently lost.

These files are **dead code** — exhaustive reference searches (code, routes,
tests, docs, frontend) found zero production references:

| File | Original path | Reason it is dead | Working-tree change preserved |
|---|---|---|---|
| `Gate.php` | `backend/app/Gates/Gate.php` | No route/controller/service references `App\Gates\Gate`; authorization uses `AuthorizationMiddleware` + `AuthorizationService` directly | Corrected `AuthorizationService::getInstance()` + user-ID-keyed permission call |
| `EmployeePolicy.php` | `backend/app/Policies/EmployeePolicy.php` | No references to `App\Policies\EmployeePolicy` anywhere | Corrected `AuthorizationService::getInstance()` usage |
| `config_jwt.php` | `backend/config/config/jwt.php` | Laravel-style config file under `backend/config/config/` that the runtime `config()` loader never loads; the JWT helper reads `env('JWT_SECRET', …)` directly | Fail-closed `JWT_SECRET` comment (Phase 1 security fix) |

**Do not re-introduce these files into `backend/app` or `backend/config`** unless a
real production reference is later found. Once the corresponding commit ships,
these preserved copies can be deleted from `_legacy` as well.