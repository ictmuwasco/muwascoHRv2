# HR Policy & Procedures Manual — Module Guide

Implementation of the MUWASCO HR Policy & Procedures module (dashboard card,
policy reader, search, versioning/publishing workflow, acknowledgements,
HR administration, AI policy integration). Change log + deployment guide.

## 1. Architecture summary

| Layer | What was added |
|---|---|
| Database | `backend/database/migrations/081_hr_policies.sql` — 5 tables + RBAC seeds |
| Models | `HrPolicyDocument`, `HrPolicySection`, `HrPolicyAcknowledgement`, `HrPolicyBookmark` |
| Services | `App\Services\HrPolicy\PolicyService` (workflow, publishing, KB mirror, notifications, audit), `PolicyFileService` (private-file upload/stream), `HrPolicySearchService` (server-side search) |
| Controllers | `Controllers\HR\HrPolicyController` (employee surface), `Controllers\Settings\HrPolicyAdminController` (admin surface) |
| Validator | `App\Validators\HrPolicyValidator` (HTTP input shape only; business rules live in PolicyService) |
| AI | `App\Services\AI\Tools\SearchHrPolicyTool` in `AiToolRegistry` — cites PUBLISHED sections only, never invents provisions |
| Frontend service | `frontend/src/api/services/hrPolicyService.ts` |
| Frontend pages | `pages/hr-policies/HrPolicyReader.tsx` (employee reader), `pages/settings/HrPolicies.tsx` (admin) |
| Route registry | `frontend/src/config/pagePermissions.jsx` — `/hr/policies*` (`hr_policies:view`), `/settings/hr-policies` (`hr_policies:manage`), `hr-policies` Settings tab |
| Dashboard | `pages/dashboard/Dashboard.tsx` — "HR Policy & Procedures" card (version, effective date, section count) |
| Sidebar | `components/Sidebar.jsx` — "HR Policies" entry for every `hr_policies:view` role |
| Shell guard | `App.jsx` `SettingsShellGuard` + `canOpenSettingsShell()` (see §7) |

## 2. Database changes (migration 081)

* `hr_policy_documents` — one row per VERSION. `status` ENUM
  (`draft|review|published|archived`), `source_type` ENUM (AI source
  hierarchy: `manual|cba|circular|law|procedure|handbook`), private
  `file_path` + server-generated `stored_name` + SHA-256 `file_hash`,
  `section_count` (dashboard card), single-active guarantee via the nullable
  UNIQUE `active_token`, soft delete (`deleted_at` — versions never
  silently removed).
* `hr_policy_sections` — section tree (`parent_id`), `section_number`,
  `title`, `content` (official wording PRESERVED VERBATIM), page refs,
  `sort_order`. Indexed for search/navigation.
* `hr_policy_acknowledgements` — per-employee "accessed and read" receipts
  (unique per document+user, IP + user agent captured).
* `hr_policy_bookmarks`, `hr_policy_recent_views` — per-user, never modify
  official policy.
* RBAC seeds: `hr_policies:view` + `hr_policies:acknowledge` → EVERY role;
  `hr_policies:manage` + `hr_policies:publish` → `hr_manager`, `super_admin`.

## 3. API endpoints (registered in root `api.php`)

Employee (`hr_policies:view`): `GET /hr-policies/current|search|bookmarks|
recent|sections/{id}|{id}|{id}/sections|{id}/file`,
`POST /hr-policies/{id}/acknowledge`, bookmarks add/remove.
Admin (`hr_policies:manage`/`publish`): `GET|POST /settings/hr-policies`,
`PUT /settings/hr-policies/{id}`, `POST /settings/hr-policies/{id}/status`
(draft|review only), `/publish` (`hr_policies:publish`), `/archive`,
`DELETE /settings/hr-policies/{id}` (soft), `/history`, `/acknowledgements`.

Status transitions are DEDICATED endpoints: the metadata PUT rejects
`published|archived` by design (`HrPolicyValidator`), publish/archive/delete
each own their endpoint + throttle + audit entry.

## 4. Security model

* Policy files live in PRIVATE storage (`backend/storage/policies`), never
  the webroot; streamed only through permission-checked endpoints with
  `X-Content-Type-Options: nosniff` and server-side `Content-Disposition`.
