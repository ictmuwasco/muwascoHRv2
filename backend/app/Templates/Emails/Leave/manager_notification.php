<?php
/**
 * Manager Notification - Email Template
 * Variables: $managerName, $employeeName, $leaveType, $startDate, $endDate, $days, $delegateName, $delegatePosition, $systemUrl
 */
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;line-height:1.6;color:#333}
.container{max-width:600px;margin:0 auto;padding:20px}
.header{background:#9b59b6;color:#fff;padding:20px;text-align:center;border-radius:5px 5px 0 0}
.content{background:#f9f9f9;padding:20px;border:1px solid #ddd}
.footer{text-align:center;padding:15px;font-size:12px;color:#666}
.details{background:#fff;padding:15px;border-radius:5px;margin:15px 0;border:1px solid #e0e0e0}
.label{font-weight:700;color:#555}
.delegate-box{background:#e8f4fd;border-left:4px solid #3498db;padding:12px;margin:15px 0;border-radius:4px}
.btn{display:inline-block;padding:10px 20px;background:#9b59b6;color:#fff;text-decoration:none;border-radius:5px}
</style></head>
<body>
<div class="container">
    <div class="header"><h2>Leave Application Review Required</h2></div>
    <div class="content">
        <p>Dear <strong><?= htmlspecialchars($managerName) ?></strong>,</p>
        <p>A leave application requires your approval. The employee has nominated a delegate to handle their responsibilities during their absence.</p>
        <div class="details">
            <p><span class="label">Employee:</span> <?= htmlspecialchars($employeeName) ?></p>
            <p><span class="label">Leave Type:</span> <?= htmlspecialchars($leaveType) ?></p>
            <p><span class="label">Start Date:</span> <?= htmlspecialchars($startDate) ?></p>
            <p><span class="label">End Date:</span> <?= htmlspecialchars($endDate) ?></p>
            <p><span class="label">Duration:</span> <?= (int)$days ?> day(s)</p>
        </div>
        <?php if (!empty($delegateName)): ?>
        <div class="delegate-box">
            <p><strong>Task Delegation:</strong></p>
            <p><strong>Delegate:</strong> <?= htmlspecialchars($delegateName) ?></p>
            <?php if (!empty($delegatePosition)): ?>
            <p><strong>Position:</strong> <?= htmlspecialchars($delegatePosition) ?></p>
            <?php endif; ?>
            <p>This employee will handle the applicant's responsibilities during the leave period.</p>
        </div>
        <?php endif; ?>
        <p style="text-align:center"><a href="<?= htmlspecialchars($systemUrl) ?>" class="btn">Review Application</a></p>
    </div>
    <div class="footer"><p>HR Management System</p></div>
</div>
</body>
</html>