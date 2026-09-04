<?php

declare(strict_types=1);

/**
 * Rate-limit governance list (Phase 7, finding P7-4).
 *
 * Every route registered in the root api.php that appears below MUST declare
 * a server-defined throttle ("max:windowSeconds") as the sixth argument of
 * $router->add(). backend/tests/Unit/Authorization/RoutePermissionMapTest.php
 * enforces both directions of the contract:
 *
 *   1. a route listed here without throttle metadata fails the test;
 *   2. throttle metadata is validated as "positive-int:positive-int".
 *
 * Limits are calibrated generously so legitimate HR operations (bulk
 * approvals, monthly exports, seasonal employee onboarding) are never
 * blocked, while scripted scraping/extraction and credential-style abuse
 * are throttled per authenticated user + client IP
 * (SecurityMiddleware::protectAgainstBruteForce, file-backed flock counters).
 *
 * Exempt (throttled elsewhere, documented):
 *   - POST /auth/login            — protectAgainstBruteForce in the controller
 *                                   (5 / 15 min, per IP + account identifier)
 *   - POST /auth/change-password  — protectAgainstBruteForce in the controller
 *                                   (5 / 15 min, per account)
 *   - POST /admin/notifications/test-send — 5/hour in NotificationTestController
 *   - POST|DELETE /push/subscribe — throttled in PushSubscriptionController
 *
 * There is deliberately NO self-service "forgot password" endpoint in this
 * application (password resets are admin-driven via POST /users/{id}/change-
 * password, which is throttled below at 10/15 min).
 */
return [

    /**
     * Bulk data extraction. Highest-value target for scripted abuse: a
     * compromised or malicious session could otherwise walk every filter
     * combination and siphon the HR dataset.
     */
    'exports' => [
        'GET /audit/export',
        'GET /reports/attendance/export',
        'GET /reports/leave/export',
        'GET /reports/{type}/export/{format}',
        'GET /leave/roster/export',
        'GET /leave/profile/{id}/export',
        'GET /workplans/export',
    ],

    /**
     * Identity & employee record writes. Throttling blunts automated
     * tampering and limits blast radius of a stolen HR/admin session.
     */
    'identity_writes' => [
        'POST /users',
        'PUT /users/{id}',
        'DELETE /users/{id}',
        'PUT /users/{id}/toggle-status',
        'POST /users/{id}/change-password', // admin-driven reset; 10/15 min
        'POST /employees',
        'PUT /employees/{id}',
    ],

    /**
     * Privilege changes — role/permission overrides.
     */
    'privilege_changes' => [
        'POST /permissions/users/{id}/overrides',
        'DELETE /permissions/users/{id}/overrides',
    ],

    /**
     * File uploads — each attempt costs storage + validation work; upload
     * endpoints are a classic DoS / abuse surface.
     */
    'uploads' => [
        'POST /profile/documents',
        'POST /profile/profile-image',
        'POST /employees/{id}/profile-image',
    ],

    /**
     * Approval / rejection workflow decisions. High enough for bulk approval
     * sessions, low enough to stop a compromised account churning the whole
     * leave ledger in seconds.
     */
    'workflow_decisions' => [
        'PUT /leave/{id}/approve',
        'PUT /leave/{id}/reject',
        'PUT /leave/{id}/invalidate',
        'PUT /leave/{id}/cancel',
    ],

    /**
     * Attendance clock — one person clocks in/out a handful of times a day;
     * 10 / 5 min is far above legitimate use (closes P5-18 debt).
     */
    'clock' => [
        'POST /attendance/clock-in',
        'POST /attendance/clock-out',
        'POST /attendance/auto-clockout',
    ],

    /**
     * Financial-year leave allocation — bulk write affecting balances
     * company-wide.
     */
    'allocation' => [
        'POST /admin/financial-year/allocate',
    ],
];
