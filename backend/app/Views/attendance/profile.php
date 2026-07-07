<?php
/**
 * Attendance Profile View
 * Place: backend/app/Views/attendance/profile.php
 */
$pageTitle = 'Attendance Profile - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php include __DIR__ . '/../components/attendance_tabs.php'; ?>

    <div class="mt-6">
        <h1 class="text-3xl font-bold text-white mb-2">
            <i class="fas fa-id-card text-primary-400 mr-2"></i>Attendance Profile
        </h1>
        <p class="text-gray-400 mb-6">Employee attendance history and analytics</p>

        <!-- Employee Search -->
        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 mb-6">
            <h3 class="text-lg font-semibold text-white mb-4">Select Employee</h3>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Employee</label>
                    <select name="employee_id" class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                        <option value="">Select Employee</option>
                        <?php foreach ($allEmployees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" style="background:#1a1a2e">
                                <?= htmlspecialchars($emp['employee_id'] . ' - ' . $emp['first_name'] . ' ' . $emp['last_name'] . ' (' . ($emp['office_name'] ?? 'N/A') . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Date From</label>
                    <input type="date" name="date_from" value="<?= date('Y-m-01') ?>" class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Date To</label>
                    <input type="date" name="date_to" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                </div>
                <div class="md:col-span-3">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search mr-1"></i> View Attendance</button>
                </div>
            </form>
        </div>

        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 text-center">
            <p class="text-gray-400">Select an employee above to view their attendance profile.</p>
        </div>
    </div>
</div>