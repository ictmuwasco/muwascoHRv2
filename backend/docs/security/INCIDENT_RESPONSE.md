# Security Incident Response

Phase 7 (2026-09). Basic runbook for the most likely HR-system incidents.
Adapt severity/timeframes to your operation. Every incident ends with a
post-incident review and a regression test for the root cause where possible.

## Roles

- **On-call engineer** — first responder, containment.
- **HR system owner** — business-impact decisions (recompute, restore data).
- **Security lead** — evidence preservation, investigation, coordination.
- **Communications owner** — internal/user notifications (data-protection act
  reporting obligations apply in Kenya under the DPA 2019 for personal data
  breaches).

## 1. Credential compromise (DB password / SMTP / API key)

**Detection**: secret-scan findings, vendor breach notices, unexpected auth
failures, new access patterns.
**Containment**: rotate immediately (all environments); if the credential is
in Git history assume all historical copies are compromised.
**Investigation**: audit `users.last_login`, `audit_logs` around the exposure
window; check error logs for leaks.
**Remediation**: rotate **before** removing the visible secret; remove from
source; update `.env` on all hosts; document rotation.
**Recovery**: verify connectivity; re-run secret-scan gate.
**Documentation**: date, credential, scope, rotation evidence.

## 2. Account compromise (user/session takeover)

**Detection**: `audit_logs` anomalies (unusual login time/IP), support
tickets, password-change that the user denies.
**Containment**: disable the account (`PUT /users/{id}/toggle-status`) — this
revokes refresh tokens and the per-request `is_active` gate cuts active
sessions immediately.
**Investigation**: login history, refresh-token table, audit trail for the
user id.
**Remediation**: force password change (admin path), optionally reset consent,
re-enable only after review.
**Recovery**: monitor for recurrence.
**Documentation**: account id, vectors considered, actions taken.

## 3. Unauthorized access (including IDOR)

**Detection**: audit/export anomalies, error-tracker 403 spikes, support
reports of records visible to the wrong person.
**Containment**: block the offending account/IP (or feature-flag the
endpoint); if a token was misused, `JWT::revokeAllTokens(userId)`.
**Investigation**: reproduce; confirm whether data was retrieved vs only
attempted; scope the records exposed via audit + DB logs.
**Remediation**: fix at service/controller layer; add a regression test
(see `UNIT/SECURITY/IDOR*` patterns).
**Recovery**: restore legitimacy of affected data if modified.
**Documentation**: finding, evidence, fix commit, test added.

## 4. Data exposure (bulk extraction / report leak)

**Detection**: export rate-limit alerts, unusually large responses, CSV/PDF
downloads outside work patterns.
**Containment**: revoke the session (disable account), or tighten
permissions for the role; consider suspending the export feature temporarily
(the rate limiter `exports` `20:300` is the automated brake).
**Investigation**: `audit_logs` EXPORT events, request ids, IPs.
**Remediation**: strengthen filters/authorization; rotate any disclosed
credentials (e.g. SSN-equivalents: national IDs) per DPA.
**Recovery**: notify affected individuals per policy.
**Documentation**: scope of data, channels, notification record.

## 5. Malicious file upload

**Detection**: upload allowlist denies, security-log events, antivirus/EDR.
**Containment**: quarantine the file (move out of the uploads path); block the
account.
**Investigation**: verify stored name, origin request id, whether the file was
ever served (download audit).
**Remediation**: ensure the uploads tree has no execution + no direct URL
access (already enforced by `backend/public/uploads/.htaccess`); delete the
file and its DB row; add a regression test.
**Recovery**: re-scan adjacent files.
**Documentation**: sample, source, disposition.

## 6. API abuse / brute force

**Detection**: `auth.bruteforce_blocked`, `RATE_LIMITED` (429) spikes,
`auth.login_inactive_account` volume.
**Containment**: IP-block at WAF; the file-backed limiter already throttles
per IP + identifier.
**Investigation**: request ids, user agents, target accounts.
**Remediation**: tighten limits, add indicators, notify the account owners.
**Recovery**: monitor decays.
**Documentation**: pattern, limits, outcome.

## 7. Suspicious administrator activity

**Detection**: `AUDIT` export reviews, permission-override audit events,
`ACTION_STATUS_CHANGE` on admin accounts.
**Containment**: disable the account; revoke tokens; preserve audit rows.
**Investigation**: full audit trail for the actor id (actor identity is
resolved from the session, never the payload — the audit is trustworthy).
**Remediation**: revoke overrides, restore baseline roles.
**Recovery**: strengthen review cadence (quarterly admin attestation).
**Documentation**: findings and follow-ups.

## General process (every incident)

1. **Detect** — log evidence (request id, timestamp, actor, IP).
2. **Contain** — stop the bleeding before investigation.
3. **Rotate** — any credential involved.
4. **Investigate** — scope, vectors, root cause.
5. **Remediate** — fix + regression test.
6. **Recover** — restore, verify.
7. **Document** — lessons learned; update this runbook if the playbook missed
   a vector.