<?php
/**
 * Consent Management View
 *
 * Clean interface for managing employee consents with filtering and export.
 * Place: backend/app/Views/consent/index.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/Helpers/Auth.php';
}

$pageTitle = 'Employee Consent Management - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen"
      style="background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);">

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

            <?php $activeTab = 'consents'; include __DIR__ . '/../components/admin_tabs.php'; ?>

            <div class="tab-content">
                <h3>Employee Consent Management</h3>

                <!-- Statistics Cards -->
                <div class="stats-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['total_employees']) ?></h3><p>Total Employees</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--success-color)"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['consented_employees']) ?></h3><p>Consented</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--warning-color)"><i class="fas fa-exclamation-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['pending_consents']) ?></h3><p>Pending</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--info-color)"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-info"><h3><?= $stats['completion_rate'] ?>%</h3><p>Completion Rate</p></div>
                    </div>
                </div>

                <!-- Filters + Export -->
                <div class="glass-card mb-4">
                    <div class="filter-export-bar">
                        <form method="GET" action="/consent-management" class="filter-form">
                            <div class="form-group">
                                <label>Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Name, email, ID..."
                                       value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <select name="department" class="form-control">
                                    <option value="">All</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= (($_GET['department'] ?? '') == $d['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="consent_status" class="form-control">
                                    <option value="">All</option>
                                    <option value="consented" <?= (($_GET['consent_status'] ?? '')==='consented') ? 'selected' : '' ?>>Consented</option>
                                    <option value="not_consented" <?= (($_GET['consent_status'] ?? '')==='not_consented') ? 'selected' : '' ?>>Pending</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Apply</button>
                                <a href="/consent-management" class="btn btn-secondary">Clear</a>
                            </div>
                        </form>

                        <div class="export-actions">
                            <form method="POST" id="exportForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="export_type" id="export_type_input">
                                <input type="hidden" name="filters" value="<?= htmlspecialchars(json_encode($_GET)) ?>">
                                <button type="button" onclick="doExport('pdf')" class="btn btn-danger"><i class="fas fa-file-pdf"></i> PDF</button>
                                <button type="button" onclick="doExport('excel')" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee ID</th><th>Full Name</th><th>National ID</th>
                                <th>Department</th><th>Position</th>
                                <th>Consent Status</th><th>Consent Date</th>
                                <th>IP Address</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($consents)): ?>
                                <tr><td colspan="9" class="text-center">No records found</td></tr>
                            <?php else: foreach ($consents as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['employee_id']) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($c['email']) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($c['consent_national_id']): ?>
                                            <?= htmlspecialchars(maskNationalId($c['consent_national_id'])) ?>
                                            <br><small class="text-muted">(Partially masked)</small>
                                        <?php else: ?>
                                            <span class="text-muted">Not provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($c['department_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($c['designation'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if ($c['consent_given']): ?>
                                            <span class="badge badge-success"><i class="fas fa-check"></i> Consented</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $c['consent_date'] ? formatDate($c['consent_date']) : 'N/A' ?></td>
                                    <td><?= $c['ip_address'] ? '<code>'.htmlspecialchars($c['ip_address']).'</code>' : '<span class="text-muted">N/A</span>' ?></td>
                                    <td>
                                        <?php if ($c['consent_given']): ?>
                                            <button onclick="viewConsentDetails(<?= (int)$c['consent_id'] ?>)" class="btn btn-sm btn-info" title="View"><i class="fas fa-eye"></i></button>
                                            <button onclick="window.open('download_consent.php?id=<?= (int)$c['consent_id'] ?>','_blank')" class="btn btn-sm btn-primary" title="PDF"><i class="fas fa-download"></i></button>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                           class="page-link <?= $i === $current_page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>
</div>

<script>
function doExport(type) {
    document.getElementById('export_type_input').value = type;
    document.getElementById('exportForm').submit();
}

function viewConsentDetails(id) {
    // TODO: replace with a detail modal
    alert('Consent ID: ' + id);
}

document.addEventListener('DOMContentLoaded', () => {
    ['search','department','consent_status','date_from','date_to'].forEach(n => {
        const el = document.querySelector('[name="'+n+'"]');
        if (el) el.addEventListener('change', () => el.closest('form').submit());
    });
});
</script>
</body>
</html>

