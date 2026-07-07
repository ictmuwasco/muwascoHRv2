<?php
/**
 * Leave History View
 * Place: backend/app/Views/leave/history.php
 */
$pageTitle = 'Leave History - HR Management System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen <?= ($_SESSION['theme'] ?? 'light') === 'light' ? 'bg-gray-50 text-gray-900' : 'bg-dark-bg text-white' ?>">

    <!-- Sidebar Navigation -->
    <?php require __DIR__ . '/../components/navbar.php'; ?>
    <?php require __DIR__ . '/../components/header_bar.php'; ?>

function formatDate(?string $d): string { return $d ? date('M d, Y', strtotime($d)) : 'N/A'; }
function statusBadge(string $s): string {
    $map = ['pending'=>'badge-warning','approved'=>'badge-success','rejected'=>'badge-danger','cancelled'=>'badge-secondary'];
    $cls = $map[$s] ?? 'badge-light';
    return "<span class=\"badge {$cls}\">" . ucfirst($s) . "</span>";
}
?>
<div class="lg:pl-64 pt-16 min-h-screen <?= ($_SESSION['theme'] ?? 'light') === 'light' ? 'bg-gray-50' : 'bg-dark-bg' ?>">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php include __DIR__ . '/../components/leave_tabs.php'; ?>
        
        <div class="mt-6">
            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Leave History</h3>

                <?php if ($flash ?? false): ?>
                    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
                        <?= htmlspecialchars($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <!-- Employees Currently on Leave -->
                <div class="table-container mb-4">
                    <h4>Employees Currently on Leave</h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th><th>Leave Type</th><th>Start Date</th><th>End Date</th><th>Days</th><th>Remaining Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($currentLeaves)): ?>
                                <tr><td colspan="6" class="text-center">No employees currently on leave</td></tr>
                            <?php else: foreach ($currentLeaves as $leave): ?>
                                <tr>
                                    <td><?= htmlspecialchars($leave['employee_id'] . ' - ' . $leave['first_name'] . ' ' . $leave['last_name']) ?></td>
                                    <td><?= htmlspecialchars($leave['leave_type_name']) ?></td>
                                    <td><?= formatDate($leave['start_date']) ?></td>
                                    <td><?= formatDate($leave['end_date']) ?></td>
                                    <td><?= (int)$leave['days_requested'] ?></td>
                                    <td><?= number_format((float)($leave['remaining_days'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- All Leave History -->
                <div class="table-container">
                    <h4>All Leave Applications (Recent 50)</h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th><th>Leave Type</th><th>Start Date</th><th>End Date</th><th>Days</th><th>Remaining Days</th><th>Applied Date</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allLeaves as $leave): ?>
                                <tr>
                                    <td><?= htmlspecialchars($leave['employee_id'] . ' - ' . $leave['first_name'] . ' ' . $leave['last_name']) ?></td>
                                    <td><?= htmlspecialchars($leave['leave_type_name']) ?></td>
                                    <td><?= formatDate($leave['start_date']) ?></td>
                                    <td><?= formatDate($leave['end_date']) ?></td>
                                    <td><?= (int)$leave['days_requested'] ?></td>
                                    <td><?= number_format((float)($leave['remaining_days'] ?? 0), 2) ?></td>
                                    <td><?= formatDate($leave['applied_at']) ?></td>
                                    <td><?= statusBadge($leave['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>