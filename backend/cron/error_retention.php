<?php

declare(strict_types=1);

/**
 * Cron: nightly retention sweep for the error-tracking / observability layer.
 *
 * Schedule (cPanel cron or crontab), e.g. 02:30 daily:
 *   30 2 * * * php /home/user/public_html/hrdemo/backend/cron/error_retention.php
 *
 * Periods are configured in backend/config/config/observability.php:
 *   retention.occurrence_days          raw server occurrences (default 90)
 *   retention.client_days              browser-reported occurrences (default 30)
 *   retention.performance_days         slow-request events (default 30)
 *   retention.resolved_group_months    aged-out resolved groups (default 12)
 */

require_once dirname(__DIR__, 2) . '/backend/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

\App\Services\ErrorTracking\ErrorTrackerService::runRetention();
