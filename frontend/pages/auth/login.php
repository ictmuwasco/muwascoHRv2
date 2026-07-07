<?php

declare(strict_types=1);

/**
 * MUWASCO HR System - Login Page
 * 
 * Modular frontend login page with Tailwind CSS v3
 */

require_once __DIR__ . '/../../../backend/bootstrap.php';

// Apply security headers
\App\Middleware\SecurityMiddleware::applySecurityHeaders();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: /hrdemo/');
    exit();
}

$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$error = $_SESSION['login_error'] ?? '';
$success = $_SESSION['login_success'] ?? '';
$flash = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';

// Clear flash messages
unset($_SESSION['login_error'], $_SESSION['login_success'], $_SESSION['flash_message'], $_SESSION['flash_type']);

// Get rate limit info
$attempts = 0;
$timeLeft = 0;
if (!empty($_POST['email']) && isset($_SESSION['login_attempts_' . md5($_POST['email'] . $_SERVER['REMOTE_ADDR'])])) {
    $att = $_SESSION['login_attempts_' . md5($_POST['email'] . $_SERVER['REMOTE_ADDR'])];
    $attempts = (int) $att['count'];
    $timeLeft = max(0, 900 - (time() - $att['first_attempt']));
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Login - MUWASCO HR System</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="frontend/assets/css/main.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <!-- Header -->
            <div class="text-center mb-6">
                <img src="muwascologo.png" alt="MUWASCO Logo" class="login-logo mx-auto mb-3">
                <h2 class="text-xl font-bold text-white">HR Management System</h2>
                <p class="text-sm text-gray-400">Muranga Water & Sanitation Co. Ltd</p>
                <p class="text-xs text-gray-500 mt-2">Secure Employee Portal</p>
            </div>

            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="alert alert-danger mb-4"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success mb-4"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flashType) ?> mb-4"><?= htmlspecialchars($flash) ?></div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" 
                           class="form-input" 
                           id="email" 
                           name="email" 
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           required 
                           autocomplete="email" 
                           placeholder="Enter your company email">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" 
                           class="form-input" 
                           id="password" 
                           name="password" 
                           required 
                           autocomplete="current-password" 
                           placeholder="Enter your password">
                </div>

                <button type="submit" class="btn btn-primary w-full mt-6">
                    <i class="fas fa-sign-in-alt"></i> Sign In to Your Account
                </button>
            </form>

            <!-- Rate Limit Warning -->
            <?php if ($attempts > 0 && $timeLeft > 0): ?>
                <div class="mt-4 p-3 bg-yellow-900 bg-opacity-20 border border-yellow-600 rounded-lg">
                    <p class="text-sm text-yellow-400">
                        <i class="fas fa-shield-alt"></i> 
                        Login attempts: <?= $attempts ?>/5<br>
                        <i class="fas fa-clock"></i> Resets in: <?= ceil($timeLeft / 60) ?> minute<?= $timeLeft > 60 ? 's' : '' ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Help Text -->
            <div class="text-center mt-4">
                <p class="text-sm text-gray-500">
                    <i class="fas fa-life-ring"></i> Need assistance? Contact HR Support
                </p>
            </div>
        </div>
    </div>

    <script>
        // Auto-focus email field
        document.addEventListener('DOMContentLoaded', function () {
            const emailField = document.getElementById('email');
            if (emailField) emailField.focus();

            // Auto-dismiss alerts after 5 seconds
            document.querySelectorAll('.alert').forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity .5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            // Prevent form re-submission on back/forward
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>