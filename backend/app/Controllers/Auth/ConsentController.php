<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Controller;
use App\Models\Consent;


class ConsentController extends Controller
{
    private Consent $consentModel;

    public function __construct()
    {
        parent::__construct();
        $this->consentModel = new Consent();
    }

    /**
     * Display the consent form.
     * GET /consent
     */
    public function indexAction(): void
    {
        // Must have pending auth from login
        if (!$this->hasPendingAuth()) {
            $_SESSION['flash_message'] = 'Session expired. Please login again.';
            $_SESSION['flash_type']    = 'warning';
            $this->redirect('login.php');
            return;
        }

        // Refresh pending auth time
        $_SESSION['pending_auth_time'] = time();

        $this->view('auth/consent/index', [
            'csrf_token'  => $this->generateCsrfToken(),
            'pending_name' => $_SESSION['pending_user_name'] ?? 'User',
            'error'       => $_SESSION['flash_error'] ?? '',
        ]);

        unset($_SESSION['flash_error']);
    }

    /**
     * Process consent form submission.
     * POST /consent/submit
     */
    public function submitAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('consent');
            return;
        }

        if (!$this->hasPendingAuth()) {
            $this->setFlash('Session expired. Please login again.', 'warning');
            $this->redirect('login.php');
            return;
        }

        // CSRF check
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Security token invalid. Please refresh and try again.';
            $this->redirect('consent');
            return;
        }

        $pendingUserId = (int)$_SESSION['pending_user_id'];
        $fullName      = trim($_POST['full_name'] ?? '');
        $nationalId    = trim($_POST['national_id'] ?? '');
        $accepted      = isset($_POST['consent_accepted']) && $_POST['consent_accepted'] === '1';

        if (empty($fullName) || strlen($fullName) < 3) {
            $_SESSION['flash_error'] = 'Please enter your full name (min 3 characters).';
            $this->redirect('consent');
            return;
        }

        $cleanedNid = preg_replace('/[^0-9]/', '', $nationalId);
        if (strlen($cleanedNid) < 5) {
            $_SESSION['flash_error'] = 'Please enter a valid National ID (min 5 digits).';
            $this->redirect('consent');
            return;
        }

        if (!$accepted) {
            $_SESSION['flash_error'] = 'You must accept the data protection terms to continue.';
            $this->redirect('consent');
            return;
        }

        $verification = $this->consentModel->verifyNationalId($pendingUserId, $cleanedNid);

        if (!$verification['success']) {
            $_SESSION['flash_error'] = $verification['error'];
            $this->logSecurityEvent('national_id_verification_failed', $pendingUserId, [
                'error' => $verification['error'],
            ]);
            $this->redirect('consent');
            return;
        }

        // ── Save consent ──────────────────────────────────────────────────────
        $saved = $this->consentModel->saveConsent($pendingUserId, $fullName, $cleanedNid);

        if (!$saved) {
            $_SESSION['flash_error'] = 'Failed to save consent. Please try again.';
            $this->redirect('consent');
            return;
        }

        $this->logSecurityEvent('consent_provided_with_verification', $pendingUserId, [
            'full_name'        => $fullName,
            'national_id_hash' => hash('sha256', $cleanedNid),
            'employee_id'      => $verification['employee_id'],
        ]);

        // ── Complete login ────────────────────────────────────────────────────
        $sessionToken = bin2hex(random_bytes(32));
        $loginId      = bin2hex(random_bytes(32));

        $conn = $this->getDbConnection();
        $stmt = $conn->prepare(
            "UPDATE users SET session_token = ?, login_identifier = ?, last_activity = NOW() WHERE id = ?"
        );
        $stmt->bind_param("ssi", $sessionToken, $loginId, $pendingUserId);
        $stmt->execute();
        $stmt->close();

        // Generate JWT tokens for the user
        $userData = [
            'id'    => $pendingUserId,
            'email' => $_SESSION['pending_user_email'],
            'role'  => $_SESSION['pending_user_role'],
        ];
        $this->generateJwtTokens($userData);

        // Transfer pending → active session
        $_SESSION['user_id']     = $_SESSION['pending_user_id'];
        $_SESSION['user_email']  = $_SESSION['pending_user_email'];
        $_SESSION['user_name']   = $_SESSION['pending_user_name'];
        $_SESSION['user_role']   = $_SESSION['pending_user_role'];
        $_SESSION['designation'] = $_SESSION['pending_designation'];

        $_SESSION['national_id_verified'] = true;
        $_SESSION['employee_id']   = $verification['employee_id'];
        $_SESSION['session_token'] = $sessionToken;
        $_SESSION['login_time']    = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['ip_address']    = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['session_valid'] = true;

        // Backward compat
        $_SESSION['hr_system_user_id']     = $_SESSION['user_id'];
        $_SESSION['hr_system_username']    = $_SESSION['user_name'];
        $_SESSION['hr_system_user_role']   = $_SESSION['user_role'];

        // Clear pending
        unset(
            $_SESSION['pending_user_id'], $_SESSION['pending_user_email'],
            $_SESSION['pending_user_name'], $_SESSION['pending_user_role'],
            $_SESSION['pending_designation'], $_SESSION['pending_auth_time']
        );

        $this->logSecurityEvent('login_success_after_verified_consent', (int)$_SESSION['user_id']);
        $this->trackAction('LOGIN_SUCCESS', 'User logged in after consent', [
            'user_id' => $_SESSION['user_id'],
        ]);

        $this->redirect('dashboard.php');
    }

    /**
     * Check if user has a valid pending authentication session.
     */
    private function hasPendingAuth(): bool
    {
        if (empty($_SESSION['pending_user_id']) || empty($_SESSION['pending_auth_time'])) {
            return false;
        }

        // Pending auth expires after 10 minutes
        if (time() - $_SESSION['pending_auth_time'] > 600) {
            unset(
                $_SESSION['pending_user_id'], $_SESSION['pending_user_email'],
                $_SESSION['pending_user_name'], $_SESSION['pending_user_role'],
                $_SESSION['pending_designation'], $_SESSION['pending_auth_time']
            );
            return false;
        }

        return true;
    }

    /**
     * Set a flash message for the next request.
     */
    private function setFlash(string $message, string $type = 'info'): void
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type']    = $type;
    }
}