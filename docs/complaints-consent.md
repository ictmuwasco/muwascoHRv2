# Complaints & Consent Modules

> Status: **Implemented.** Audited against `api.php` (`ComplaintController`, `ConsentController`), migration 008 and the frontend pages.

## Complaints

| | |
|---|---|
| Endpoints | `GET /complaints`, `POST /complaints`, `PUT /complaints/{id}` (no delete — complaints are an auditable record) |
| Controller | `App\Controllers\ComplaintController` |
| Frontend | Complaints page (list + create + edit) |

Complaints are registered, tracked and updated — resolution status changes flow through `PUT /complaints/{id}`. Deletion is intentionally unavailable so the record trail is preserved.

## Consent

Records employees' data-consent decisions, versioned so a policy change can re-trigger consent.

| Endpoint | Purpose |
|---|---|
| `GET /consents` | List consent records |
| `PUT /consents/{id}` | Update a consent record |
| `GET /consent/status` | Consent status for the current user |
| `POST /consent/verify-employee` | Verify an employee identifier (self-service consent capture) |
| `POST /consent` | Record a consent decision |
| `GET /consent/dashboard` | HR overview of consent coverage |
| `GET /consent/employees` | Consent state per employee |

Migration `008_consent_version` added consent **versioning**: consent rows are tied to a policy version, and HR can track who has consented to which version (dashboard + per-employee views).

## Notes

- Both modules are served by the same hybrid RBAC and audit-logging infrastructure as the rest of the app.
- Neither module currently has automated tests — see `testing.md`.
