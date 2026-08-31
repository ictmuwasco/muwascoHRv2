<?php

declare(strict_types=1);

/**
 * Authorization Allowlist — routes WITHOUT a catalog permission gate (Phase 2).
 *
 * Every route registered in the root api.php MUST either declare a
 * server-defined permission ("module:action" from backend/config/permissions.php)
 * as the sixth argument of $router->add(), OR appear in this list.
 *
 * This list is the ONLY sanctioned place for permission-less routes and every
 * entry needs a justification. backend/tests/Unit/Authorization/RoutePermissionMapTest.php
 * enforces both directions:
 *   1. a route without a permission argument that is not listed here fails the test;
 *   2. an entry here that no longer matches any registered route fails the test.
 *
 * Entries use the same "METHOD /path" form as
 * AuthenticationMiddleware::currentRoute() (hrdemo/api prefixes stripped,
 * {placeholders} verbatim).
 *
 * SECURITY MODEL: these endpoints are still protected by the global
 * authentication gate (AuthenticationMiddleware::process) — they are NOT
 * public unless explicitly marked PUBLIC below. Ownership/organizational
 * scope for self-service endpoints is enforced inside the controller or its
 * service (defense in depth), e.g. ComplaintController returns own complaints
 * to employees and all complaints only to holders of complaints:view.
 *
 * Place: backend/config/authz_allowlist.php
 */
return [

    /**
     * PUBLIC — pre-authentication endpoints (mirrors
     * AuthenticationMiddleware::PUBLIC_ROUTES). Reachable without any session
     * or token; must never expose protected data.
     */
    'public' => [
        'POST /auth/login',            // credential exchange
        'POST /system/client-errors',  // pre-login browser error collector
        'GET /consent/status',         // must answer pre-session gracefully
    ],

    /**
     * AUTHENTICATION-ONLY — session/token management and profile self-service.
     * Identity comes from the authenticated session; the "resource" is always
     * the caller themself.
     */
    'self_service' => [
        'POST /auth/logout',
        'POST /auth/refresh',
        'GET /auth/user',              // current user + effective permissions
        'POST /auth/change-password',  // own password (current password verified)

        'POST /attendance/clock-in',   // own attendance record
        'POST /attendance/clock-out',
        'POST /attendance/auto-clockout',

        'POST /consent/verify-employee', // onboarding self-service flow
        'POST /consent',

        'GET /notifications',            // own notifications
        'POST /notifications/{id}/read', // own notification (ownership in controller)
        'POST /notifications/read-all',

        'GET /notification-preferences', // own push/notification preferences
        'PUT /notification-preferences',

        'GET /push/vapid-public-key',    // public key material + own subscriptions
        'GET /push/subscriptions',
        'POST /push/subscribe',
        'DELETE /push/subscribe',

        'GET /profile/documents/{id}',      // own profile documents
        'GET /profile/documents/{id}/view', // (ownership enforced in controller)
    ],

    /**
     * REFERENCE DATA — read-only lookups every authenticated user needs for
     * filters and forms (holiday calendars, org-unit pickers). Writes to the
     * same resources ARE permission-gated (holidays:*, departments:*).
     */
    'reference_data' => [
        'GET /holidays',
        'GET /holidays/upcoming',
        'GET /holidays/{id}',
        'GET /departments',
        'GET /sections',
        'GET /subsections',
        'GET /appraisal-cycles',
    ],

    /**
     * SELF-SCOPE MIXED — endpoints whose response differs by permission but
     * which every authenticated user must be able to call for their OWN data.
     * The controller branches on the permission internally (e.g. employees
     * see their own complaints; complaints:view holders see the triage list).
     * Gating the route itself would break the self-service half.
     */
    'mixed_scope' => [
        'GET /complaints',
        'POST /complaints',
        'GET /leave/{id}/documents',            // own leave application documents
        'GET /leave/{id}/documents/{documentId}',
    ],
];
