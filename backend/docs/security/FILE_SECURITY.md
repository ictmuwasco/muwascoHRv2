# File Security

Phase 7 (2026-09). Upload and download security for employee documents,
leave attachments, profile images and meeting documents.

## 1. Upload policy — never trust the client

For every upload the server independently verifies **size**, **extension**,
**actual MIME** (finfo), then writes the file under a **random name** into
**non-executable storage**:

| Control | Where |
|---|---|
| Size cap (5 MB profile docs / 10 MB PHP limit) | `EmployeeController::uploadProfileDocument`, `.htaccess` |
| Extension allowlist `pdf,jpg,jpeg,png,doc,docx` | controller |
| MIME verified via `finfo_file` (spoofed client MIME rejected) | controller |
| Random stored name `doc_<time>_<16 hex>` + mapped safe extension | controller |
| Storage outside webroot | `STORAGE_PATH/uploads/documents` (0750) |
| Image integrity | `getimagesize` on image uploads |
| Content-hygiene scan | `LeaveAttachmentService` blocks `<?php`, `<script`, `<?xml`, `base64_decode(`, `shell_exec(`, `popen(` |

Upload routes are throttled (`uploads` group, `20:300`) and permission-gated
(`profile:edit`, `employees:edit`).

## 2. Critical fix — legacy upload action retired (P7-1)

The legacy `EmployeeController::uploadDocumentAction()` accepted uploads with
**no validation** and wrote them into the **web-executable** directory
`backend/public/uploads/employee_documents/` (mkdir 0777). It was **not**
routed, but remained a loaded method. Action taken:

1. Method **deleted** (zero-caller verified) — a future route registration
   pointing at it would have been remote code execution.
2. The upload tree is now guarded by `backend/public/uploads/.htaccess`.
3. The only authorized upload paths are the hardened ones:
   `POST /profile/documents`, `POST /profile/profile-image`,
   `POST /employees/{id}/profile-image`.

## 3. Download security

Every file delivery flows through an **authorized streaming endpoint** that:

1. Requires an authenticated, active account.
2. Verifies permission/ownership:
   - profile docs → owner employee OR `employees:view`
   - leave attachments → invitation/document-level ownership scoped by the
     authenticated user id
3. Resolves the stored path from the DB row (never from user-supplied path
   segments), guarding against traversal.
4. Streams with hardened headers (`SecurityMiddleware::applyStreamHeaders`):

```
Content-Type: <detected>
Content-Security-Policy: sandbox          ← even if a hostile HTML/SVG ever
                                            renders inline, no script runs
X-Content-Type-Options: nosniff
Cache-Control: private, no-store          ← PII never cached
Content-Disposition: inline               ← only PDF + images (business preview)
                  : attachment            ← everything else / leave attachments
```

## 4. Webroot lockdown (P7-2)

`backend/public/uploads/.htaccess`:

```apache
Require all denied                       # no direct URL access
php_flag engine off                      # no execution (even if rules relax)
Options -ExecCGI -Indexes
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py .asp .aspx .sh
RemoveType
AddType text/plain ...                   # serve as plain text if ever fetched
```

Legacy files (e.g. `employee_documents/`) remain readable ONLY through the
authorized endpoints (legacy-path fallback is inside `viewProfileDocumentAction`
which still runs the owner/HR gate + streaming headers).

Root `.htaccess` additionally denies `.env|log|sql|md|lock` and
`composer.json`/`composer.lock`, and disables directory listing.

## 5. Regression tests

`backend/tests/Unit/Security/SecurityPolicyConfigurationTest.php` pins the
uploads `.htaccess` (require-all-denied, php engine off, RemoveHandler/Type).
`backend/tests/Unit/Middleware/StreamHeadersTest.php` pins the streaming
headers: inline only for PDF/images, attachment + sandbox for everything else,
CRLF/quote-stripped filenames.

## 6. Remaining risk

Legacy files under `backend/public/uploads/` were stored by the retired
unvalidated action and are served through the authorized endpoint. Long term,
migrate them to `STORAGE_PATH/uploads/documents` and delete the webroot tree
(see Phase 7 report, remaining risks).