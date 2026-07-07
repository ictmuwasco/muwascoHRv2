<?php

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/auth.php';
}

$pageTitle = 'Documentation Reports - HR Management System';
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
                <h3>Documentation Reports</h3>

                <!-- Statistics Cards -->
                <div class="stats-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['total']) ?></h3><p>Total Documents</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--success-color)"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['valid']) ?></h3><p>Valid</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--warning-color)"><i class="fas fa-exclamation-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['expiring_soon']) ?></h3><p>Expiring Soon</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--danger-color)"><i class="fas fa-times-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['expired']) ?></h3><p>Expired</p></div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="glass-card mb-4">
                    <h5 class="mb-3"><i class="fas fa-filter"></i> Filters</h5>
                    <form method="GET" action="/reports/documentation" class="filter-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Document Type</label>
                                <select name="document_type" class="form-control">
                                    <option value="">All Types</option>
                                    <?php foreach ($filter_options['document_types'] as $type): ?>
                                        <option value="<?= htmlspecialchars($type['document_type']) ?>" <?= ($filters['document_type'] ?? '') == $type['document_type'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($type['document_type']) ?>
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
                                <label>Expiry Status</label>
                                <select name="expiry_status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="valid" <?= ($filters['expiry_status'] ?? '') === 'valid' ? 'selected' : '' ?>>Valid</option>
                                    <option value="expiring_soon" <?= ($filters['expiry_status'] ?? '') === 'expiring_soon' ? 'selected' : '' ?>>Expiring Soon (30 days)</option>
                                    <option value="expired" <?= ($filters['expiry_status'] ?? '') === 'expired' ? 'selected' : '' ?>>Expired</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Upload Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Upload Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                            </div>
                            <div class="form-group d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                                <a href="/reports/documentation" class="btn btn-secondary ml-2">
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
                            <form method="POST" action="/reports/documentation/export" style="display:inline;">
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

                <!-- Documents Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h5><i class="fas fa-list"></i> Employee Documents</h5>
                        <span class="records-count"><?= number_format(count($documents)) ?> records found</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Document ID</th>
                                    <th>Employee ID</th>
                                    <th>Employee Name</th>
                                    <th>Department</th>
                                    <th>Document Type</th>
                                    <th>Document Number</th>
                                    <th>Issue Date</th>
                                    <th>Expiry Date</th>
                                    <th>Status</th>
                                    <th>Uploaded At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($documents)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted">
                                            <i class="fas fa-search" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
                                            No documents found matching the selected filters
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($documents as $doc): ?>
                                        <tr>
                                            <td><strong>#<?= htmlspecialchars($doc['id']) ?></strong></td>
                                            <td><?= htmlspecialchars($doc['emp_number']) ?></td>
                                            <td><?= htmlspecialchars($doc['employee_name']) ?></td>
                                            <td><?= htmlspecialchars($doc['department_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($doc['document_type'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($doc['document_number'] ?? 'N/A') ?></td>
                                            <td><?= $doc['issue_date'] ? date('M d, Y', strtotime($doc['issue_date'])) : 'N/A' ?></td>
                                            <td><?= $doc['expiry_date'] ? date('M d, Y', strtotime($doc['expiry_date'])) : 'N/A' ?></td>
                                            <td>
                                                <?php
                                                $expiryDate = $doc['expiry_date'] ? strtotime($doc['expiry_date']) : null;
                                                $today = time();
                                                $daysUntilExpiry = $expiryDate ? ($expiryDate - $today) / (60 * 60 * 24) : null;
                                                
                                                if (!$expiryDate):
                                                ?>
                                                    <span class="badge badge-info">No Expiry</span>
                                                <?php elseif ($daysUntilExpiry < 0): ?>
                                                    <span class="badge badge-danger">Expired</span>
                                                <?php elseif ($daysUntilExpiry <= 30): ?>
                                                    <span class="badge badge-warning">Expiring Soon</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success">Valid</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($doc['uploaded_at'])) ?></td>
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

