<?php
/**
 * Attendance Reports View
 *
 * Clean, data-driven attendance reporting interface.
 * Place: backend/app/Views/reports/attendance.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/auth.php';
}

$pageTitle = 'Attendance Reports - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>

<div class="container">
    <div class="main-content">
        <div class="content">

            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="alert alert-<?= $_SESSION['flash_type'] ?? 'info' ?>">
                    <?= htmlspecialchars($_SESSION['flash_message']) ?>
                    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <div class="tab-content">
                <h3>Attendance Reports</h3>

                <!-- Statistics Cards -->
                <div class="stats-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['total_active']) ?></h3><p>Total Active Employees</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--success-color)"><i class="fas fa-user-check"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['total_present']) ?></h3><p>Present</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--danger-color)"><i class="fas fa-user-times"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['absent_count']) ?></h3><p>Absent</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--info-color)"><i class="fas fa-clock"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['total_records']) ?></h3><p>Total Records</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--warning-color)"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['avg_hours'], 1) ?>h</h3><p>Avg Hours</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#dc3545"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['late_count']) ?></h3><p>Late Arrivals</p></div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="glass-card mb-4">
                    <h5 class="mb-3"><i class="fas fa-filter"></i> Filters</h5>
                    <form method="GET" action="/reports/attendance" class="filter-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from']) ?>">
                            </div>
                            <div class="form-group">
                                <label>Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to']) ?>">
                            </div>
                            <div class="form-group">
                                <label>Office</label>
                                <select name="office" class="form-control">
                                    <option value="all" <?= ($filters['office'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Offices</option>
                                    <?php foreach ($filter_options['offices'] as $office): ?>
                                        <option value="<?= $office['id'] ?>" <?= ($filters['office'] ?? '') == $office['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($office['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <select name="department" class="form-control">
                                    <option value="all" <?= ($filters['department'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Departments</option>
                                    <?php foreach ($filter_options['departments'] as $dept): ?>
                                        <option value="<?= $dept['id'] ?>" <?= ($filters['department'] ?? '') == $dept['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dept['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Employee Search</label>
                                <input type="text" name="employee" class="form-control" placeholder="Employee ID or Name"
                                       value="<?= htmlspecialchars($filters['employee'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="all" <?= ($filters['status'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="clocked_in" <?= ($filters['status'] ?? '') === 'clocked_in' ? 'selected' : '' ?>>Clocked In</option>
                                    <option value="clocked_out" <?= ($filters['status'] ?? '') === 'clocked_out' ? 'selected' : '' ?>>Clocked Out</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Report Type</label>
                                <select name="report_type" class="form-control" onchange="this.form.submit()">
                                    <option value="summary" <?= ($report_type ?? 'summary') === 'summary' ? 'selected' : '' ?>>Summary Report</option>
                                    <option value="detailed" <?= ($report_type ?? '') === 'detailed' ? 'selected' : '' ?>>Detailed Report</option>
                                    <option value="late" <?= ($report_type ?? '') === 'late' ? 'selected' : '' ?>>Late Arrivals</option>
                                    <option value="absent" <?= ($report_type ?? '') === 'absent' ? 'selected' : '' ?>>Absent Employees</option>
                                    <option value="location" <?= ($report_type ?? '') === 'location' ? 'selected' : '' ?>>Location Issues</option>
                                </select>
                            </div>
                            <div class="form-group d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Generate Report
                                </button>
                                <a href="/reports/attendance" class="btn btn-secondary ml-2">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Export Buttons -->
                <div class="glass-card mb-4">
                    <div class="card-body">
                        <div class="export-actions">
                            <?php if ($report_type !== 'absent'): ?>
                                <form method="POST" action="/reports/attendance/export" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="filters" value="<?= htmlspecialchars(json_encode($filters)) ?>">
                                    <input type="hidden" name="export_type" value="csv">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-file-csv"></i> Export CSV
                                    </button>
                                </form>
                            <?php endif; ?>
                            <button onclick="window.print()" class="btn btn-secondary">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Data Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h5><i class="fas fa-list"></i> 
                            <?php
                            switch ($report_type) {
                                case 'detailed': echo 'Detailed Attendance Records'; break;
                                case 'late': echo 'Late Arrivals'; break;
                                case 'absent': echo 'Absent Employees'; break;
                                case 'location': echo 'Location Issues'; break;
                                default: echo 'Attendance Summary';
                            }
                            ?>
                        </h5>
                        <span class="records-count"><?= number_format(count($data)) ?> records found</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <?php if ($report_type === 'summary'): ?>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Employee Name</th>
                                        <th>Department</th>
                                        <th>Office</th>
                                        <th>Total Days</th>
                                        <th>Avg Hours</th>
                                        <th>Earliest In</th>
                                        <th>Latest In</th>
                                        <th>Late Count</th>
                                        <th>Undertime Count</th>
                                    </tr>
                                <?php elseif ($report_type === 'detailed'): ?>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Employee Name</th>
                                        <th>Department</th>
                                        <th>Office</th>
                                        <th>Date</th>
                                        <th>Clock In</th>
                                        <th>Clock Out</th>
                                        <th>Total Hours</th>
                                        <th>Status</th>
                                    </tr>
                                <?php elseif ($report_type === 'late'): ?>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Employee Name</th>
                                        <th>Department</th>
                                        <th>Office</th>
                                        <th>Date</th>
                                        <th>Clock In Time</th>
                                        <th>Minutes Late</th>
                                    </tr>
                                <?php elseif ($report_type === 'absent'): ?>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Employee Name</th>
                                        <th>Department</th>
                                        <th>Office</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Absent Days</th>
                                        <th>Absent Dates</th>
                                    </tr>
                                <?php elseif ($report_type === 'location'): ?>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Employee Name</th>
                                        <th>Office</th>
                                        <th>Date</th>
                                        <th>Clock In Time</th>
                                        <th>Accuracy (m)</th>
                                        <th>Distance (m)</th>
                                    </tr>
                                <?php endif; ?>
                            </thead>
                            <tbody>
                                <?php if (empty($data)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted">
                                            <i class="fas fa-search" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
                                            No records found matching the selected filters
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($data as $row): ?>
                                        <?php if ($report_type === 'summary'): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($row['emp_number']) ?></strong></td>
                                                <td><?= htmlspecialchars($row['employee_name']) ?></td>
                                                <td><?= htmlspecialchars($row['department_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($row['office_name'] ?? 'N/A') ?></td>
                                                <td><?= number_format($row['total_days']) ?></td>
                                                <td><?= number_format($row['avg_hours'], 1) ?>h</td>
                                                <td><?= htmlspecialchars($row['earliest_clock_in'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($row['latest_clock_in'] ?? 'N/A') ?></td>
                                                <td>
                                                    <span class="badge badge-<?= $row['late_count'] > 0 ? 'warning' : 'success' ?>">
                                                        <?= number_format($row['late_count']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= $row['undertime_count'] > 0 ? 'danger' : 'success' ?>">
                                                        <?= number_format($row['undertime_count']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php elseif ($report_type === 'detailed'): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($row['emp_number']) ?></strong></td>
                                                <td><?= htmlspecialchars($row['employee_name']) ?></td>
                                                <td><?= htmlspecialchars($row['department_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($row['office_name'] ?? 'N/A') ?></td>
                                                <td><?= date('M d, Y', strtotime($row['date'])) ?></td>
                                                <td><?= date('H:i', strtotime($row['clock_in'])) ?></td>
                                                <td><?= $row['clock_out'] ? date('H:i', strtotime($row['clock_out'])) : 'N/A' ?></td>
                                                <td><?= number_format($row['total_hours'], 1) ?>h</td>
                                                <td>
                                                    <span class="badge badge-<?= $row['status'] === 'clocked_in' ? 'success' : 'secondary' ?>">
                                                        <?= ucwords(str_replace('_', ' ', $row['status'])) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php elseif ($report_type === 'late'): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($row['emp_number']) ?></strong></td>
                                                <td><?= htmlspecialchars($row['employee_name']) ?></td>
                                                <td><?= htmlspecialchars($row['department_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($row['office_name'] ?? 'N/A') ?></td>
                                                <td><?= date('M d, Y', strtotime($row['date'])) ?></td>
                                                <td><?= date('H:i', strtotime($row['clock_in_time'])) ?></td>
                                                <td>
                                                    <span class="badge badge-warning">
                                                        <?= number_format($row['minutes_late']) ?> mins
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php elseif ($report_type === 'absent'): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($row['emp_number']) ?></strong></td>
                                                <td><?= htmlspecialchars($row['employee_name']) ?></td>
                                                <td><?= htmlspecialchars($row['department_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($row['office_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($row['email'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($row['phone'] ?? 'N/A') ?></td>
                                                <td>
                                                    <span class="badge badge-danger">
                                                        <?= number_format($row['absent_days']) ?> days
                                                    </span>
                                                </td>
                                                <td><small><?= htmlspecialchars($row['absent_dates'] ?? 'N/A') ?></small></td>
                                            </tr>
                                        <?php elseif ($report_type === 'location'): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($row['emp_number']) ?></strong></td>
                                                <td><?= htmlspecialchars($row['employee_name']) ?></td>
                                                <td><?= htmlspecialchars($row['office_name'] ?? 'N/A') ?></td>
                                                <td><?= date('M d, Y', strtotime($row['date'])) ?></td>
                                                <td><?= date('H:i', strtotime($row['clock_in_time'])) ?></td>
                                                <td>
                                                    <span class="badge badge-<?= $row['accuracy'] > 100 ? 'warning' : 'success' ?>">
                                                        <?= number_format($row['accuracy']) ?>m
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= $row['distance_from_office'] > 200 ? 'danger' : 'success' ?>">
                                                        <?= number_format($row['distance_from_office']) ?>m
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

