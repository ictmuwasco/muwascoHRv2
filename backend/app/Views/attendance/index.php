<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - HR Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen"
      style="background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);">

    <!-- Sidebar Navigation -->
    <?php require __DIR__ . '/../components/navbar.php'; ?>

    <!-- Top Header Bar -->
    <?php require __DIR__ . '/../components/header_bar.php'; ?>

    <div class="lg:pl-64 pt-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white">Attendance</h1>
                <p class="text-gray-400 mt-1">Clock In / Out & Attendance History</p>
            </div>
        </div>

        <?php if (!$employee_db_id): ?>
            <div class="px-6 py-4 rounded-2xl mb-6 border-2 backdrop-blur-sm
                        bg-gradient-to-r from-warning to-orange-500 text-white border-warning">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Employee record not found. Please contact HR.
            </div>
        <?php else: ?>
            <!-- Status + Clock In/Out -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Clock Card -->
                <div class="lg:col-span-1">
                    <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl shadow-2xl p-6 text-center">
                        <h3 class="text-lg font-semibold text-white mb-4">
                            <i class="fas fa-clock mr-2 text-primary-400"></i>
                            <?= $summary && $summary['is_clocked_in'] ? 'Currently Clocked In' : 'Clock In' ?>
                        </h3>

                        <?php if ($summary && $summary['clocked_in_today']): ?>
                            <div class="mb-4">
                                <p class="text-3xl font-bold <?= $summary['is_clocked_in'] ? 'text-success' : 'text-gray-400' ?>">
                                    <i class="fas <?= $summary['is_clocked_in'] ? 'fa-play-circle' : 'fa-stop-circle' ?>"></i>
                                </p>
                                <p class="text-sm text-gray-400 mt-2">
                                    Clocked in at: <?= date('g:i A', strtotime($summary['today_record']['clock_in'])) ?>
                                </p>
                                <?php if (!$summary['is_clocked_in'] && $summary['today_record']['clock_out']): ?>
                                    <p class="text-sm text-gray-500">
                                        Clocked out at: <?= date('g:i A', strtotime($summary['today_record']['clock_out'])) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Office Selector -->
                        <div class="mb-4">
                            <label class="block text-xs text-gray-500 mb-1 text-left">Select Office</label>
                            <select id="office_id" class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                                <?php foreach ($offices as $office): ?>
                                    <option value="<?= $office['id'] ?>" style="background:#1a1a2e;color:white">
                                        <?= htmlspecialchars($office['name']) ?>
                                        <?= $office['is_assigned'] ? '(Assigned)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                        <button onclick="handleClock()" id="clockBtn"
                                class="w-full px-6 py-3 rounded-xl text-sm font-semibold uppercase tracking-wider
                                       transition-all duration-300
                                       <?= $summary && $summary['is_clocked_in']
                                            ? 'bg-gradient-to-r from-error to-red-600 text-white shadow-[0_6px_20px_rgba(225,112,85,0.4)]'
                                            : 'bg-gradient-to-r from-success to-emerald-600 text-white shadow-[0_6px_20px_rgba(0,184,148,0.4)]' ?>">
                            <i class="fas <?= $summary && $summary['is_clocked_in'] ? 'fa-sign-out-alt' : 'fa-sign-in-alt' ?> mr-2"></i>
                            <?= $summary && $summary['is_clocked_in'] ? 'Clock Out' : 'Clock In' ?>
                        </button>
                        <div id="clockStatus" class="mt-3 text-sm"></div>
                    </div>
                </div>

                <!-- Summary Stats -->
                <div class="lg:col-span-2">
                    <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl shadow-2xl p-6">
                        <h3 class="text-lg font-semibold text-white mb-4">
                            <i class="fas fa-chart-simple mr-2 text-primary-400"></i>This Month
                        </h3>
                        <div class="grid grid-cols-3 gap-6 text-center">
                            <div>
                                <p class="text-3xl font-bold text-white"><?= $summary['month_days'] ?? 0 ?></p>
                                <p class="text-xs text-gray-500 mt-1">Days Present</p>
                            </div>
                            <div>
                                <p class="text-3xl font-bold text-primary-400"><?= $summary['month_hours'] ?? 0 ?>h</p>
                                <p class="text-xs text-gray-500 mt-1">Total Hours</p>
                            </div>
                            <div>
                                <p class="text-3xl font-bold text-warning"><?= $summary['late_days'] ?? 0 ?></p>
                                <p class="text-xs text-gray-500 mt-1">Late Arrivals</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- History -->
            <div class="mt-8">
                <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl shadow-2xl overflow-hidden">
                    <div class="p-6 border-b border-white/10">
                        <h3 class="text-lg font-semibold text-white">
                            <i class="fas fa-history mr-2 text-primary-400"></i>Attendance History
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-white/5">
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Clock In</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Clock Out</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Office</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if (empty($history)): ?>
                                    <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">No attendance records found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($history as $record): ?>
                                        <tr class="hover:bg-white/5 transition-colors">
                                            <td class="px-6 py-4 text-sm text-white">
                                                <?= date('d M Y', strtotime($record['clock_in'])) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-300">
                                                <?= date('g:i A', strtotime($record['clock_in'])) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-300">
                                                <?= $record['clock_out'] ? date('g:i A', strtotime($record['clock_out'])) : '-' ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-300">
                                                <?= htmlspecialchars($record['office_name'] ?? 'N/A') ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full
                                                    <?= match($record['status']) {
                                                        'clocked_in'  => 'bg-primary-400/20 text-primary-400',
                                                        'clocked_out' => 'bg-success/20 text-success',
                                                        'late'        => 'bg-warning/20 text-warning',
                                                        'auto_clocked_out' => 'bg-error/20 text-error',
                                                        default       => 'bg-gray-500/20 text-gray-400',
                                                    } ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $record['status'] ?? 'unknown')) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    </div>

    <script>
    function handleClock() {
        const btn = document.getElementById('clockBtn');
        const status = document.getElementById('clockStatus');
        const officeId = document.getElementById('office_id').value;
        const csrf = document.getElementById('csrf_token').value;
        const isClockedIn = btn.textContent.trim().includes('Clock Out');

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';

        // Get location
        if (!navigator.geolocation) {
            status.className = 'mt-3 text-sm text-error';
            status.textContent = 'Geolocation not supported.';
            btn.disabled = false;
            btn.innerHTML = isClockedIn
                ? '<i class="fas fa-sign-out-alt mr-2"></i> Clock Out'
                : '<i class="fas fa-sign-in-alt mr-2"></i> Clock In';
            return;
        }

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                const data = new FormData();
                data.append('csrf_token', csrf);
                data.append('action', isClockedIn ? 'clock_out' : 'clock_in');
                data.append('office_id', officeId);
                data.append('latitude', pos.coords.latitude);
                data.append('longitude', pos.coords.longitude);
                data.append('accuracy', pos.coords.accuracy);

                fetch('/attendance/clock', {
                    method: 'POST',
                    body: data
                })
                .then(r => r.json())
                .then(resp => {
                    if (resp.success) {
                        status.className = 'mt-3 text-sm text-success';
                        status.textContent = resp.message + (resp.warning ? ' (' + resp.warning + ')' : '');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        status.className = 'mt-3 text-sm text-error';
                        status.textContent = resp.error;
                        btn.disabled = false;
                        btn.innerHTML = isClockedIn
                            ? '<i class="fas fa-sign-out-alt mr-2"></i> Clock Out'
                            : '<i class="fas fa-sign-in-alt mr-2"></i> Clock In';
                    }
                })
                .catch(err => {
                    status.className = 'mt-3 text-sm text-error';
                    status.textContent = 'Network error. Please try again.';
                    btn.disabled = false;
                    btn.innerHTML = isClockedIn
                        ? '<i class="fas fa-sign-out-alt mr-2"></i> Clock Out'
                        : '<i class="fas fa-sign-in-alt mr-2"></i> Clock In';
                });
            },
            function(err) {
                status.className = 'mt-3 text-sm text-error';
                status.textContent = 'Location error: ' + err.message;
                btn.disabled = false;
                btn.innerHTML = isClockedIn
                    ? '<i class="fas fa-sign-out-alt mr-2"></i> Clock Out'
                    : '<i class="fas fa-sign-in-alt mr-2"></i> Clock In';
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }
    </script>
</body>
</html>