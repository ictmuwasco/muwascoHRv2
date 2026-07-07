<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - HR Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= BASE_URL ?>/frontend/assets/js/theme.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">

    <?php require __DIR__ . '/../components/navbar.php'; ?>
    <?php require __DIR__ . '/../components/header_bar.php'; ?>
    
    <div class="lg:pl-64 pt-16 bg-gray-50 min-h-screen">
        <div class="px-4 sm:px-6 lg:px-8 py-8 w-full">

            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8 gap-4">
                <div>
                    <h1 class="text-2xl lg:text-3xl font-bold text-gray-900">
                        Welcome back, <?= htmlspecialchars($user['first_name']) ?>!
                    </h1>
                    <p class="text-gray-600 mt-1 text-xs lg:text-sm">
                        <?= date('l, F j, Y') ?> &middot; <?= htmlspecialchars($user['role']) ?>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-block px-3 py-1.5 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700">
                        <?= htmlspecialchars($user['role']) ?>
                    </span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($flash): ?>
                <div class="mb-6 px-4 py-3 rounded-lg border text-sm
                    <?= $flash['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-700' : '' ?>
                    <?= $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-700' : '' ?>
                    <?= $flash['type'] === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-700' : '' ?>
                    <?= $flash['type'] === 'info' ? 'bg-blue-50 border-blue-200 text-blue-700' : '' ?>">
                    <i class="fas fa-info-circle mr-1"></i>
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Row 1: Attendance, Apply Leave, My Appraisal -->
            <div class="dashboard-cards-row">
                <?php
                $attendance = $attendance;
                require __DIR__ . '/../components/attendance_card.php';
                ?>
                <?php require __DIR__ . '/../components/apply_leave_card.php'; ?>
                <?php require __DIR__ . '/../components/my_appraisal_card.php'; ?>
            </div>

            <!-- Row 2: Statistics Cards -->
            <?php if ($has_hr_access): $stats = $hr_stats; ?>
            <div class="dashboard-cards-row">
                <?php require __DIR__ . '/../components/statistics_cards.php'; ?>
            </div>
            <?php endif; ?>

            <!-- Notifications Widget -->
            <div class="mb-8">
                <?php
                $notifications = $notifications;
                $unread_count  = $unread_count;
                require __DIR__ . '/../components/notification_widget.php';
                ?>
            </div>

            <!-- Charts Section -->
            <div>
                <?php require __DIR__ . '/../components/charts_section.php'; ?>
            </div>

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.alert').forEach(el => {
                setTimeout(() => {
                    el.style.transition = 'opacity .5s';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>