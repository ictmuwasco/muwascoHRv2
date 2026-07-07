<?php
/**
 * Personal Information Partial
 *
 * Displays employee personal details.
 *
 * Place: backend/app/Views/profile/partials/personal.php
 */
?>
<div class="bg-white border border-gray-200 rounded-2xl shadow p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-6">
        <i class="fas fa-user mr-2 text-primary-400"></i>Personal Information
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">First Name</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['first_name'] ?? 'N/A') ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Last Name</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['last_name'] ?? 'N/A') ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Email</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['email'] ?? 'N/A') ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Phone</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['phone'] ?? 'N/A') ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">National ID</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['national_id'] ?? 'N/A') ?></p>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Date of Birth</label>
            <p class="text-gray-900 dark:text-white"><?= isset($employee['date_of_birth']) ? date('d M Y', strtotime($employee['date_of_birth'])) : 'N/A' ?></p>
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Address</label>
            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($employee['address'] ?? 'N/A') ?></p>
        </div>
    </div>
</div>