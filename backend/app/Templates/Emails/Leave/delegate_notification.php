<?php
/**
 * Delegate Notification - Email Template
 * 
 * Sent to: The employee selected as delegate
 * Variables: $delegateName, $employeeName, $leaveType, $startDate, $endDate, $days, $systemUrl
 */
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;line-height:1.6;color:#333}
.container{max-width:600px;margin:0 auto;padding:20px}
.header{background:#f39c12;color:#fff;padding:20px;text-align:center;border-radius:5px 5px 0 0}
.content{background:#f9f9f9;padding:20px;border:1px solid #ddd}
.footer{text-align:center;padding:15px;font-size:12px;color:#666}
.details{background:#fff;padding:15px;border-radius:5px;margin:15px 0;border:1px solid #e0e0e0}
.label{font-weight:700;color:#555}
.btn{display:inline-block;padding:10px 20px;background:#f39c12;color:#fff;text-decoration:none;border-radius:5px}
</style></head>
<body>
<div class="container">
    <div class="header"><h2>Task Delegation Notification</h2></div>
    <div class="content">
        <p>Dear <strong><?= htmlspecialchars($delegateName) ?></strong>,</p>
        <p>You have been assigned as a <strong>task delegate</strong> during the following leave period:</p>
        <div class="details">
            <p><span class="label">Employee on Leave:</span> <?= htmlspecialchars($employeeName) ?></p>
            <p><span class="label">Leave Type:</span> <?= htmlspecialchars($leaveType) ?></p>
            <p><span class="label">Start Date:</span> <?= htmlspecialchars($startDate) ?></p>
            <p><span class="label">End Date:</span> <?= htmlspecialchars($endDate) ?></p>
            <p><span class="label">Duration:</span> <?= (int)$days ?> day(s)</p>
        </div>
        <p>During this period, you will be responsible for handling tasks and responsibilities that would normally be managed by <strong><?= htmlspecialchars($employeeName) ?></strong>.</p>
        <p style="text-align:center"><a href="<?= htmlspecialchars($systemUrl) ?>" class="btn">View Details</a></p>
    </div>
    <div class="footer"><p>HR Management System</p></div>
</div>
</body>
</html>