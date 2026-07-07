<?php
/**
 * Employee Reports View
 *
 * Clean, data-driven employee reporting interface.
 * Place: backend/app/Views/reports/employees.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/auth.php';
}

$pageTitle = 'Employee Reports - HR Management System';
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
                <h3>Employee Reports</h3>

                <!-- Statistics Cards -->
                <div class="stats-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['total']) ?></h3><p>Total Employees</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--success-color)"><i class="fas fa-user-check"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['active']) ?></h3><p>Active</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--warning-color)"><i class="fas fa-user-clock"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['inactive']) ?></h3><p>Inactive</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--info-color)"><i class="fas fa-briefcase"></i></div>
                        <div class="stat-info">
                            <h3><?= number_format($stats['permanent']) ?>/<?= number_format($stats['contract']) ?>/<?= number_format($stats['temporary']) ?></h3>
                            <p>Perm/Cont/Temp</p>
                        </div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="glass-card mb-4">
                    <h5 class="mb-3"><i class="fas fa-filter"></i> Filters</h5>
                    <form method="GET" action="/reports/employees" class="filter-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Name, ID, email..."
                                       value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
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
                                <label>Section</label>
                                <select name="section" class="form-control">
                                    <option value="">All Sections</option>
                                    <?php foreach ($filter_options['sections'] as $sect): ?>
                                        <option value="<?= $sect['id'] ?>" <?= ($filters['section'] ?? '') == $sect['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sect['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Sub-Section</label>
                                <select name="subsection" class="form-control">
                                    <option value="">All Sub-Sections</option>
                                    <?php foreach ($filter_options['subsections'] as $subsect): ?>
                                        <option value="<?= $subsect['id'] ?>" <?= ($filters['subsection'] ?? '') == $subsect['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($subsect['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Employee Type</label>
                                <select name="type" class="form-control">
                                    <option value="">All Types</option>
                                    <option value="officer" <?= ($filters['type'] ?? '') === 'officer' ? 'selected' : '' ?>>Officer</option>
                                    <option value="section_head" <?= ($filters['type'] ?? '') === 'section_head' ? 'selected' : '' ?>>Section Head</option>
                                    <option value="sub_section_head" <?= ($filters['type'] ?? '') === 'sub_section_head' ? 'selected' : '' ?>>Sub Section Head</option>
                                    <option value="manager" <?= ($filters['type'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager</option>
                                    <option value="hr_manager" <?= ($filters['type'] ?? '') === 'hr_manager' ? 'selected' : '' ?>>HR Manager</option>
                                    <option value="dept_head" <?= ($filters['type'] ?? '') === 'dept_head' ? 'selected' : '' ?>>Department Head</option>
                                    <option value="managing_director" <?= ($filters['type'] ?? '') === 'managing_director' ? 'selected' : '' ?>>Managing Director</option>
                                    <option value="bod_chairman" <?= ($filters['type'] ?? '') === 'bod_chairman' ? 'selected' : '' ?>>BOD Chairman</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value="resigned" <?= ($filters['status'] ?? '') === 'resigned' ? 'selected' : '' ?>>Resigned</option>
                                    <option value="fired" <?= ($filters['status'] ?? '') === 'fired' ? 'selected' : '' ?>>Fired</option>
                                    <option value="retired" <?= ($filters['status'] ?? '') === 'retired' ? 'selected' : '' ?>>Retired</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Employment Type</label>
                                <select name="employment_type" class="form-control">
                                    <option value="">All Types</option>
                                    <option value="permanent" <?= ($filters['employment_type'] ?? '') === 'permanent' ? 'selected' : '' ?>>Permanent</option>
                                    <option value="contract" <?= ($filters['employment_type'] ?? '') === 'contract' ? 'selected' : '' ?>>Contract</option>
                                    <option value="temporary" <?= ($filters['employment_type'] ?? '') === 'temporary' ? 'selected' : '' ?>>Temporary</option>
                                    <option value="intern" <?= ($filters['employment_type'] ?? '') === 'intern' ? 'selected' : '' ?>>Intern</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Job Group</label>
                                <select name="job_group" class="form-control">
                                    <option value="">All Groups</option>
                                    <?php 
                                    $job_groups = ['1', '2', '3', '3A', '3B', '3C', '4', '5', '6', '7', '8', '9', '10'];
                                    foreach ($job_groups as $group): ?>
                                        <option value="<?= $group ?>" <?= ($filters['job_group'] ?? '') === $group ? 'selected' : '' ?>>
                                            <?= $group ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Hire Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Hire Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                            </div>
                            <div class="form-group d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                                <a href="/reports/employees" class="btn btn-secondary ml-2">
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
                            <form method="POST" action="/reports/employees/export" style="display:inline;">
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

                <!-- Employee Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h5><i class="fas fa-list"></i> Employee List</h5>
                        <span class="records-count"><?= number_format(count($employees)) ?> records found</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Employee ID</th>
                                    <th>Full Name</th>
                                    <th>Gender</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Designation</th>
                                    <th>Department</th>
                                    <th>Section</th>
                                    <th>Sub-Section</th>
                                    <th>Type</th>
                                    <th>Employment</th>
                                    <th>Status</th>
                                    <th>Job Group</th>
                                    <th>Hire Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employees)): ?>
                                    <tr>
                                        <td colspan="14" class="text-center text-muted">
                                            <i class="fas fa-search" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
                                            No employees found matching the selected filters
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($employees as $emp): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($emp['employee_id']) ?></strong></td>
                                            <td><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' ' . ($emp['surname'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars(ucfirst($emp['gender'] ?? 'N/A')) ?></td>
                                            <td><?= htmlspecialchars($emp['email'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($emp['phone'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($emp['designation'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($emp['department_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($emp['section_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($emp['subsection_name'] ?? 'N/A') ?></td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?= ucwords(str_replace('_', ' ', $emp['employee_type'] ?? 'N/A')) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?= ucwords($emp['employment_type'] ?? 'N/A') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?= $emp['employee_status'] === 'active' ? 'success' : 'secondary' ?>">
                                                    <?= ucwords($emp['employee_status'] ?? 'N/A') ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($emp['scale_id'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($emp['hire_date'] ? date('M d, Y', strtotime($emp['hire_date'])) : 'N/A') ?></td>
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
