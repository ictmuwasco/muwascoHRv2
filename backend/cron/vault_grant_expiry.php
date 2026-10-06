<?php

declare(strict_types=1);

/**
 * vault_grant_expiry.php - cron job that retires lapsed vault grants.
 *
 * WHAT THIS DOES, AND WHAT IT DELIBERATELY DOES NOT DO
 *   It flips grants whose expires_at has passed from 'active' to 'expired' and
 *   writes an audit row for each, so the employee's grant list is honest and
 *   the security dashboard can show when access ended.
 *
 *   It is NOT the security boundary. VaultService::activeGrantFor() already
 *   refuses any grant that is past its expiry, so a vault stops being readable
 *   at the right moment whether or not this job ever runs. That redundancy is
 *   deliberate: if the scheduler is broken, the display degrades, the CONTROL
 *   does not. A cron-only expiry check would turn a broken scheduler into an
 *   indefinite access grant, which is the failure mode worth designing against.
 *
 * INSTALL
 *   Hourly is enough:
 *
 *     17 * * * *  php /var/www/hrdemo/backend/cron/vault_grant_expiry.php
 *
 *   Running it more often only produces more audit rows, because access is
 *   already refused at the expiry instant by the read query.
 *
 * EXIT CODES
 *   0 = ran successfully (including "nothing to expire")
 *   1 = failed; the error is logged, not printed with secrets
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Services\Vault\VaultService;

if (PHP_SAPI !== 'cli') {
    // Never reachable through the web entry points (cron/ is outside the
    // document root), but fail closed if it ever is.
    http_response_code(404);
    exit(1);
}

$start = microtime(true);

try {
    $vault = VaultService::getInstance();

    if (!$vault->isEnabled()) {
        echo "Private vault is disabled; nothing to do.\n";
        exit(0);
    }

    $expired = $vault->expireLapsedGrants();

    $elapsed = round((microtime(true) - $start) * 1000);
    echo sprintf(
        "vault_grant_expiry: %d grant(s) expired in %dms\n",
        $expired,
        $elapsed
    );

    exit(0);

} catch (\Throwable $e) {
    // The message is logged rather than echoed with context, and never
    // contains key material or ciphertext - VaultService::expireLapsedGrants()
    // only touches ids and timestamps.
    error_log('[vault_grant_expiry] failed: ' . $e->getMessage());
    fwrite(STDERR, "vault_grant_expiry failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
