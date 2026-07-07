<?php
/**
 * Performance Appraisal - Pending Approval View
 * Place: backend/app/Views/appraisal/performance_appraisal_pending.php
 */
$pageTitle = 'Pending Approval - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php include __DIR__ . '/../components/strategic_plan_tabs.php'; ?>
    <?php include __DIR__ . '/../components/performance_appraisal_tabs.php'; ?>

    <div class="mt-6">
        <h1 class="text-3xl font-bold text-white mb-2">
            <i class="fas fa-clock text-warning-400 mr-2"></i>Pending Approval
        </h1>
        <p class="text-gray-400 mb-6">Review and approve pending performance appraisals</p>

        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 text-center">
            <p class="text-gray-400">Pending Approval module - Review employee appraisals awaiting your approval.</p>
            <p class="text-gray-500 text-sm mt-2">Approve or reject appraisals with comments.</p>
        </div>
    </div>
</div>