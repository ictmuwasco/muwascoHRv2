<?php

declare(strict_types=1);

namespace App\Controllers\Security;

use App\Controllers\BaseController;
use App\Services\Security\SecurityIncidentService;
use App\Services\Security\SecurityEventService;

/**
 * SecurityAdminController — administrator actions for security management.
 *
 * All actions require security:manage permission and are audited.
 */
class SecurityAdminController extends BaseController
{
    /**
     * POST /api/security/incidents/{id}/resolve
     * Resolve an incident.
     */
    public function resolveIncidentAction(int $id): void
    {
        $this->requirePermission('security', 'manage');

        try {
            $data = $this->getJsonBody();
            $notes = $data['notes'] ?? '';
            $userId = $this->getAuthUserId();

            $success = SecurityIncidentService::getInstance()->updateStatus(
                $id, SecurityIncidentService::STATUS_RESOLVED, $userId, $notes
            );

            if (!$success) $this->error('Failed to resolve incident', 500);
            $this->success(null, 'Incident resolved');
        } catch (\Exception $e) {
            $this->error('Failed to resolve incident', 500);
        }
    }

    /**
     * POST /api/security/incidents/{id}/false-positive
     * Mark incident as false positive.
     */
    public function falsePositiveAction(int $id): void
    {
        $this->requirePermission('security', 'manage');

        try {
            $data = $this->getJsonBody();
            $notes = $data['notes'] ?? '';
            $userId = $this->getAuthUserId();

            $success = SecurityIncidentService::getInstance()->updateStatus(
                $id, SecurityIncidentService::STATUS_FALSE_POSITIVE, $userId, $notes
            );

            if (!$success) $this->error('Failed to update incident', 500);
            $this->success(null, 'Incident marked as false positive');
        } catch (\Exception $e) {
            $this->error('Failed to update incident', 500);
        }
    }

    /**
     * POST /api/security/incidents/{id}/investigate
     * Set incident to investigating status.
     */
    public function investigateAction(int $id): void
    {
        $this->requirePermission('security', 'investigate');

        try {
            $success = SecurityIncidentService::getInstance()->updateStatus(
                $id, SecurityIncidentService::STATUS_INVESTIGATING, $this->getAuthUserId()
            );

            if (!$success) $this->error('Failed to update incident', 500);
            $this->success(null, 'Incident status updated to investigating');
        } catch (\Exception $e) {
            $this->error('Failed to update incident', 500);
        }
    }

    /**
     * POST /api/security/incidents/{id}/contain
     * Contain an incident.
     */
    public function containAction(int $id): void
    {
        $this->requirePermission('security', 'manage');

        try {
            $success = SecurityIncidentService::getInstance()->updateStatus(
                $id, SecurityIncidentService::STATUS_CONTAINED, $this->getAuthUserId()
            );

            if (!$success) $this->error('Failed to contain incident', 500);
            $this->success(null, 'Incident contained');
        } catch (\Exception $e) {
            $this->error('Failed to contain incident', 500);
        }
    }
}
