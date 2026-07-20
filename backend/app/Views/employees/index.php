<?php

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees - HR Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= BASE_URL ?>/frontend/assets/js/theme.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">

    <!-- Sidebar Navigation -->
    <?php require __DIR__ . '/../components/navbar.php'; ?>

    <!-- Top Header Bar -->
    <?php require __DIR__ . '/../components/header_bar.php'; ?>

    <div class="lg:pl-64 pt-16">
    <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Employees</h1>
                <p class="text-gray-600 mt-1">Manage employee records</p>
            </div>
            <a href="<?= BASE_URL ?>/?route=employees/create" class="btn btn-primary">
                <i class="fas fa-plus mr-2"></i>Add Employee
            </a>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="px-6 py-4 rounded-2xl mb-6 border-2 backdrop-blur-sm
                        <?= $_SESSION['flash_type'] === 'success' ? 'bg-gradient-to-r from-success to-emerald-600 text-white border-success' : '' ?>
                        <?= $_SESSION['flash_type'] === 'error' ? 'bg-gradient-to-r from-error to-red-600 text-white border-error' : '' ?>">
                <i class="fas fa-info-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['flash_message']) ?>
            </div>
            <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
        <?php endif; ?>

        <!-- Search Bar (replaces the old search tab) -->
        <div class="mb-6">
            <form method="GET" action="<?= BASE_URL ?>/?route=employees" class="flex items-center gap-3 bg-white rounded-lg border border-gray-200 px-4 py-3 shadow-sm">
                <i class="fas fa-search text-gray-400"></i>
                <input type="hidden" name="route" value="employees">
                <input type="text" 
                       name="search" 
                       class="flex-1 border-0 outline-none text-sm bg-transparent" 
                       placeholder="Search by first, middle or surname..."
                       value="<?= htmlspecialchars($search ?? '') ?>">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if (!empty($search)): ?>
                <a href="<?= BASE_URL ?>/?route=employees" class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i> Clear
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tabs (list only - search tab removed) -->
        <?php
        $activeTab = $_GET['tab'] ?? 'list';
        $tabs = [
            [
                'id' => 'list',
                'label' => 'All Employees',
                'active' => $activeTab === 'list',
                'content' => $this->renderPartial('employees/partials/list', [
                    'employees' => $employees,
                    'page' => $page,
                    'totalPages' => $totalPages,
                    'total' => $total,
                    'search' => $search,
                    'department_filter' => $department_filter,
                    'section_filter' => $section_filter,
                    'type_filter' => $type_filter,
                    'status_filter' => $status_filter,
                    'csrf_token' => $csrf_token,
                ])
            ],
        ];
        require __DIR__ . '/../components/tabs.php';
        ?>
    </div>
    </div>
</body>
</html>