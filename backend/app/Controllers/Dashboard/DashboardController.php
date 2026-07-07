<?php

declare(strict_types=1);

namespace App\Controllers\Dashboard;

use App\Core\Controller;
use App\Models\Attendance;
use App\Models\Notification;


class DashboardController extends Controller
{
    private Attendance $attendanceModel;
    private Notification $notificationModel;

    public function __construct()
    {
        parent::__construct();
        $this->attendanceModel  = new Attendance();
        $this->notificationModel = new Notification();
    }

    /**
     * Display the main dashboard.
     * GET /dashboard
     */
    public function indexAction(): void
    {
        // Require authentication
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        // Ensure RBAC functions are loaded
        if (!function_exists('hasPermission')) {
            require_once dirname(__DIR__, 3) . '/auth.php';
        }

        $userId = $this->getUserId();
        $conn   = $this->getDbConnection();

        // Auto clock-out missed sessions
        $this->attendanceModel->autoClockOutMissedSessions();

        // ── User info ─────────────────────────────────────────────────────────
        $user = [
            'first_name' => explode(' ', $_SESSION['user_name'] ?? 'User')[0],
            'last_name'  => explode(' ', $_SESSION['user_name'] ?? '')[1] ?? '',
            'role'       => $_SESSION['user_role'] ?? 'guest',
            'id'         => $userId,
        ];

        // ── Employee record ───────────────────────────────────────────────────
        $stmt = $conn->prepare("
            SELECT e.*, d.id AS department_id, s.id AS section_id, ss.id AS subsection_id
            FROM employees e
            LEFT JOIN users       u  ON u.employee_id  = e.employee_id
            LEFT JOIN departments d  ON e.department_id = d.id
            LEFT JOIN sections    s  ON e.section_id    = s.id
            LEFT JOIN subsections ss ON e.subsection_id = ss.id
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $employeeDbId = $employee['id'] ?? null;

        // ── Notifications ─────────────────────────────────────────────────────
        $notifications = $this->notificationModel->getNotifications($userId, 20);
        $unreadCount   = $this->notificationModel->getUnreadCount($userId);

        // ── Attendance summary (from model) ───────────────────────────────────
        $attendanceSummary = $employeeDbId
            ? $this->attendanceModel->getAttendanceSummary((int)$employeeDbId)
            : null;

        // ── HR Manager & Super Admin Stats ────────────────────────────────────
        $userRole = $_SESSION['user_role'] ?? '';
        $hasHrAccess = in_array($userRole, ['hr_manager', 'super_admin']);
        $hrStats = [];

        if ($hasHrAccess) {
            $hrStats = [
                'total_employees'   => (int)$conn->query("SELECT COUNT(*) FROM employees WHERE employee_status = 'active'")->fetch_row()[0],
                'total_departments' => (int)$conn->query("SELECT COUNT(*) FROM departments")->fetch_row()[0],
                'total_sections'    => (int)$conn->query("SELECT COUNT(*) FROM sections")->fetch_row()[0],
                'recent_hires'      => (int)$conn->query("SELECT COUNT(*) FROM employees WHERE hire_date >= CURDATE() - INTERVAL 30 DAY")->fetch_row()[0],
            ];
        }

        // ── Flash messages ────────────────────────────────────────────────────
        $flash = null;
        if (isset($_SESSION['flash_message'])) {
            $flash = [
                'message' => $_SESSION['flash_message'],
                'type'    => $_SESSION['flash_type'] ?? 'info',
            ];
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        }

        // ── Render view ───────────────────────────────────────────────────────
        $this->view('dashboard/index', [
            'user'               => $user,
            'employee'           => $employee,
            'employee_db_id'     => $employeeDbId,
            'notifications'      => $notifications,
            'unread_count'       => $unreadCount,
            'attendance'         => $attendanceSummary,
            'has_hr_access'      => $hasHrAccess,
            'hr_stats'           => $hrStats,
            'flash'              => $flash,
            'csrf_token'         => $this->generateCsrfToken(),
        ]);
    }
}
