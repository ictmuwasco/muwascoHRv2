<?php
/**
 * Strategic Plan Index View
 * Place: backend/app/Views/strategic_plan/index.php
 */
$pageTitle = 'Strategic Plan - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php include __DIR__ . '/../components/strategic_plan_tabs.php'; ?>

    <div class="mt-6">
        <h1 class="text-3xl font-bold text-white mb-2">
            <i class="fas fa-chess-board text-primary-400 mr-2"></i>Strategic Plan
        </h1>
        <p class="text-gray-400 mb-6">Organizational strategic objectives and initiatives</p>

        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 text-center">
            <p class="text-gray-400">Strategic Plan module - Main dashboard coming soon.</p>
            <p class="text-gray-500 text-sm mt-2">Use the tabs above to navigate to Work Plans, KPIs, and Reports.</p>
        </div>
    </div>
</div>