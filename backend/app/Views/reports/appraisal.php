<?php
/**
 * Appraisal Reports View
 *
 * Clean, data-driven appraisal reporting interface.
 * Place: backend/app/Views/reports/appraisal.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/auth.php';
}

$pageTitle = 'Appraisal Reports - HR Management System';
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
                <h3>Appraisal Reports</h3>

                <!-- Statistics Cards -->
                <div class="stats-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['total']) ?></h3><p>Total Appraisals</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--success-color)"><i class="fas fa-check-double"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['completed']) ?></h3><p>Completed</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--warning-color)"><i class="fas fa-spinner"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['in_progress']) ?></h3><p>In Progress</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--info-color)"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['pending']) ?></h3><p>Pending</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--primary-color)"><i class="fas fa-star"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['avg_score'], 2) ?></h3><p>Avg Score</p></div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="glass-card mb-4">
                    <h5 class="mb-3"><i class="fas fa-filter"></i> Filters</h5>
                    <form method="GET" action="/reports/appraisal" class="filter-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Appraisal Cycle</label>
                                <select name="cycle" class="form-control">
                                    <option value="">All Cycles</option>
                                    <?php foreach ($filter_options['cycles'] as $cycle): ?>
                                        <option value="<?= htmlspecialchars($cycle['appraisal_cycle']) ?>" <?= ($filters['cycle'] ?? '') == $cycle['appraisal_cycle'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cycle['appraisal_cycle']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <select name="department" class="form-control">
                                    <option value="">All Departments</option>
                                    <?php foreach ($filter_options['departments'] as $dept): ?>
                                        <option value="<?= $dept['id'] ?>" <?= ($filters['department'] ?? '') == $dept['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dept['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Employee Search</label>
                                <input type="text" name="employee" class="form-control" placeholder="Employee ID or Name"
                                       value="<?= htmlspecialchars($filters['employee'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="in_progress" <?= ($filters['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="completed" <?= ($filters['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                            </div>
                            <div class="form-group d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                                <a href="/reports/appraisal" class="btn btn-secondary ml-2">
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
                            <form method="POST" action="/reports/appraisal/export" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="filters" value="<?= htmlspecialchars(json_encode($filters)) ?>">
                                <input type="hidden" name="export_type" value="excel">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-file-excel"></i> Export to Excel
                                </button>
                            </form>
                            <button onclick="window.print()" class="btn btn-secondary">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Appraisals Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h5><i class="fas fa-list"></i> Appraisal Records</h5>
                        <span class="records-count"><?= number_format(count($appraisals)) ?> records found</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Appraisal ID</th>
                                    <th>Employee ID</th>
                                    <th>Employee Name</th>
                                    <th>Department</th>
                                    <th>Cycle</th>
                                    <th>Reviewer</th>
                                    <th>Status</th>
                                    <th>Score</th>
                                    <th>Created Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($appraisals)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">
                                            <i class="fas fa-search" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
                                            No appraisals found matching the selected filters
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($appraisals as $appraisal): ?>
                                        <tr>
                                            <td><strong>#<?= htmlspecialchars($appraisal['id']) ?></strong></td>
                                            <td><?= htmlspecialchars($appraisal['emp_number']) ?></td>
                                            <td><?= htmlspecialchars($appraisal['employee_name']) ?></td>
                                            <td><?= htmlspecialchars($appraisal['department_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($appraisal['appraisal_cycle'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($appraisal['reviewer_name'] ?? 'N/A') ?></td>
                                            <td>
                                                <span class="badge badge-<?= 
                                                    $appraisal['status'] === 'completed' ? 'success' : 
                                                    ($appraisal['status'] === 'in_progress' ? 'warning' : 
                                                    ($appraisal['status'] === 'pending' ? 'info' : 'secondary')) 
                                                ?>">
                                                    <?= ucwords(str_replace('_', ' ', $appraisal['status'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($appraisal['overall_score']): ?>
                                                    <span class="badge badge-primary">
                                                        <?= number_format($appraisal['overall_score'], 2) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($appraisal['created_at'])) ?></td>
                                        </tr>
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

