<?php
/**
 * Admin View
 *
 * Displays financial year management and leave allocation interface.
 * Place: backend/app/Views/admin/index.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/auth.php';
}

$pageTitle = 'Admin Panel - HR Management System';
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= BASE_URL ?>/frontend/assets/js/theme.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">

<?php include __DIR__ . '/../components/header_bar.php'; ?>
<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="lg:pl-64 pt-16 bg-gray-50 min-h-screen">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full">
        
        <!-- Page Header -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Admin Panel</h1>
            <p class="text-gray-500 text-sm mt-1">Financial year management & leave allocation</p>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="mb-4 px-4 py-3 rounded-lg bg-green-100 border border-green-300 text-green-800 text-sm">
                <i class="fas fa-check-circle mr-1"></i><?= htmlspecialchars($_SESSION['flash_message']) ?>
            </div>
            <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="mb-4 px-4 py-3 rounded-lg bg-red-100 border border-red-300 text-red-800 text-sm">
                <i class="fas fa-exclamation-circle mr-1"></i><?= htmlspecialchars($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <!-- Admin Tabs -->
        <?php $activeTab = 'financial-year'; include __DIR__ . '/../components/admin_tabs.php'; ?>

        <?php
        $cur_month = (int)date('n');
        $cur_day = (int)date('j');
        $cur_year = (int)date('Y');
        $next_fy_display = [
            'start_date' => "{$cur_year}-07-01",
            'end_date'  => ($cur_year + 1) . "-06-30",
            'year_name' => (string)$cur_year . '/' . substr((string)($cur_year + 1), 2),
        ];
        $existing_fy = !empty($fy_status['next_fy']) ? ['id' => 1] : null;
        ?>

        <!-- Financial Year Status -->
        <div class="bg-white rounded-lg border border-gray-200 p-5 mb-5">
            <h4 class="font-semibold text-gray-900 mb-3">
                <i class="fas fa-calendar-alt mr-1 text-blue-600"></i>Financial Year Status
            </h4>
            <div class="px-4 py-3 rounded-lg border text-sm <?= $existing_fy ? 'bg-green-50 border-green-300 text-green-800' : ($cur_month === 7 && $cur_day === 1 ? 'bg-red-50 border-red-300 text-red-800' : ($cur_month === 7 ? 'bg-yellow-50 border-yellow-300 text-yellow-800' : ($cur_month === 6 ? 'bg-blue-50 border-blue-300 text-blue-800' : 'bg-green-50 border-green-300 text-green-800'))) ?>">
                <div class="flex items-center justify-between">
                    <div><strong>Current Status:</strong>
                        <?php
                        if ($existing_fy)
                            echo "✓ Financial Year {$next_fy_display['year_name']} is already created.";
                        elseif ($cur_month === 7 && $cur_day === 1)
                            echo "🚨 URGENT: It's July 1st! Create financial year {$next_fy_display['year_name']} immediately!";
                        elseif ($cur_month === 7)
                            echo "⚠️ It's " . date('F') . ". Please create financial year {$next_fy_display['year_name']}.";
                        elseif ($cur_month === 6)
                            echo "ℹ️ It's " . date('F') . ". Prepare to create financial year {$next_fy_display['year_name']} next month.";
                        else
                            echo "✓ No action required. Financial years are created in July.";
                        ?>
                    </div>
                    <?php if (!$existing_fy && $cur_month === 7): ?>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700"><i class="fas fa-bell mr-1"></i>Notification Sent</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-0.5">Current Month</label>
                    <div class="px-3 py-2 bg-gray-100 border border-gray-300 rounded text-sm text-gray-700"><?= date('F') ?></div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-0.5">Current Date</label>
                    <div class="px-3 py-2 bg-gray-100 border border-gray-300 rounded text-sm text-gray-700"><?= date('Y-m-d') ?></div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-0.5">Next Financial Year</label>
                    <div class="px-3 py-2 bg-gray-100 border border-gray-300 rounded text-sm text-gray-700"><?= "{$next_fy_display['year_name']} ({$next_fy_display['start_date']} to {$next_fy_display['end_date']})" ?></div>
                </div>
            </div>
        </div>

        <!-- Add New Financial Year -->
        <div class="bg-white rounded-lg border border-gray-200 p-5 mb-5">
            <h4 class="font-semibold text-gray-900 mb-3">
                <i class="fas fa-plus-circle mr-1 text-green-600"></i>Add New Financial Year
            </h4>
            <?php if (!$fy_status['can_create']): ?>
                <div class="mb-3 px-3 py-2 rounded bg-yellow-50 border border-yellow-300 text-yellow-800 text-sm"><strong>Note:</strong> <?= htmlspecialchars($fy_status['reason']) ?></div>
            <?php endif; ?>
            <form method="POST" action="/admin/financial-year/add"
                  onsubmit="return confirm('Create financial year <?= $fy_status['can_create'] ? htmlspecialchars($fy_status['next_fy']['year_name']) : ''; ?>? This will allocate leave to all employees.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                        <input type="date" name="start_date" readonly required
                               value="<?= $fy_status['can_create'] ? $fy_status['next_fy']['start_date'] : ''; ?>"
                               class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded text-sm text-gray-700">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                        <input type="date" name="end_date" readonly required
                               value="<?= $fy_status['can_create'] ? $fy_status['next_fy']['end_date'] : ''; ?>"
                               class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded text-sm text-gray-700">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Details</label>
                        <div class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded text-sm text-gray-700">
                            <?php
                            if ($fy_status['can_create']) {
                                $s = new DateTime($fy_status['next_fy']['start_date']);
                                $e = new DateTime($fy_status['next_fy']['end_date']);
                                $days = $s->diff($e)->days + 1;
                                echo "{$fy_status['next_fy']['year_name']} ({$days} days)";
                            } else {
                                echo 'Not available';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" <?= !$fy_status['can_create'] ? 'disabled' : '' ?>
                            class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium">
                        <i class="fas fa-calendar-plus mr-1"></i><?= $fy_status['can_create'] ? " Create Financial Year {$fy_status['next_fy']['year_name']}" : ' Cannot Create' ?>
                    </button>
                    <button type="button" onclick="location.reload()"
                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 text-sm font-medium">
                        <i class="fas fa-sync-alt mr-1"></i>Refresh
                    </button>
                    <?php if (hasPermission('super_admin')): ?>
                        <button type="button" onclick="testFinancialYearNotification()"
                                class="px-4 py-2 bg-indigo-100 text-indigo-700 rounded hover:bg-indigo-200 text-sm font-medium">
                            <i class="fas fa-bell mr-1"></i>Test Notification
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Allocate Leave -->
        <div class="bg-white rounded-lg border border-gray-200 p-5 mb-5">
            <h4 class="font-semibold text-gray-900 mb-3">
                <i class="fas fa-user-plus mr-1 text-purple-600"></i>Allocate Leave to Employee
            </h4>
            <p class="text-sm text-gray-500 mb-3">Use for newly hired employees or to fill missing records. Existing records are skipped.</p>
            <form method="POST" action="/admin/leave/allocate" onsubmit="return confirm('Allocate leave?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employee</label>
                        <select name="employee_id" required
                                class="w-full px-3 py-2 bg-white border border-gray-300 rounded text-sm text-gray-700">
                            <option value="">Select...</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Financial Year</label>
                        <select name="financial_year_id" required
                                class="w-full px-3 py-2 bg-white border border-gray-300 rounded text-sm text-gray-700">
                            <option value="">Select...</option>
                            <?php foreach ($financial_years as $fy): ?>
                                <option value="<?= $fy['id'] ?>"><?= htmlspecialchars($fy['year_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Leave Types <span class="text-gray-400 font-normal">(optional)</span></label>
                        <div class="border border-gray-300 rounded bg-white max-h-32 overflow-y-auto p-2">
                            <label class="flex items-center gap-1.5 py-0.5 text-sm cursor-pointer">
                                <input type="checkbox" id="select_all_leave_types" onclick="toggleLeaveTypes()" class="rounded border-gray-400 text-blue-600">
                                <span class="font-medium">Select All</span>
                            </label>
                            <hr class="my-1 border-gray-200">
                            <?php foreach ($leave_types as $lt): ?>
                                <label class="flex items-center gap-1.5 py-0.5 text-sm cursor-pointer">
                                    <input type="checkbox" name="leave_types[]" value="<?= $lt['id'] ?>" class="leave-type-checkbox rounded border-gray-400 text-blue-600">
                                    <?= htmlspecialchars($lt['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm font-medium">
                    <i class="fas fa-user-plus mr-1"></i>Allocate Leave
                </button>
            </form>
        </div>

        <!-- Financial Years Table -->
        <div class="bg-white rounded-lg border border-gray-200 p-5">
            <h4 class="font-semibold text-gray-900 mb-3">
                <i class="fas fa-table mr-1 text-gray-500"></i>Existing Financial Years
            </h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50">
                            <th class="text-left py-2.5 px-3 font-semibold text-gray-600 text-xs uppercase">ID</th>
                            <th class="text-left py-2.5 px-3 font-semibold text-gray-600 text-xs uppercase">Year</th>
                            <th class="text-left py-2.5 px-3 font-semibold text-gray-600 text-xs uppercase">Start</th>
                            <th class="text-left py-2.5 px-3 font-semibold text-gray-600 text-xs uppercase">End</th>
                            <th class="text-left py-2.5 px-3 font-semibold text-gray-600 text-xs uppercase">Days</th>
                            <th class="text-left py-2.5 px-3 font-semibold text-gray-600 text-xs uppercase">Status</th>
                            <th class="text-left py-2.5 px-3 font-semibold text-gray-600 text-xs uppercase">Period</th>
                            <th class="text-left py-2.5 px-3 font-semibold text-gray-600 text-xs uppercase">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($financial_years)): ?>
                            <tr><td colspan="8" class="py-6 text-center text-gray-400">No financial years found</td></tr>
                        <?php else: foreach ($financial_years as $fy): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-2.5 px-3 text-gray-700"><?= $fy['id'] ?></td>
                                <td class="py-2.5 px-3 font-medium text-gray-900"><?= htmlspecialchars($fy['year_name']) ?></td>
                                <td class="py-2.5 px-3 text-gray-700"><?= date('M d, Y', strtotime($fy['start_date'])) ?></td>
                                <td class="py-2.5 px-3 text-gray-700"><?= date('M d, Y', strtotime($fy['end_date'])) ?></td>
                                <td class="py-2.5 px-3 text-gray-700"><?= $fy['total_days'] ?> days</td>
                                <td class="py-2.5 px-3">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $fy['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= $fy['is_active'] ? 'Active' : 'Inactive' ?></span>
                                </td>
                                <td class="py-2.5 px-3">
                                    <?php
                                    $today = date('Y-m-d');
                                    if ($today < $fy['start_date']): ?>
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">Future</span>
                                    <?php elseif ($today <= $fy['end_date']): ?>
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Current</span>
                                    <?php else: ?>
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">Past</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 text-gray-700"><?= date('M d, Y H:i', strtotime($fy['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
function toggleLeaveTypes() {
    const checked = document.getElementById('select_all_leave_types').checked;
    document.querySelectorAll('.leave-type-checkbox').forEach(cb => cb.checked = checked);
}
function testFinancialYearNotification() {
    if (!confirm('Send test notification?')) return;
    fetch('/test_financial_year_notification.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'test=true&csrf_token=<?= htmlspecialchars($csrf_token) ?>'
    }).then(r=>r.json()).then(d=>{
        alert(d.success ? 'Sent!' : 'Error: '+d.error);
        if(d.success) setTimeout(()=>location.reload(),1000);
    }).catch(e=>alert('Error: '+e));
}
</script>

</body>
</html>