* Uploads: extension + MIME allowlist (pdf/doc/docx), size cap, random
  server-generated filename, SHA-256 integrity pin; client filename is
  display-only.
* Employees only ever receive PUBLISHED documents/sections — drafts/review/
  archived are filtered server-side (IDOR-safe: unknown id → 404).
* Authorization is enforced by the existing `AuthorizationMiddleware` +
  permission catalog; no new authz mechanism. All admin actions are audited
  via the existing `AuditService` (upload/edit/status/publish/archive/delete
  with before/after snapshots).

## 5. Publishing workflow

DRAFT → REVIEW → PUBLISHED → ARCHIVED. Publishing is transactional: the new
version becomes the single active policy, the previous active version is
archived in the same transaction, the policy is mirrored into the AI
knowledge base (`ai_knowledge_documents`/`ai_knowledge_chunks`,
doc_type=policy), an in-app announcement is sent to all employees (existing
notification system — no duplicate architecture), and the action is audited.

## 6. AI integration ("Ask AI About This Policy")

The PolicyReader dispatches a window CustomEvent (`muwasco:ask-ai`) that the
global assistant widget (`AiAssistantWidget`) consumes: it opens the panel,
initialises the conversation and seeds the question. The assistant uses the
`search_hr_policy` tool (permission `hr_policies:view`, re-checked per call)
to cite the actual PUBLISHED section (number/title/page ref/version) and a
"View Section X.X" deep link. It never invents provisions; when nothing
matches it says to consult HR & Administration.

## 7. Settings shell guard (frontend-only fix)

`/settings` previously required the super_admin-only `settings:view`, which
contradicted the documented all-roles Notifications tab and would have locked
HR managers out of `/settings/hr-policies`. The shell is now gated by
`canOpenSettingsShell()` (= `SETTINGS_VISIBILITY_PERMISSIONS`, any of
`settings:view|settings:notifications|hr_policies:manage`); every tab stays
independently permission-guarded and the backend enforces everything
independently. UX only — no backend change.

## 8. Document ingestion

`scripts/ingest_policy_manual.php <path-to-docx>` reads the official manual
via ZipArchive (`word/document.xml`), maps headings/numbering to the section
tree, inserts it as DRAFT and emits a parse report flagging anything unclear
for HR review. Official wording is never rewritten. Run:

    php backend/database/run.php
    php scripts/ingest_policy_manual.php "C:\path\MUWASCO PP MANUAL.docx"

After HR review of the parse report, set the version to REVIEW, then PUBLISH
from Settings → HR Policies.

## 9. Deployment / rollback

Deploy:
1. `php backend/database/run.php` (applies 081 — idempotent:
   `CREATE TABLE IF NOT EXISTS` + `ON DUPLICATE KEY UPDATE` seeds).
2. Deploy backend + frontend code; `npm run build` for the frontend.
3. Run the ingestion script (§8) and publish v1 after HR review.

Rollback:
1. `DROP TABLE hr_policy_recent_views, hr_policy_bookmarks,
   hr_policy_acknowledgements, hr_policy_sections, hr_policy_documents;`
   (children first). Files remain in private storage (delete
   `backend/storage/policies` manually if desired).
2. Remove the `hr_policies` rows from `role_permissions`.
3. Revert the code commit (no other schema dependencies).

## 10. Tests

* Backend: `tests/Unit/Authorization/*` (permission catalog + route→permission
  map + matrix — pass, 378 assertions; DB-backed checks skip when no DB).
* Frontend: `npx vitest run` — 79/80 pass (the 1 failure is a pre-existing
  flaky LeaveApplication timeout, unrelated).
* Production build: `npx vite build` — passes.

## 11. Known limitations / HR decisions pending

* Ingestion parse report must be reviewed by HR before v1 is published.
* Acknowledgement wording: "I confirm that I have accessed and read the
  MUWASCO HR Policy & Procedures Manual." — non-blocking "Mark as Read";
  legal-agreement wording requires HR/legal approval.
* Policy↔module links (e.g. Leave Application → Section 12.7.1) are ready to
  add via `hrPolicyService.search()` + the reader deep link; not yet placed
  in the individual modules.
* Discrepancy report (policy vs current system logic, §31) is a separate HR
  review task — no business rules were changed.
