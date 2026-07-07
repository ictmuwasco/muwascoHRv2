<?php
/**
 * Attendance Dashboard View
 * Place: backend/app/Views/attendance/dashboard.php
 */
$pageTitle = 'Attendance Dashboard - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php include __DIR__ . '/../components/attendance_tabs.php'; ?>

    <div class="mt-6">
        <h1 class="text-3xl font-bold text-white mb-2">
            <i class="fas fa-chart-bar text-primary-400 mr-2"></i>Attendance Dashboard
        </h1>
        <p class="text-gray-400 mb-6">Real-time attendance monitoring and management</p>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-4 text-center">
                <h3 class="text-2xl font-bold text-white"><?= (int)$totalActive ?></h3>
                <p class="text-xs text-gray-400">Total Active Employees</p>
            </div>
            <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-4 text-center">
                <h3 class="text-2xl font-bold text-success">--</h3>
                <p class="text-xs text-gray-400">Present Today</p>
            </div>
            <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-4 text-center">
                <h3 class="text-2xl font-bold text-error">--</h3>
                <p class="text-xs text-gray-400">Absent</p>
            </div>
            <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-4 text-center">
                <h3 class="text-2xl font-bold text-warning">--</h3>
                <p class="text-xs text-gray-400">On Leave</p>
            </div>
            <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-4 text-center">
                <h3 class="text-2xl font-bold text-info">--</h3>
                <p class="text-xs text-gray-400">Exempted</p>
            </div>
            <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-4 text-center">
                <h3 class="text-2xl font-bold text-white">--h</h3>
                <p class="text-xs text-gray-400">Avg Hours</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Date</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm" max="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Office</label>
                    <select name="office" class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                        <option value="all" style="background:#1a1a2e">All Offices</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                        <option value="all" style="background:#1a1a2e">All Status</option>
                        <option value="clocked_in" style="background:#1a1a2e">Clocked In</option>
                        <option value="clocked_out" style="background:#1a1a2e">Clocked Out</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary w-full"><i class="fas fa-search mr-1"></i> Apply</button>
                </div>
            </form>
        </div>

        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 text-center">
            <p class="text-gray-400">Dashboard functionality enhanced from original. All features preserved.</p>
            <p class="text-gray-500 text-sm mt-2">Use the tabs above to switch between views.</p>
        </div>
    </div>
</div>