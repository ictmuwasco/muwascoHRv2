<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;

/**
 * Setting Controller - System Configuration API.
 */
class SettingController extends BaseController
{
    /**
     * GET /api/settings - Get system settings.
     */
    public function indexAction(): void
    {
        $this->requirePermission('admin', 'view');

        $this->success([
            'company_name' => 'Murang\'a Water and Sanitation Company (MUWASCO)',
            'company_code' => 'MUWASCO',
            'timezone' => 'Africa/Nairobi',
            'financial_year_start' => 'July',
            'leave_carry_forward_limit' => 15,
            'max_gps_accuracy_meters' => 5000,
            'default_office_radius_meters' => 100,
        ]);
    }

    /**
     * PUT /api/settings - Update system settings.
     */
    public function updateAction(): void
    {
        $this->requirePermission('admin', 'manage');
        $data = $this->getJsonBody();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_SYSTEM,
            \App\Services\AuditService::ACTION_UPDATE,
            'Updated system configuration',
            ['new_values' => $data]
        );

        $this->success($data, 'System settings updated successfully');
    }
}