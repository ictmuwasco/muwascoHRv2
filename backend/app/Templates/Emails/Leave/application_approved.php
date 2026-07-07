<?php
/**
 * Leave Approved - Email Template
 * Variables: $employeeName, $leaveType, $startDate, $endDate, $days, $delegateName, $delegatePosition, $approverName, $systemUrl
 */
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;line-height:1.6;color:#333}
.container{max-width:600px;margin:0 auto;padding:20px}
.header{background:#27ae60;color:#fff;padding:20px;text-align:center;border-radius:5px 5px 0 0}
.content{background:#f9f9f9;padding:20px;border:1px solid #ddd}
.footer{text-align:center;padding:15px;font-size:12px;color:#666}
.details{background:#fff;padding:15px;border-radius:5px;margin:15px 0;border:1px solid #e0e0e0}
.label{font-weight:700;color:#555}
.delegate-info{background:#e8f4fd;border-left:4px solid #3498db;padding:12px;margin:15px 0;border-radius:4px}
.btn{display:inline-block;padding:10px 20px;background:#27ae60;color:#fff;text-decoration:none;border-radius:5px}
</style></head>
<body>
<div class="container">
    <div class="header"><h2>Leave Approved</h2></div>
    <div class="content">
        <p>Dear <strong><?= htmlspecialchars($employeeName) ?></strong>,</p>
        <p>Your leave application has been <strong style="color:#27ae60">approved</strong>.</p>
        <div class="details">
            <p><span class="label">Leave Type:</span> <?= htmlspecialchars($leaveType) ?></p>
            <p><span class="label">Start Date:</span> <?= htmlspecialchars($startDate) ?></p>
            <p><span class="label">End Date:</span> <?= htmlspecialchars($endDate) ?></p>
            <p><span class="label">Duration:</span> <?= (int)$days ?> day(s)</p>
            <p><span class="label">Approved By:</span> <?= htmlspecialchars($approverName ?? 'System') ?></p>
        </div>
        <?php if (!empty($delegateName)): ?>
        <div class="delegate-info">
            <p><strong>Task Delegation:</strong></p>
            <p><strong><?= htmlspecialchars($delegateName) ?></strong> 
            <?= !empty($delegatePosition) ? '(' . htmlspecialchars($delegatePosition) . ')' : '' ?> 
            has been assigned to handle your responsibilities.</p>
        </div>
        <?php endif; ?>
        <p style="text-align:center"><a href="<?= htmlspecialchars($systemUrl) ?>" class="btn">View Details</a></p>
    </div>
    <div class="footer"><p>HR Management System</p></div>
</div>
</body>
</html>