<?php
/**
 * Performance Appraisal - Regular Appraisals View
 * Place: backend/app/Views/appraisal/performance_appraisal.php
 */
$pageTitle = 'Performance Appraisal - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php include __DIR__ . '/../components/strategic_plan_tabs.php'; ?>
    <?php include __DIR__ . '/../components/performance_appraisal_tabs.php'; ?>

    <div class="mt-6">
        <h1 class="text-3xl font-bold text-white mb-2">
            <i class="fas fa-clipboard-check text-primary-400 mr-2"></i>Performance Appraisal
        </h1>
        <p class="text-gray-400 mb-6">Manage employee performance appraisals</p>

        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 text-center">
            <p class="text-gray-400">Regular Appraisals module - Select an employee and cycle to begin.</p>
            <p class="text-gray-500 text-sm mt-2">Use the subtabs above to navigate between Regular, Escalated, and Pending appraisals.</p>
        </div>
    </div>
</div>