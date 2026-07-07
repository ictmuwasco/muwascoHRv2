<?php
/**
 * Employment Details Partial
 *
 * Displays employee employment information.
 *
 * Place: backend/app/Views/profile/partials/employment.php
 */
?>
<div class="bg-white/50 dark:bg-white/10 border border-gray-200 dark:border-white/20 rounded-2xl shadow-lg dark:shadow-2xl p-6 backdrop-blur-xl">
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">
        <i class="fas fa-briefcase mr-2 text-primary-400"></i>Employment Details
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Employee ID</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['employee_id'] ?? 'N/A') ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Employee Type</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $employee['employee_type'] ?? 'N/A'))) ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Department</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['department_name'] ?? 'N/A') ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Section</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['section_name'] ?? 'N/A') ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Office</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['office_name'] ?? 'N/A') ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Employment Status</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars(ucfirst($employee['employee_status'] ?? 'N/A')) ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Hire Date</label>
            <p class="text-gray-900 dark:text-white"><?= isset($employee['hire_date']) ? date('d M Y', strtotime($employee['hire_date'])) : 'N/A' ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Job Group</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['job_group'] ?? 'N/A') ?></p>
        </div>
    </div>
</div>