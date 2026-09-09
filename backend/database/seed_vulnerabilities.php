<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Services\Security\VulnerabilityService;

/**
 * Seeds the `vulnerabilities` table with the initial vulnerability register.
 *
 * The SOC "Vulnerabilities" tab reads from this table via
 * SecurityDashboardController::vulnerabilitiesAction() →
 * VulnerabilityService::getAll(). Until migrations 043/044 were applied the
 * table did not exist and the tab rendered hardcoded placeholder rows.
 *
 * Rows are written through VulnerabilityService::create() (the audited path)
 * so the timeline + audit log are kept consistent. The seeder is idempotent:
 * it no-ops when the table already contains vulnerability records.
 *
 * Usage: php backend/database/seed_vulnerabilities.php
 */

$service = VulnerabilityService::getInstance();

try {
    $existing = (int) (\db()->fetchValue('SELECT COUNT(*) FROM vulnerabilities') ?? 0);
} catch (\Throwable $e) {
    $existing = -1;
}

if ($existing === -1) {
    fwrite(STDERR, "ERROR: `vulnerabilities` table is missing. Run php backend/database/run_migration_044.php first.\n");
    exit(1);
}

if ($existing > 0) {
    echo "vulnerabilities table already has {$existing} record(s) — skipping seed (idempotent).\n";
    exit(0);
}

$now = date('Y-m-d H:i:s');

