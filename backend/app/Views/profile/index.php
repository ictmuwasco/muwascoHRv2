<?php

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - HR Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen <?= ($_SESSION['theme'] ?? 'light') === 'light' ? 'bg-gray-50 text-gray-900' : 'bg-dark-bg text-white' ?>">

    <!-- Sidebar Navigation -->
    <?php require __DIR__ . '/../components/navbar.php'; ?>

    <!-- Top Header Bar -->
    <?php require __DIR__ . '/../components/header_bar.php'; ?>

    <div class="lg:pl-64 pt-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white">My Profile</h1>
                <p class="text-gray-600 dark:text-gray-400 mt-1">
                    <?= htmlspecialchars($employee['first_name'] ?? '') ?> <?= htmlspecialchars($employee['last_name'] ?? '') ?>
                    - <?= htmlspecialchars($employee['employee_id'] ?? 'N/A') ?>
                </p>
            </div>
            <?php if (!$is_viewing_other): ?>
                <button onclick="document.getElementById('editProfileModal').classList.remove('hidden')"
                        class="btn btn-primary">
                    <i class="fas fa-edit mr-2"></i>Edit Profile
                </button>
            <?php endif; ?>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="px-6 py-4 rounded-2xl mb-6 border-2 backdrop-blur-sm text-white
                        <?= $_SESSION['flash_type'] === 'success' ? 'bg-gradient-to-r from-success to-emerald-600 border-success' : '' ?>
                        <?= $_SESSION['flash_type'] === 'error' ? 'bg-gradient-to-r from-error to-red-600 border-error' : '' ?>">
                <i class="fas fa-info-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['flash_message']) ?>
            </div>
            <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
        <?php endif; ?>

        <!-- Tabs -->
        <?php
        $activeTab = $_GET['tab'] ?? 'personal';
        $tabs = [
            [
                'id' => 'personal',
                'label' => 'Personal Information',
                'active' => $activeTab === 'personal',
                'content' => $this->renderPartial('profile/partials/personal', ['employee' => $employee])
            ],
            [
                'id' => 'employment',
                'label' => 'Employment Details',
                'active' => $activeTab === 'employment',
                'content' => $this->renderPartial('profile/partials/employment', ['employee' => $employee])
            ],
            [
                'id' => 'documents',
                'label' => 'Documents',
                'active' => $activeTab === 'documents',
                'content' => $this->renderPartial('profile/partials/documents', ['documents' => $documents, 'employee' => $employee, 'is_viewing_other' => $is_viewing_other, 'csrf_token' => $csrf_token])
            ],
            [
                'id' => 'next_of_kin',
                'label' => 'Next of Kin',
                'active' => $activeTab === 'next_of_kin',
                'content' => $this->renderPartial('profile/partials/next_of_kin', ['next_of_kin' => $next_of_kin])
            ],
        ];

        // Add password change tab only for own profile
        if (!$is_viewing_other && $current_user):
            $tabs[] = [
                'id' => 'password',
                'label' => 'Change Password',
                'active' => $activeTab === 'password',
                'content' => $this->renderPartial('profile/partials/password', ['current_user' => $current_user, 'csrf_token' => $csrf_token])
            ];
        endif;

        require __DIR__ . '/../components/tabs.php';
        ?>
    </div>

    <!-- Edit Profile Modal -->
    <div id="editProfileModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-gray-200 dark:border-white/10 flex items-center justify-between">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Edit Profile</h3>
                <button onclick="document.getElementById('editProfileModal').classList.add('hidden')"
                        class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6">
                <!-- Edit form will be loaded here -->
                <p class="text-gray-600 dark:text-gray-400">Edit form coming soon...</p>
            </div>
        </div>
    </div>
    </div>
</body>
</html>