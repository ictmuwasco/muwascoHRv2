<?php
/**
 * Completed Appraisals View
 * Place: backend/app/Views/appraisal/completed_appraisals.php
 */
$pageTitle = 'Completed Appraisals - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php include __DIR__ . '/../components/strategic_plan_tabs.php'; ?>

    <div class="mt-6">
        <h1 class="text-3xl font-bold text-white mb-2">
            <i class="fas fa-check-circle text-success-400 mr-2"></i>Completed Appraisals
        </h1>
        <p class="text-gray-400 mb-6">View and export completed performance appraisals</p>

        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 text-center">
            <p class="text-gray-400">Completed Appraisals module - View, filter, and export submitted appraisals.</p>
            <p class="text-gray-500 text-sm mt-2">Export to PDF, Word, or print directly.</p>
        </div>
    </div>
</div>