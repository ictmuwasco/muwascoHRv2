<?php
/**
 * Attendance Tabs Component
 * 
 * Consistent tab navigation for the Attendance module.
 * Renders relevant tabs based on user role.
 */
$userRole = $_SESSION['user_role'] ?? 'guest';
$currentPage = basename($_SERVER['PHP_SELF']);
$hasDashboardAccess = in_array($userRole, ['hr_manager','super_admin','dept_head']);

// Determine active tab from current page/route
$tabs = [
    ['label' => 'My Attendance', 'url' => '/attendance', 'active' => str_contains($currentPage, 'attendance') && !str_contains($currentPage, 'dashboard') && !str_contains($currentPage, 'profile')],
    ['label' => 'Dashboard', 'url' => '/attendance/dashboard', 'active' => str_contains($currentPage, 'dashboard'), 'roles' => ['hr_manager','super_admin','dept_head']],
    ['label' => 'Employee Profile', 'url' => '/attendance/profile', 'active' => str_contains($currentPage, 'profile'), 'roles' => ['hr_manager','super_admin','dept_head']],
];
?>
<div class="leave-tabs">
    <?php foreach ($tabs as $tab): ?>
        <?php if (!isset($tab['roles']) || in_array($userRole, $tab['roles'])): ?>
            <a href="<?= htmlspecialchars($tab['url']) ?>" 
               class="leave-tab <?= ($tab['active'] ?? false) ? 'active' : '' ?>">
                <?= htmlspecialchars($tab['label']) ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>