<?php
/**
 * Strategic Plan Tabs Component
 * 
 * Main tab navigation for Strategic Planning module.
 */
$userRole = $_SESSION['user_role'] ?? 'guest';
$currentPage = basename($_SERVER['PHP_SELF']);
$allowedRoles = ['hr_manager','super_admin','manager','dept_head','section_head','sub_section_head'];

// Determine active tab
$activeTab = 'strategic_plan';
if (str_contains($currentPage, 'workplans')) $activeTab = 'workplans';
if (str_contains($currentPage, 'kpi')) $activeTab = 'kpi';
if (str_contains($currentPage, 'workplan_reports')) $activeTab = 'reports';

$tabs = [
    ['id' => 'strategic_plan', 'label' => 'Strategic Plan', 'url' => '/strategic-plan', 'active' => $activeTab === 'strategic_plan'],
    ['id' => 'workplans', 'label' => 'Work Plans', 'url' => '/strategic-plan/workplans', 'active' => $activeTab === 'workplans'],
    ['id' => 'kpi', 'label' => 'KPI Management', 'url' => '/strategic-plan/kpi', 'active' => $activeTab === 'kpi'],
    ['id' => 'reports', 'label' => 'Workplan Reports', 'url' => '/strategic-plan/reports', 'active' => $activeTab === 'reports'],
];
?>
<div class="leave-tabs">
    <?php foreach ($tabs as $tab): ?>
        <a href="<?= htmlspecialchars($tab['url']) ?>" 
           class="leave-tab <?= $tab['active'] ? 'active' : '' ?>">
            <?= htmlspecialchars($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</div>