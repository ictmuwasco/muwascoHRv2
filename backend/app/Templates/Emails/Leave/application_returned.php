<?php
/**
 * Leave Returned for Revision - Email Template
 * Variables: $employeeName, $leaveType, $startDate, $endDate, $days, $reason, $delegateName, $systemUrl
 */
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;line-height:1.6;color:#333}
.container{max-width:600px;margin:0 auto;padding:20px}
.header{background:#ff9800;color:#fff;padding:20px;text-align:center;border-radius:5px 5px 0 0}
.content{background:#f9f9f9;padding:20px;border:1px solid #ddd}
.footer{text-align:center;padding:15px;font-size:12px;color:#666}
.details{background:#fff;padding:15px;border-radius:5px;margin:15px 0;border:1px solid #e0e0e0}
.label{font-weight:700;color:#555}
.reason-box{background:#fff3cd;border-left:4px solid #ffc107;padding:12px;margin:15px 0;border-radius:4px}
.btn{display:inline-block;padding:10px 20px;background:#ff9800;color:#fff;text-decoration:none;border-radius:5px}
</style></head>
<body>
<div class="container">
    <div class="header"><h2>Leave Application Returned for Revision</h2></div>
    <div class="content">
        <p>Dear <strong><?= htmlspecialchars($employeeName) ?></strong>,</p>
        <p>Your leave application has been <strong>returned for revision</strong>. Please review and resubmit with the necessary corrections.</p>
        <div class="details">
            <p><span class="label">Leave Type:</span> <?= htmlspecialchars($leaveType) ?></p>
            <p><span class="label">Start Date:</span> <?= htmlspecialchars($startDate) ?></p>
            <p><span class="label">End Date:</span> <?= htmlspecialchars($endDate) ?></p>
            <p><span class="label">Duration:</span> <?= (int)$days ?> day(s)</p>
        </div>
        <?php if (!empty($reason)): ?>
        <div class="reason-box">
            <p><strong>Reason for Return:</strong></p>
            <p><?= htmlspecialchars($reason) ?></p>
        </div>
        <?php endif; ?>
        <p>Please make the necessary corrections and resubmit your application.</p>
        <p style="text-align:center"><a href="<?= htmlspecialchars($systemUrl) ?>" class="btn">Review and Resubmit</a></p>
    </div>
    <div class="footer"><p>HR Management System</p></div>
</div>
</body>
</html>