$vulnerabilities = [
    [
        'title'                  => 'IDOR — unauthorized access to employee records via GET /employees/{id}',
        'description'            => 'Employee records are addressed by sequential database IDs with no unguessable identifier. Object-level authorization was not enforced on every employee sub-resource (profile images, document view), allowing a holder of employees:view to horizontally reach records outside their scope by altering the {id} path parameter.',
        'category'               => 'access_control',
        'type'                   => 'idor',
        'cwe_id'                 => '639',
        'owasp_category'         => 'A01:2021',
        'severity'               => VulnerabilityService::SEVERITY_HIGH,
        'risk_score'             => 70,
        'cvss_score'             => 6.5,
        'affected_resource_type' => 'employee',
        'affected_endpoint'      => '/api/employees/{id}',
        'affected_route'         => '/employees/{id}',
        'affected_method'        => 'GET',
        'ai_classification'      => 'LIKELY_ATTACK',
        'ai_confidence'          => 0.82,
        'ai_analysis'            => 'Sequential resource identifiers combined with per-endpoint (not per-sub-resource) object checks enable horizontal privilege escalation across employee records.',
        'remediation'            => 'Enforce EmployeePolicy::canView/canEdit on EVERY parameterized employee endpoint (profile, documents, profile-image). In depth: move to unguessable resource UUIDs and centralize the object-authorization check in one service the controllers must call.',
        'created_by'             => 0,
    ],
    [
        'title'                  => 'Weak brute-force protection on login (no account lockout, no MFA)',
        'description'            => 'POST /auth/login allows 5 attempts per 15 minutes per IP + account (file-backed). There is no progressive account lockout and multi-factor authentication is disabled (FEATURE_MFA=false), so slow credential stuffing and distributed brute force remain possible.',
        'category'               => 'authentication',
        'type'                   => 'credential_stuffing',
        'cwe_id'                 => '307',
        'owasp_category'         => 'A07:2021',
        'severity'               => VulnerabilityService::SEVERITY_MEDIUM,
        'risk_score'             => 45,
        'cvss_score'             => 5.3,
        'affected_resource_type' => 'user',
        'affected_endpoint'      => '/api/auth/login',
        'affected_route'         => '/auth/login',
        'affected_method'        => 'POST',
        'ai_classification'      => 'SUSPICIOUS',
        'ai_confidence'          => 0.70,
        'ai_analysis'            => 'Per-IP/per-account throttling is present but an attacker rotating IPs and spreading attempts over time can stay under the 5/15min radar indefinitely.',
        'remediation'            => 'Add exponential lockout after repeated failures on an account, enable MFA (TOTP), and enforce IP reputation checks (WAF) on the login endpoint.',
        'created_by'             => 0,
    ],
    [
        'title'                  => 'Missing multi-factor authentication (MFA) for privileged accounts',
        'description'            => 'The application signs in with email + password only. Privileged accounts (super_admin, hr_manager) do not require a second factor, so a phished or leaked password grants full security-dashboard and HR data access.',
        'category'               => 'authentication',
        'type'                   => 'missing_mfa',
        'cwe_id'                 => '287',
        'owasp_category'         => 'A07:2021',
        'severity'               => VulnerabilityService::SEVERITY_MEDIUM,
        'risk_score'             => 50,
        'cvss_score'             => 5.9,
        'affected_resource_type' => 'user',
        'affected_endpoint'      => '/api/auth/login',
        'affected_route'         => '/auth/login',
        'affected_method'        => 'POST',
        'ai_classification'      => 'NORMAL',
        'ai_confidence'          => 0.50,
        'ai_analysis'            => 'Feature flag FEATURE_MFA=false; no TOTP/WebAuthn enrollment flow exists.',
        'remediation'            => 'Enable FEATURE_MFA and require TOTP for privileged roles; store TOTP secrets encrypted, revoke on password change.',
        'created_by'             => 0,
    ],
    [
        'title'                  => 'Insecure CORS origin allowlist shipped for development',
        'description'            => 'Allowed origins are hardcoded to localhost:5173/3000/localhost (backend/config/cors.php). In production this either leaks data to unintended dev origins or must be overridden by hand; there is no environment-driven, per-origin allowlist.',
        'category'               => 'security_configuration',
        'type'                   => 'cors_misconfiguration',
        'cwe_id'                 => '942',
        'owasp_category'         => 'A05:2021',
        'severity'               => VulnerabilityService::SEVERITY_LOW,
        'risk_score'             => 20,
        'cvss_score'             => 3.7,
        'affected_resource_type' => 'api',
        'affected_endpoint'      => '/api/*',
        'affected_route'         => '*',
        'affected_method'        => 'ANY',
        'ai_classification'      => 'NORMAL',
        'ai_confidence'          => 0.60,
        'ai_analysis'            => 'Development-oriented allowlist; should be environment-controlled and reviewed before any public deployment.',
        'remediation'            => 'Drive allowed origins from CORS_ALLOWED_ORIGINS env var per environment and never from a hardcoded localhost list.',
        'created_by'             => 0,
    ],
    [
        'title'                  => 'Sensitive data exposure — PII in security/audit logs',
        'description'            => 'Historical security logging captured full email addresses and password-verification outcomes (AuthService, AuthorizationService). Current logSecurityEvent redacts passwords/emails/tokens, but old log stores may still contain PII and sensitive markers.',
        'category'               => 'data_protection',
        'type'                   => 'sensitive_data_logging',
        'cwe_id'                 => '532',
        'owasp_category'         => 'A02:2021',
        'severity'               => VulnerabilityService::SEVERITY_MEDIUM,
        'risk_score'             => 35,
        'cvss_score'             => 5.3,
        'affected_resource_type' => 'api',
        'affected_endpoint'      => '/api/auth/*',
        'affected_route'         => '/auth/login',
        'affected_method'        => 'ANY',
        'ai_classification'      => 'SUSPICIOUS',
        'ai_confidence'          => 0.62,
        'ai_analysis'            => 'Redaction is applied at write time now; residual risk is the historical retention of non-redacted rows.',
        'remediation'            => 'Purge or re-encrypt historical logs, enforce transmission encryption and access control on log stores, and keep the SecurityEventService PII-safe schema as the only event sink.',
        'created_by'             => 0,
    ],
    [
        'title'                  => 'Security telemetry pipeline was non-functional (events not persisted)',
        'description'            => 'The security_events / security_incidents / vulnerabilities tables were missing from the database, so rate-limit violations, IDOR denials and unauthorized-access events were silently dropped (record() catches and swallows). Fixed by applying migrations 043 + 044; a regression guard should keep the telemetry write path observable.',
        'category'               => 'security_configuration',
        'type'                   => 'monitoring',
        'cwe_id'                 => '693',
        'owasp_category'         => 'A05:2021',
        'severity'               => VulnerabilityService::SEVERITY_HIGH,
        'risk_score'             => 60,
        'cvss_score'             => 5.9,
        'affected_resource_type' => 'security',
        'affected_endpoint'      => '/api/security/*',
        'affected_route'         => '/security/*',
        'affected_method'        => 'ANY',
        'ai_classification'      => 'NORMAL',
        'ai_confidence'          => 0.75,
        'ai_analysis'            => 'The SOC dashboard displayed "Unable to calculate" and zero events because SecurityRiskEngine::calculatePosture() caught the missing-table exception. Migrations 043/044 + runner verification resolve this.',
        'remediation'            => 'Run php backend/database/run_migration_043.php and run_migration_044.php; keep check_security_tables.php in CI; make SecurityEventService::record() surface failures at ERROR level instead of silently swallowing them.',
        'created_by'             => 0,
    ],
    [
        'title'                  => 'Path traversal hardening on document streaming',
        'description'            => 'Document download resolves the file path directly from the stored file_name with no explicit traversal sanitization. Although file_name comes from the upload path (server-generated), a malicious DB write would allow readfile() to serve arbitrary files inside the storage root.',
        'category'               => 'input_validation',
        'type'                   => 'path_traversal',
        'cwe_id'                 => '22',
        'owasp_category'         => 'A03:2021',
        'severity'               => VulnerabilityService::SEVERITY_LOW,
        'risk_score'             => 15,
        'cvss_score'             => 3.1,
        'affected_resource_type' => 'document',
        'affected_endpoint'      => '/api/profile/documents/{id}/view',
        'affected_route'         => '/profile/documents/{id}/view',
        'affected_method'        => 'GET',
        'ai_classification'      => 'NORMAL',
        'ai_confidence'          => 0.55,
        'ai_analysis'            => 'The path is built inside the uploads root, so the practical risk is low; defense-in-depth recommends an explicit containment check.',
        'remediation'            => 'Reject file_name containing .. or path separators; open via realpath() and verify the resolved path is inside the storage root before readfile().',
        'created_by'             => 0,
    ],
    [
        'title'                  => 'Session fixation and idle-timeout coverage gaps',
        'description'            => 'Session regeneration and idle/absolute timeout are implemented, but the session cookie lifetime and per-role timeout policy are not configurable at runtime, and no periodic expiration sweep runs for abandoned sessions (fed by repeated JWT refresh).',
        'category'               => 'session_management',
        'type'                   => 'session_management',
        'cwe_id'                 => '384',
        'owasp_category'         => 'A07:2021',
        'severity'               => VulnerabilityService::SEVERITY_LOW,
        'risk_score'             => 15,
        'cvss_score'             => 3.7,
        'affected_resource_type' => 'user',
        'affected_endpoint'      => '/api/auth/*',
        'affected_route'         => '/auth/*',
        'affected_method'        => 'ANY',
        'ai_classification'      => 'NORMAL',
        'ai_confidence'          => 0.50,
        'ai_analysis'            => 'SecurityMiddleware enforces idle/absolute timeout per request; residual risk is configuration maintainability and backwards rotation across devices.',
        'remediation'            => 'Centralize session-timeout constants in config, regenerate the PHPSESSID at every privilege change, and add a background job to revoke stale db_sessions rows.',
        'created_by'             => 0,
    ],
];

echo 'Seeding vulnerabilities...' . PHP_EOL;
$inserted = 0;
foreach ($vulnerabilities as $data) {
    $id = $service->create($data);
    if ($id !== null) {
        $inserted++;
        echo "  + created #{$id}: {$data['title']}\n";
    } else {
        fwrite(STDERR, "  ! FAILED to create: {$data['title']}\n");
    }
}

echo "Inserted {$inserted}/" . count($vulnerabilities) . " vulnerabilities.\n";

if ($inserted > 0) {
    $rows = \db()->fetchAll('SELECT id, title, severity, status, category, cwe_id, affected_endpoint FROM vulnerabilities ORDER BY id');
    echo PHP_EOL . "Verify — vulnerabilities table now has " . count($rows) . " rows:\n";
    foreach ($rows as $r) {
        printf("  [%d] %-8s %-12s %-16s CWE-%s %s\n", $r['id'], $r['severity'], $r['status'], $r['category'], $r['cwe_id'] ?? '-', $r['title']);
    }
}
exit($inserted > 0 ? 0 : 1);