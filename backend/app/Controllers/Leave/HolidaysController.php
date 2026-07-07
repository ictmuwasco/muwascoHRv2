<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Core\Controller;
use App\Services\AuditService;

class HolidaysController extends Controller
{
    private AuditService $audit;

    public function __construct()
    {
        parent::__construct();
        $this->audit = AuditService::getInstance();
    }

    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        $role = $_SESSION['user_role'] ?? 'guest';
        if (!in_array($role, ['hr_manager', 'managing_director', 'super_admin'])) {
            $this->redirect('leave/apply');
            return;
        }

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        $success = '';
        $error = '';

        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';

            if ($action === 'add_holiday') {
                $name = $this->sanitize($_POST['name'] ?? '');
                $date = $_POST['date'] ?? '';
                $description = $this->sanitize($_POST['description'] ?? '');
                $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;

                if ($name && $date) {
                    $stmt = $conn->prepare("INSERT INTO holidays (name, date, description, is_recurring) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("sssi", $name, $date, $description, $isRecurring);
                    if ($stmt->execute()) {
                        $holidayId = $stmt->insert_id;
                        $this->audit->logCreate('holidays', $holidayId, ['name' => $name, 'date' => $date], "Holiday '{$name}' added");
                        $success = "Holiday added successfully!";
                    } else {
                        $error = "Error adding holiday.";
                    }
                } else {
                    $error = "Name and date are required.";
                }
            }
        }

        // Handle GET delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete_holiday' && isset($_GET['id'])) {
            $holidayId = (int)$_GET['id'];
            $stmt = $conn->prepare("SELECT name FROM holidays WHERE id = ?");
            $stmt->bind_param("i", $holidayId);
            $stmt->execute();
            $holiday = $stmt->get_result()->fetch_assoc();

            if ($holiday) {
                $delStmt = $conn->prepare("DELETE FROM holidays WHERE id = ?");
                $delStmt->bind_param("i", $holidayId);
                if ($delStmt->execute()) {
                    $this->audit->logDelete('holidays', $holidayId, $holiday, "Holiday '{$holiday['name']}' deleted");
                    $success = "Holiday deleted successfully!";
                } else {
                    $error = "Error deleting holiday.";
                }
            } else {
                $error = "Holiday not found.";
            }
        }

        // Fetch holidays
        $holidays = $conn->query("SELECT * FROM holidays ORDER BY date DESC")->fetch_all(MYSQLI_ASSOC) ?: [];

        $this->view('leave/holidays', [
            'holidays' => $holidays,
            'success' => $success,
            'error' => $error,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    private function sanitize(string $v, int $max = 500): string
    {
        $v = trim(strip_tags($v));
        return strlen($v) > $max ? substr($v, 0, $max) : $v;
    }
}