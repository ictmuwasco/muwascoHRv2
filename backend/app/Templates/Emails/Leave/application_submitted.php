<?php
/**
 * Leave Application Submitted - Email Template
 * 
 * Sent to: Employee who applied
 * Variables: $employeeName, $leaveType, $startDate, $endDate, $days, $reason, $delegateName, $delegatePosition, $systemUrl
 */
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;line-height:1.6;color:#333}
.container{max-width:600px;margin:0 auto;padding:20px}
.header{background:#2c3e50;color:#fff;padding:20px;text-align:center;border-radius:5px 5px 0 0}
.content{background:#f9f9f9;padding:20px;border:1px solid #ddd}
.footer{text-align:center;padding:15px;font-size:12px;color:#666;background:#f0f0f0;border-radius:0 0 5px 5px}
.details{background:#fff;padding:15px;border-radius:5px;margin:15px 0;border:1px solid #e0e0e0}
.details p{margin:8px 0}
.label{font-weight:700;color:#555}
.delegate-info{background:#e8f4fd;border-left:4px solid #3498db;padding:12px;margin:15px 0;border-radius:4px}
.btn{display:inline-block;padding:10px 20px;background:#3498db;color:#fff;text-decoration:none;border-radius:5px;margin:10px 0}
</style></head>
<body>
<div class="container">
    <div class="header"><h2>Leave Application Submitted</h2></div>
    <div class="content">
        <p>Dear <strong><?= htmlspecialchars($employeeName) ?></strong>,</p>
        <p>Your leave application has been submitted successfully.</p>
        <div class="details">
            <p><span class="label">Leave Type:</span> <?= htmlspecialchars($leaveType) ?></p>
            <p><span class="label">Start Date:</span> <?= htmlspecialchars($startDate) ?></p>
            <p><span class="label">End Date:</span> <?= htmlspecialchars($endDate) ?></p>
            <p><span class="label">Duration:</span> <?= (int)$days ?> day(s)</p>
            <?php if (!empty($reason)): ?>
            <p><span class="label">Reason:</span> <?= htmlspecialchars($reason) ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($delegateName)): ?>
        <div class="delegate-info">
            <p><strong>Task Delegation:</strong></p>
            <p>During your absence, <strong><?= htmlspecialchars($delegateName) ?></strong> 
            <?= !empty($delegatePosition) ? '(' . htmlspecialchars($delegatePosition) . ')' : '' ?> 
            will handle your responsibilities.</p>
        </div>
        <?php endif; ?>
        <p style="text-align:center"><a href="<?= htmlspecialchars($systemUrl) ?>" class="btn">View Application</a></p>
        <p>You will be notified when your application is reviewed.</p>
    </div>
    <div class="footer">
        <p>This is an automated message from the HR Management System.</p>
    </div>
</div>
</body>
</html>