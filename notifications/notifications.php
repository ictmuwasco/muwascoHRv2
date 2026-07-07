<?php
/**
 * notifications/notifications.php
 * Unified API endpoint for all notification operations
 */

// Turn off output buffering and clear any existing output
while (ob_get_level()) ob_end_clean();

// Set headers first - this must be before ANY output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Start output buffering to catch any unexpected output
ob_start();

// Error handler to convert errors to exceptions
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // Start session after headers
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check authentication
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized - No user session');
    }

    // Include required files with absolute paths
    $basePath = dirname(__DIR__); // Go up one level from notifications folder
    $configPath = $basePath . '/config.php';
    $servicePath = __DIR__ . '/NotificationService.php'; // Use __DIR__ for current directory
    
    if (!file_exists($configPath)) {
        throw new Exception('config.php not found at: ' . $configPath);
    }
    if (!file_exists($servicePath)) {
        throw new Exception('NotificationService.php not found at: ' . $servicePath);
    }
    
    require_once $configPath;
    require_once $servicePath;
    
    // Check if connection exists
    if (!isset($conn) || !($conn instanceof mysqli)) {
        // Try to establish connection if not already done
        if (function_exists('getConnection')) {
            $conn = getConnection();
        } else {
            // Fallback connection logic
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($conn->connect_error) {
                throw new Exception('Database connection failed: ' . $conn->connect_error);
            }
        }
        
        if (!$conn) {
            throw new Exception('Database connection could not be established');
        }
    }
    
    $notificationService = new NotificationService($conn);
    $userId = (int)$_SESSION['user_id'];
    
    // Get action from GET or POST
    $action = $_GET['action'] ?? $_POST['action'] ?? 'get_notifications';
    
    // Clear the output buffer before sending JSON
    ob_clean();
    
    switch ($action) {
        
        // ── Fetch notification list ──────────────────────────────────────
        case 'get_notifications':
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 20;
            $offset = (int)($_GET['offset'] ?? 0);
            $unreadOnly = isset($_GET['unread_only']) && ($_GET['unread_only'] === '1' || $_GET['unread_only'] === 'true');
            
            // Get notifications from service - FIXED METHOD NAME
            $notifications = $notificationService->getNotifications($userId, $limit, $offset, $unreadOnly);
            $unreadCount = $notificationService->getUnreadCount($userId);
            
            // Add computed time_ago field for display
            foreach ($notifications as &$n) {
                $diff = time() - strtotime($n['created_at']);
                
                if ($diff < 60) {
                    $n['time_ago'] = 'Just now';
                } elseif ($diff < 3600) {
                    $n['time_ago'] = floor($diff / 60) . 'm ago';
                } elseif ($diff < 86400) {
                    $n['time_ago'] = floor($diff / 3600) . 'h ago';
                } elseif ($diff < 604800) {
                    $n['time_ago'] = floor($diff / 86400) . 'd ago';
                } else {
                    $n['time_ago'] = date('M j', strtotime($n['created_at']));
                }
                
                // Ensure boolean type
                $n['is_read'] = (bool)$n['is_read'];
            }
            unset($n);
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'unreadCount' => $unreadCount,
                'total' => count($notifications)
            ]);
            break;
        
        // ── Count only (lightweight poll) ───────────────────────────────
        case 'count':
        case 'get_count':
            $unreadCount = $notificationService->getUnreadCount($userId);
            
            echo json_encode([
                'success' => true,
                'unreadCount' => $unreadCount,
                'hasUnread' => $unreadCount > 0
            ]);
            break;
        
        // ── Mark single notification as read ─────────────────────────────
        case 'mark_read':
        case 'mark_as_read':
            $notificationId = (int)($_POST['notification_id'] ?? $_GET['notification_id'] ?? 0);
            
            if ($notificationId > 0) {
                $success = $notificationService->markAsRead($notificationId, $userId);
                $unreadCount = $notificationService->getUnreadCount($userId);
                
                echo json_encode([
                    'success' => $success,
                    'unreadCount' => $unreadCount
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid notification ID'
                ]);
            }
            break;
        
        // ── Mark all notifications as read ────────────────────────────────
        case 'mark_all_read':
            $marked = $notificationService->markAllAsRead($userId);
            
            echo json_encode([
                'success' => true,
                'marked' => $marked,
                'unreadCount' => 0
            ]);
            break;
        
        // ── Get pending tasks (for dashboard widget) ─────────────────────
        case 'get_pending_tasks':
            // Get user's employee details for pending tasks
            $userEmployeeQuery = "SELECT e.*, d.id as department_id, s.id as section_id, ss.id as subsection_id 
                                  FROM employees e 
                                  LEFT JOIN users u ON u.employee_id = e.employee_id 
                                  LEFT JOIN departments d ON e.department_id = d.id
                                  LEFT JOIN sections s ON e.section_id = s.id
                                  LEFT JOIN subsections ss ON e.subsection_id = ss.id
                                  WHERE u.id = ?";
            $stmt = $conn->prepare($userEmployeeQuery);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userEmployee = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $pendingTasks = $notificationService->getDashboardPendingTasks(
                $userId,
                $_SESSION['user_role'] ?? 'guest',
                $userEmployee['id'] ?? null,
                $userEmployee['department_id'] ?? null,
                $userEmployee['section_id'] ?? null,
                $userEmployee['subsection_id'] ?? null
            );
            
            echo json_encode([
                'success' => true,
                'pending_tasks' => $pendingTasks,
                'total_count' => $pendingTasks['total_count'],
                'approval_count' => $pendingTasks['approval_count'],
                'my_requests_count' => $pendingTasks['my_requests_count']
            ]);
            break;
        
        // ── Invalid action handler ───────────────────────────────────────
        default:
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action: ' . htmlspecialchars($action)
            ]);
            break;
    }
    
} catch (Exception $e) {
    // Clear any output that might have been generated
    ob_clean();
    
    // Log the error
    error_log("Notification API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Return JSON error response
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
} finally {
    // Restore error handler
    restore_error_handler();
    
    // End output buffering and send the response
    ob_end_flush();
}
?>