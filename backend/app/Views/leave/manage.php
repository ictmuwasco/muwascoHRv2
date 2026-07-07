<?php
/**
 * Manage Leave View
 * Place: backend/app/Views/leave/manage.php
 */
$pageTitle = 'Manage Leave - HR Management System';
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
function badgeClass(string $s): string {
    return match($s) {
        'approved' => 'badge-success', 'rejected' => 'badge-danger',
        'pending' => 'badge-warning', default => 'badge-secondary',
    };
}
function statusDisplay(string $s): string {
    return match($s) {
        'pending_subsection_head' => 'Pending Subsection Head',
        'pending_section_head' => 'Pending Section Head',
        'pending_dept_head' => 'Pending Dept Head',
        'pending_managing_director' => 'Pending MD',
        'pending_hr' => 'Pending HR', default => ucfirst(str_replace('_', ' ', $s)),
    };
}
function getDelegateDisplay(?array $leave): string {
    if (empty($leave['del_first'])) return '';
    $name = trim($leave['del_first'] . ' ' . $leave['del_last']);
    $designation = $leave['del_designation'] ?? '';
    $empId = $leave['del_emp_id'] ?? '';
    return htmlspecialchars($name . ($designation ? " ($designation)" : '') . ($empId ? " [$empId]" : ''));
}
function getAttachmentStatus(array $attachments): string {
    $hasStudy = false;
    $hasMedical = false;
    foreach ($attachments as $att) {
        if ($att['document_type'] === 'study_timetable') $hasStudy = true;
        if ($att['document_type'] === 'medical_certificate') $hasMedical = true;
    }
    return ['study' => $hasStudy, 'medical' => $hasMedical];
}
?>
<div class="lg:pl-64 pt-16 min-h-screen <?= ($_SESSION['theme'] ?? 'light') === 'light' ? 'bg-gray-50' : 'bg-dark-bg' ?>">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php include __DIR__ . '/../components/leave_tabs.php'; ?>
        
        <div class="mt-6">
            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Manage Leave Applications</h3>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
                        <?= htmlspecialchars($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <!-- PENDING -->
                <div class="table-container mb-6">
                    <h4>Pending Applications <span class="badge badge-warning"><?= count($pendingLeaves) ?></span></h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th><th>Type</th><th>Start</th><th>End</th>
                                <th>Days</th><th>Status</th><th>Applied</th><th>Delegate</th><th>Attachments</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pendingLeaves)): ?>
                                <tr><td colspan="10" class="text-center">No pending applications</td></tr>
                            <?php else: foreach ($pendingLeaves as $l): 
                                $attachments = $l['attachments'] ?? [];
                                $attStatus = getAttachmentStatus($attachments);
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($l['employee_id'].' - '.$l['first_name'].' '.$l['last_name']) ?></td>
                                    <td><?= htmlspecialchars($l['leave_type_name']) ?></td>
                                    <td><?= formatDate($l['start_date']) ?></td>
                                    <td><?= formatDate($l['end_date']) ?></td>
                                    <td><?= (int)$l['days_requested'] ?></td>
                                    <td><span class="badge <?= badgeClass($l['status']) ?>"><?= statusDisplay($l['status']) ?></span></td>
                                    <td><?= formatDate($l['applied_at']) ?></td>
                                    <td><?= getDelegateDisplay($l) ?: '<span class="text-muted">None</span>' ?></td>
                                    <td>
                                        <?php if (empty($attachments)): ?>
                                            <span class="text-muted">None</span>
                                        <?php else: ?>
                                            <div class="attachment-list">
                                                <?php foreach ($attachments as $att): ?>
                                                    <a href="/leave/download/<?= $att['id'] ?>" class="btn btn-sm btn-info" title="Download: <?= htmlspecialchars($att['file_name']) ?>">
                                                        <i class="fas fa-paperclip"></i> <?= htmlspecialchars($att['file_name']) ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="action-buttons">
                                        <?php
                                        $csrf = $csrf_token;
                                        $id = (int)$l['id'];
                                        $st = $l['status'];
                                        $role = $userRole;
                                        $action = match(true) {
                                            $role==='sub_section_head' && $st==='pending_subsection_head' => 'subsection_head_approve',
                                            $role==='section_head' && $st==='pending_section_head' => 'section_head_approve',
                                            $role==='dept_head' && $st==='pending_dept_head' => 'dept_head_approve',
                                            $role==='managing_director' && $st==='pending_managing_director' => 'managing_director_approve',
                                            $role==='hr_manager' => 'hr_approve',
                                            default => null,
                                        };
                                        ?>
                                        <?php if ($action): ?>
                                            <a href="/leave/manage/approve?action=<?= $action ?>&id=<?= $id ?>&csrf_token=<?= $csrf ?>"
                                               class="btn btn-success btn-sm" onclick="return confirm('Approve?')">✓</a>
                                            <a href="/leave/manage/approve?action=reject_leave&id=<?= $id ?>&csrf_token=<?= $csrf ?>"
                                               class="btn btn-danger btn-sm" onclick="return confirm('Reject?')">✕</a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- APPROVED -->
                <div class="table-container mb-6">
                    <h4>Approved <span class="badge badge-success"><?= count($approvedLeaves) ?></span></h4>
                    <table class="table">
                        <thead>
                            <tr><th>Employee</th><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Delegate</th><th>Attachments</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($approvedLeaves)): ?>
                                <tr><td colspan="8" class="text-center">No approved leaves</td></tr>
                            <?php else: foreach ($approvedLeaves as $l): 
                                $attachments = $l['attachments'] ?? [];
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($l['employee_id'].' - '.$l['first_name'].' '.$l['last_name']) ?></td>
                                    <td><?= htmlspecialchars($l['leave_type_name']) ?></td>
                                    <td><?= formatDate($l['start_date']) ?></td>
                                    <td><?= formatDate($l['end_date']) ?></td>
                                    <td><?= (int)$l['days_requested'] ?></td>
                                    <td><?= getDelegateDisplay($l) ?: '<span class="text-muted">None</span>' ?></td>
                                    <td>
                                        <?php if (empty($attachments)): ?>
                                            <span class="text-muted">None</span>
                                        <?php else: ?>
                                            <?php foreach ($attachments as $att): ?>
                                                <a href="/leave/download/<?= $att['id'] ?>" class="btn btn-sm btn-info" title="Download: <?= htmlspecialchars($att['file_name']) ?>">
                                                    <i class="fas fa-paperclip"></i> <?= htmlspecialchars($att['file_name']) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-success">Approved</span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- REJECTED -->
                <div class="table-container mb-4">
                    <h4>Rejected <span class="badge badge-danger"><?= count($rejectedLeaves) ?></span></h4>
                    <table class="table">
                        <thead>
                            <tr><th>Employee</th><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Delegate</th><th>Attachments</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rejectedLeaves)): ?>
                                <tr><td colspan="8" class="text-center">No rejected leaves</td></tr>
                            <?php else: foreach ($rejectedLeaves as $l): 
                                $attachments = $l['attachments'] ?? [];
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($l['employee_id'].' - '.$l['first_name'].' '.$l['last_name']) ?></td>
                                    <td><?= htmlspecialchars($l['leave_type_name']) ?></td>
                                    <td><?= formatDate($l['start_date']) ?></td>
                                    <td><?= formatDate($l['end_date']) ?></td>
                                    <td><?= (int)$l['days_requested'] ?></td>
                                    <td><?= getDelegateDisplay($l) ?: '<span class="text-muted">None</span>' ?></td>
                                    <td>
                                        <?php if (empty($attachments)): ?>
                                            <span class="text-muted">None</span>
                                        <?php else: ?>
                                            <?php foreach ($attachments as $att): ?>
                                                <a href="/leave/download/<?= $att['id'] ?>" class="btn btn-sm btn-info" title="Download: <?= htmlspecialchars($att['file_name']) ?>">
                                                    <i class="fas fa-paperclip"></i> <?= htmlspecialchars($att['file_name']) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-danger">Rejected</span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

<style>
.attachment-list { display: flex; flex-direction: column; gap: 4px; }
</style>
