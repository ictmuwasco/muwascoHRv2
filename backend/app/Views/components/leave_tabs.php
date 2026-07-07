<?php
/**
 * Leave Management Tabs Component
 * 
 * Consistent tab navigation for the Leave module.
 * Renders relevant tabs based on user role.
 */
$userRole = $_SESSION['user_role'] ?? 'guest';
$currentPage = basename($_SERVER['PHP_SELF']);
$allowedRoles = ['hr_manager','dept_head','section_head','sub_section_head','manager','managing_director','super_admin'];

// Determine active tab from current page/route
$tabs = [
    ['label' => 'Apply Leave', 'url' => '/leave/apply', 'active' => str_contains($currentPage, 'apply')],
    ['label' => 'Manage Leave', 'url' => '/leave/manage', 'active' => str_contains($currentPage, 'manage'), 'roles' => $allowedRoles],
    ['label' => 'Leave History', 'url' => '/leave/history', 'active' => str_contains($currentPage, 'history'), 'roles' => ['hr_manager','super_admin','manager','managing_director']],
    ['label' => 'Holidays', 'url' => '/leave/holidays', 'active' => str_contains($currentPage, 'holidays'), 'roles' => ['hr_manager','super_admin','manager','managing_director']],
    ['label' => 'My Leave Profile', 'url' => '/leave/profile', 'active' => str_contains($currentPage, 'profile')],
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