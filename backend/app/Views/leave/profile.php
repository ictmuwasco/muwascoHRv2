<?php

$pageTitle = 'My Leave Profile - HR Management System';
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

function fmtDate(?string $d): string { return $d ? date('d M Y', strtotime($d)) : '—'; }
function fmtDays(float $d): string { return ($d == (int)$d) ? (string)(int)$d : number_format($d, 1); }
function statusBadgeHtml(string $status): string {
    $map = [
        'approved' => ['✓ Approved', 'badge-success'],
        'rejected' => ['✗ Rejected', 'badge-danger'],
        'pending' => [' Pending', 'badge-warning'],
        'pending_subsection_head' => [' Sub-Section Head', 'badge-info'],
        'pending_section_head' => ['Section Head', 'badge-info'],
        'pending_dept_head' => [' Dept Head', 'badge-primary'],
        'pending_managing_director' => [' MD', 'badge-secondary'],
        'pending_hr' => ['HR', 'badge-warning'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'badge-secondary'];
    return "<span class='badge {$cls}'>{$label}</span>";
}
?>
<div class="lg:pl-64 pt-16 min-h-screen <?= ($_SESSION['theme'] ?? 'light') === 'light' ? 'bg-gray-50' : 'bg-dark-bg' ?>">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php include __DIR__ . '/../components/leave_tabs.php'; ?>

        <div class="mt-6">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h3 class="m-0">My Leave Profile</h3>
                        <p class="text-muted mt-1 mb-0">Leave balances & history</p>
                    </div>
                    <?php if ($selectedFY): ?>
                        <span class="badge badge-info">FY <?= htmlspecialchars($selectedFY['year_name']) ?>
                            <?php if ($isCurrentFY): ?><span class="badge badge-success ml-1">Current</span><?php endif; ?>
                            <?php if ($isFutureFY): ?><span class="badge badge-warning ml-1">Future</span><?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Employee search (HR only) -->
                <?php if ($canViewAll): ?>
                <div class="card p-3 mb-4">
                    <form method="GET" class="form-inline">
                        <?php if ($selectedFY): ?><input type="hidden" name="view_fy" value="<?= $selectedFY['id'] ?>"><?php endif; ?>
                        <div class="form-group mr-2">
                            <label class="mr-2">View Employee:</label>
                            <select name="employee_id" class="form-control" onchange="this.form.submit()">
                                <option value="">— Select —</option>
                                <?php foreach ($allEmployees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $selectedEmployeeId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['employee_id'] . ' – ' . $emp['first_name'] . ' ' . $emp['last_name'] . ' (' . ($emp['dept'] ?? 'N/A') . ')') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($selectedEmployeeId !== ($ownEmployee['id'] ?? 0)): ?>
                            <a href="/leave/profile" class="btn btn-secondary btn-sm">My Profile</a>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>

                <!-- FY Switcher -->
                <?php if (count($allFYs) > 1): ?>
                <div class="mb-3">
                    <strong class="mr-2">Financial Year:</strong>
                    <?php foreach ($allFYs as $fy):
                        $fyIsFuture = $fy['start_date'] > date('Y-m-d');
                        $isActive = (int)$fy['id'] === (int)($selectedFY['id'] ?? 0);
                        $params = array_merge($_GET, ['view_fy' => $fy['id']]);
                    ?>
                        <a href="/leave/profile?<?= http_build_query($params) ?>" 
                           class="btn btn-sm <?= $isActive ? 'btn-primary' : ($fyIsFuture ? 'btn-outline-warning' : 'btn-outline-secondary') ?> mr-1">
                            <?= htmlspecialchars($fy['year_name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!$employee): ?>
                    <div class="alert alert-warning">Employee record not found.</div>
                <?php else: ?>

                <!-- Employee Identity -->
                <div class="card p-3 mb-4">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mr-3" 
                             style="width:50px;height:50px;font-size:1.2rem;font-weight:700;">
                            <?= strtoupper(substr($employee['first_name'],0,1) . substr($employee['last_name']??'',0,1)) ?>
                        </div>
                        <div>
                            <h5 class="mb-1"><?= htmlspecialchars(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?></h5>
                            <small class="text-muted"><?= htmlspecialchars($employee['employee_id'] ?? '') ?> · <?= htmlspecialchars($employee['employment_type'] ?? 'N/A') ?></small>
                            <div class="mt-1">
                                <?php if (!empty($employee['department_name'])): ?><span class="badge badge-light mr-1"><?= htmlspecialchars($employee['department_name']) ?></span><?php endif; ?>
                                <?php if (!empty($employee['section_name'])): ?><span class="badge badge-light mr-1"><?= htmlspecialchars($employee['section_name']) ?></span><?php endif; ?>
                                <?php if (!empty($employee['designation']) && $employee['designation'] !== '0'): ?><span class="badge badge-light"><?= htmlspecialchars($employee['designation']) ?></span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($balances)): ?>
                    <div class="alert alert-warning">No leave balances found for FY <strong><?= htmlspecialchars($selectedFY['year_name'] ?? '') ?></strong>.</div>
                <?php else: ?>
                <!-- Balance Cards -->
                <h5 class="mb-3">Leave Balances — FY <?= htmlspecialchars($selectedFY['year_name']) ?></h5>
                <div class="row">
                    <?php foreach ($balances as $b):
                        $rem = (float)$b['remaining_days'];
                        $alloc = (float)$b['allocated_days'];
                        $bf = (float)$b['brought_forward_days'];
                        $used = max(0, $alloc + $bf - $rem);
                        $pct = ($alloc + $bf) > 0 ? min(100, ($used / ($alloc + $bf)) * 100) : 0;
                    ?>
                    <div class="col-md-4 col-sm-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title text-muted text-uppercase small"><?= htmlspecialchars($b['leave_type_name']) ?></h6>
                                <h2 class="mb-0 <?= $rem > 0 ? 'text-success' : ($rem < 0 ? 'text-danger' : 'text-muted') ?>"><?= fmtDays($rem) ?></h2>
                                <small class="text-muted">days remaining</small>
                                <div class="progress mt-2" style="height:5px">
                                    <div class="progress-bar <?= $pct > 80 ? 'bg-danger' : ($pct > 50 ? 'bg-warning' : 'bg-success') ?>" 
                                         style="width:<?= $pct ?>%"></div>
                                </div>
                                <div class="mt-2 small text-muted">
                                    Alloc: <?= fmtDays($alloc) ?>
                                    <?php if ($bf > 0): ?> | BF: +<?= fmtDays($bf) ?><?php endif; ?>
                                    | Used: <?= fmtDays($used) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Leave Applications -->
                <h5 class="mb-3 mt-4">Leave Applications — FY <?= htmlspecialchars($selectedFY['year_name']) ?></h5>
                
                <?php if (empty($leaveApplications)): ?>
                    <div class="alert alert-info">No leave applications recorded in this financial year.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>#</th><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Status</th><th>Applied</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaveApplications as $app): ?>
                            <tr>
                                <td><?= (int)$app['id'] ?></td>
                                <td><?= htmlspecialchars($app['leave_type_name']) ?></td>
                                <td><?= fmtDate($app['start_date']) ?></td>
                                <td><?= fmtDate($app['end_date']) ?></td>
                                <td><?= (int)$app['days_requested'] ?></td>
                                <td><?= statusBadgeHtml($app['status']) ?></td>
                                <td><?= fmtDate($app['applied_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>