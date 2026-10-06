<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Notification\NotificationDispatcher;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Notification Service - Handles all system notifications.
 * 
 * Supports in-app notifications, email, and is extensible for SMS/push.
 * Templates are stored separately from business logic.
 */
class NotificationService
{
    private static ?NotificationService $instance = null;

    private function __construct() {}

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send an in-app notification using the live notifications schema.
     * `action_url` replaced the legacy `link` column; keeping this mapping in
     * one place prevents appraisal notifications from failing at runtime.
     *
     * EMAIL MIRROR: every bell written here is ALSO queued for email delivery
     * (notification_logs -> cron/notification_worker.php -> SMTP). The mirror
     * is a queue row, never an inline SMTP call, so this stays a fast local
     * INSERT inside whatever business request triggered it. Failures to queue
     * are logged and swallowed: a missed email is an annoyance, a failed
     * business operation is not.
     *
     * $category exists because one historical caller (DelegateService) wrote
     * category 'leave' via raw SQL; routing it through here keeps that
     * categorisation without forgoing the mirror.
     */
    public function sendInApp(
        int $userId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $link = null,
        string $category = 'general'
    ): int {
        $db = \db();
        $id = $db->insert('notifications', [
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'category' => $category,
            'action_url' => $link,
            'is_read' => 0,
            'is_sent' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->mirrorToEmail($userId, (int) $id, $title, $message, $type, $link);

        return $id;
    }

    /**
     * Queue the email twin of one in-app notification.
     *
     * The dedupe key is derived from the bell's own id, so the same
     * notification can never be mailed twice (the unique (user_id, dedupe_key)
     * index on notification_logs enforces it), while two different bells for
     * the same person each get their own email.
     *
     * Type `in_app_mirror` keeps these rows distinguishable from native event
     * notifications in the delivery log and in worker --type filters.
     */
    private function mirrorToEmail(int $userId, int $notificationId, string $title, string $message, string $type, ?string $link): void
    {
        if ($notificationId <= 0) {
            return;
        }

        try {
            $dispatcher = new NotificationDispatcher();
            // Resolves the ACCOUNT email (users.email, active only). Null when
            // the id is not a user - legacy call sites historically passed an
            // employees.id - in which case dispatch() records a 'skipped' row
            // with the reason instead of failing the caller.
            $recipient = $dispatcher->resolveRecipientByUserId($userId);

            $dispatcher->dispatch(
                $userId,
                $recipient['email'] ?? null,
                $recipient['phone'] ?? null,
                'in_app_mirror',
                [NotificationDispatcher::CHANNEL_EMAIL],
                'inapp:' . $notificationId,
                [
                    'title' => $title,
                    'body'  => $message,
                    'link'  => $link,
                    'type'  => $type,
                ],
                $recipient['employee_id'] ?? null
            );
        } catch (\Throwable $e) {
            \logger()->warning('In-app notification email mirror could not be queued', [
                'user_id'        => $userId,
                'notification_id' => $notificationId,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an email notification.
     */
    public function sendEmail(string $to, string $subject, string $body, bool $isHtml = true): bool
    {
        try {
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = \env('MAIL_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth = true;
            $mail->Username = \env('MAIL_USERNAME', '');
            $mail->Password = \env('MAIL_PASSWORD', '');
            $mail->SMTPSecure = \env('MAIL_ENCRYPTION', 'tls');
            $mail->Port = (int) \env('MAIL_PORT', 587);

            // Bound the SMTP conversation. PHPMailer's own default is 300s, and
            // PHP CLI runs with max_execution_time=0 (unlimited), so a stalling
            // mail host would pin a worker process indefinitely. That matters
            // far beyond one message: the scheduled task uses an
            // IgnoreNew instance policy, so one stuck worker makes every later
            // run be refused and the whole notification queue stops draining.
            $mail->Timeout = (int) \env('MAIL_TIMEOUT', 20);
            $mail->SMTPKeepAlive = false;

            // Sender and recipient
            $mail->setFrom(\env('MAIL_FROM_ADDRESS', 'noreply@muwasco.co.ke'), \env('MAIL_FROM_NAME', 'MUWASCO HR System'));
            $mail->addAddress($to);

            // Content
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            
            \logger()->info('Email sent successfully', ['to' => $to, 'subject' => $subject]);
            return true;
        } catch (Exception $e) {
            \logger()->error('Email sending failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $mail->ErrorInfo ?? $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send a notification using a template.
     */
    public function sendFromTemplate(string $template, array $data, int $userId, string $email = ''): void
    {
        $templates = $this->getTemplates();
        
        if (!isset($templates[$template])) {
            \logger()->error('Notification template not found', ['template' => $template]);
            return;
        }

        $templateData = $templates[$template];
        $title = $this->parseTemplate($templateData['title'], $data);
        $message = $this->parseTemplate($templateData['message'], $data);
        $type = $templateData['type'] ?? 'info';

        // In-app bell, mirrored to email BY sendInApp() itself. The opt-in
        // send_email template flag that used to gate a second, explicit
        // sendEmail() call here is obsolete: the organisation rule is now that
        // EVERY in-house notification also goes out by email, and keeping both
        // paths would mail template recipients twice. $email stays in the
        // signature for callers that still pass it.
        $this->sendInApp($userId, $title, $message, $type, $data['link'] ?? null);
    }

    /**
     * Get unread notifications for a user.
     */
    public function getUnreadNotifications(int $userId, int $limit = 20): array
    {
        $db = \db();
        return $db->fetchAll(
            "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT ?",
            'ii',
            [$userId, $limit]
        );
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(int $notificationId, int $userId): void
    {
        $db = \db();
        $db->update(
            'notifications',
            ['is_read' => 1],
            'id = ? AND user_id = ?',
            'ii',
            [$notificationId, $userId]
        );
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead(int $userId): void
    {
        $db = \db();
        $db->update(
            'notifications',
            ['is_read' => 1],
            'user_id = ? AND is_read = 0',
            'i',
            [$userId]
        );
    }

    /**
     * Get unread notification count for a user.
     */
    public function getUnreadCount(int $userId): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
            'i',
            [$userId]
        );
    }

    /**
     * Send leave request notification.
     */
    public function notifyLeaveRequest(int $employeeId, string $employeeName, string $leaveType, string $startDate, string $endDate): void
    {
        $db = \db();
        
        // Notify HR managers
        $hrManagers = $db->fetchAll(
            "SELECT id, email FROM users WHERE role IN ('hr_manager', 'super_admin') AND is_active = 1"
        );

        $title = "New Leave Request";
        $message = "{$employeeName} has submitted a {$leaveType} leave request from {$startDate} to {$endDate}.";
        $link = "leave_management.php";

        foreach ($hrManagers as $manager) {
            // The explicit sendEmail() that used to sit beside this call is
            // gone: sendInApp() queues the email mirror itself now, and
            // keeping both would mail every HR manager twice per request.
            $this->sendInApp((int) $manager['id'], $title, $message, 'info', $link);
        }
    }

    /**
     * Send leave approval notification.
     */
    public function notifyLeaveApproval(int $employeeId, string $employeeName, string $status): void
    {
        $title = "Leave Request {$status}";
        $message = "Your leave request has been {$status}.";
        $link = "leave_management.php";

        $this->sendInApp($employeeId, $title, $message, $status === 'approved' ? 'success' : 'warning', $link);
    }

    /**
     * Send complaint notification.
     */
    public function notifyComplaintUpdate(int $userId, string $complaintId, string $status): void
    {
        $title = "Complaint Update";
        $message = "Your complaint #{$complaintId} has been updated to: {$status}.";
        $link = "complaints.php";

        $this->sendInApp($userId, $title, $message, 'info', $link);
    }

    /**
     * Send payroll notification.
     */
    public function notifyPayrollRelease(int $employeeId, string $period): void
    {
        $title = "Payroll Released";
        $message = "Your salary for {$period} has been processed and is ready for viewing.";
        $link = "payroll.php";

        $this->sendInApp($employeeId, $title, $message, 'success', $link);
    }

    /**
     * Send password reset notification.
     */
    public function notifyPasswordReset(string $email, string $resetLink): void
    {
        $subject = "Password Reset Request - MUWASCO HR System";
        $body = "
            <h2>Password Reset Request</h2>
            <p>You have requested to reset your password for the MUWASCO HR System.</p>
            <p>Click the link below to reset your password:</p>
            <p><a href='{$resetLink}'>Reset Password</a></p>
            <p>This link will expire in 1 hour.</p>
            <p>If you did not request this, please ignore this email.</p>
        ";

        $this->sendEmail($email, $subject, $body);
    }

    /**
     * Send attendance alert notification.
     */
    public function notifyAttendanceAlert(int $employeeId, string $alertType): void
    {
        $alerts = [
            'late' => ['title' => 'Late Clock-In', 'message' => 'You have clocked in late today.'],
            'missing_clockout' => ['title' => 'Missing Clock-Out', 'message' => 'You forgot to clock out yesterday.'],
            'anomaly' => ['title' => 'Attendance Anomaly', 'message' => 'Unusual attendance pattern detected.'],
        ];

        $alert = $alerts[$alertType] ?? ['title' => 'Attendance Alert', 'message' => 'Attendance alert.'];
        $this->sendInApp($employeeId, $alert['title'], $alert['message'], 'warning', 'attendance.php');
    }

    /**
     * Get all available notification templates.
     */
    private function getTemplates(): array
    {
        return [
            'leave_request' => [
                'title' => 'New Leave Request',
                'message' => '{{employee_name}} has submitted a {{leave_type}} leave request from {{start_date}} to {{end_date}}.',
                'type' => 'info',
                'send_email' => true,
            ],
            'leave_approved' => [
                'title' => 'Leave Approved',
                'message' => 'Your {{leave_type}} leave request from {{start_date}} to {{end_date}} has been approved.',
                'type' => 'success',
                'send_email' => true,
            ],
            'leave_rejected' => [
                'title' => 'Leave Rejected',
                'message' => 'Your {{leave_type}} leave request from {{start_date}} to {{end_date}} has been rejected.',
                'type' => 'error',
                'send_email' => true,
            ],
            'complaint_submitted' => [
                'title' => 'Complaint Submitted',
                'message' => 'Your complaint has been submitted successfully. Reference: #{{complaint_id}}.',
                'type' => 'info',
                'send_email' => false,
            ],
            'complaint_resolved' => [
                'title' => 'Complaint Resolved',
                'message' => 'Your complaint #{{complaint_id}} has been resolved.',
                'type' => 'success',
                'send_email' => true,
            ],
            'payroll_released' => [
                'title' => 'Payroll Released',
                'message' => 'Your salary for {{period}} has been processed.',
                'type' => 'success',
                'send_email' => true,
            ],
            'password_reset' => [
                'title' => 'Password Reset',
                'message' => 'Click the link to reset your password: {{reset_link}}',
                'type' => 'info',
                'send_email' => true,
            ],
            'attendance_reminder' => [
                'title' => 'Attendance Reminder',
                'message' => 'Please remember to clock in/out for today.',
                'type' => 'warning',
                'send_email' => false,
            ],
            'appraisal_due' => [
                'title' => 'Performance Appraisal Due',
                'message' => 'Your performance appraisal for {{period}} is due. Please complete it by {{due_date}}.',
                'type' => 'warning',
                'send_email' => true,
            ],
        ];
    }

    /**
     * Parse template placeholders with actual data.
     */
    private function parseTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
        return $template;
    }

    /**
     * Render a full email template.
     */
    private function renderEmailTemplate(string $template, array $data): string
    {
        $message = $this->getTemplates()[$template]['message'] ?? '';
        $message = $this->parseTemplate($message, $data);

        return "
            <!DOCTYPE html>
            <html>
            <head><meta charset='UTF-8'></head>
            <body style='font-family: Arial, sans-serif; padding: 20px;'>
                <div style='max-width: 600px; margin: 0 auto; background: #f9f9f9; border-radius: 8px; padding: 20px;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #1a56db;'>MUWASCO HR System</h2>
                    </div>
                    <div style='background: white; padding: 20px; border-radius: 8px;'>
                        <p>{$message}</p>
                    </div>
                    <div style='text-align: center; margin-top: 20px; color: #666; font-size: 12px;'>
                        <p>Murang'a Water and Sanitation Company Ltd</p>
                        <p>This is an automated message. Please do not reply.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
    }

    private function __clone(): void {}
    public function __wakeup(): void
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}