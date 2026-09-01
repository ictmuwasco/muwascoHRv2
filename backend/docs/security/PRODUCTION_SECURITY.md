# Production Security Checklist

Phase 7 (2026-09). Work through this list for every production deployment.
Where a control is already enforced by the application, the evidence column
points at the enforcing code/config; remaining items are operational.

## 1. Environment

- [ ] `APP_ENV=production`, `APP_DEBUG=false` — verified via `.env.production.example`; `display_errors=0` enforced in `backend/bootstrap.php`.
- [ ] `APP_KEY`/secrets are **not** in version control (`git check-ignore .env` → ignored; `scripts/ci/secret_scan.php` blocks CI otherwise).
- [ ] **Rotate** any credential that ever appeared in Git history (known leaks: DB password `Jmwkah198`, admin defaults `ADMIN001`/`Admin@123` — see `scripts/ci/secret_scan.php` known-leak list). Values remain in history until a rewrite/amputation is approved.
- [ ] `JWT_SECRET` is a unique ≥32-byte random value per environment (fail-safe refuses to run otherwise).
- [ ] `LOG_LEVEL=info` (never `debug`).

## 2. Transport & proxy

- [ ] TLS in force (LB/nginx/Apache); port 443 only.
- [ ] `TRUSTED_PROXIES` lists the LB fleet so `X-Forwarded-Proto` is honored for secure cookies + HSTS.
- [ ] Uncomment HTTP→HTTPS redirect (`RewriteRule`) in `.htaccess` OR enforce at LB/WAF.
- [ ] Uncomment `Strict-Transport-Security` in `.htaccess` (PHP emits it on HTTPS already).
- [ ] Cookie flags verified on production: `Secure` (`SESSION_SECURE_COOKIE=true`), `HttpOnly` (always), `SameSite=Lax`.

## 3. API surface

- [ ] `CORS_ALLOWED_ORIGINS` = exact production origin(s); no wildcard.
- [ ] `CSRF_ORIGIN_ENFORCE=true` retained.
- [ ] Rate-limit buckets writable: `backend/storage/cache/rate-limits` (UTC, mode 0775) — limiter fails open with logging on unwritable storage.
- [ ] Route permission + throttle governance CI test passes (`RoutePermissionMapTest`).

## 4. Database

- [ ] Dedicated least-privilege MySQL account (`muwascohr`) — **not root**.
- [ ] No GRANT beyond the application schema (no `FILE`, no `SUPER`, no cross-DB).
- [ ] Backups encrypted at rest, restored-tested, retention honored (`BACKUP_RETENTION_DAYS`).
- [ ] `db()` connections use non-root credentials in production env.

## 5. Storage & files

- [ ] `backend/public/uploads/.htaccess` present (deny + no-exec) — CI test asserts it.
- [ ] Private uploads under `STORAGE_PATH/uploads` (0750), outside webroot.
- [ ] Migrate legacy `backend/public/uploads/employee_documents/` files to private storage and delete the tree (see Phase 7 report §remaining risks).
- [ ] `storage/`, `storage/logs`, `storage/cache` writable by the PHP user only.

## 6. Web server

- [ ] `Options -Indexes`; sensitive-file deny blocks active (`.env|log|sql|md|lock`, `composer.json`).
- [ ] `display_errors=0`; `expose_php=Off`; `upload_max_filesize`/`post_max_size` = 10M.
- [ ] PHP version patched; `allow_url_fopen`/`allow_url_include` confined; `disable_functions` hardened if possible.

## 7. Dependencies

- [ ] `composer audit` → no advisories (verified Phase 7; 6 abandoned transitive web-token packages — plan replacement).
- [ ] `npm audit` → production deps: 2 moderate react-router advisories with **no non-breaking fix** (CVE-2025-68470-bypass); schedule react-router 7.x upgrade. 14 dev-advisory items are dev-only (not shipped).
- [ ] `composer install --no-dev` on production; `php artisan`-equivalent maintenance follows `scripts/` conventions.

## 8. Operations

- [ ] Dependency update policy: minor/patch reviewed, major versions assessed before upgrade.
- [ ] Cron scripts review (attendance reminders) — they run with the app identity and must never log secrets.
- [ ] Error tracker (`system/errors`) reviewed for redaction baseline.
- [ ] Logs rotated; retention matches policy; backups of logs/audit protected.

## 9. Post-deploy verification commands

```bash
php scripts/ci/secret_scan.php
composer audit --no-interaction
php vendor/bin/phpunit
cd frontend && npm audit --omit=dev && npx vitest run
curl -sI https://hr.muwasco.co.ke | grep -iE 'strict-transport|content-security|x-frame|x-content-type|referrer-policy|permissions-policy'
curl -sI https://hr.muwasco.co.ke/hrdemo/backend/public/uploads/   # expect 403
```