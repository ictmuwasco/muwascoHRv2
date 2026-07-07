<?php

// Ensure Auth class is loaded
if (!class_exists('App\Helpers\Auth')) {
    require_once dirname(__DIR__, 2) . '/Helpers/Auth.php';
}
$auth = \App\Helpers\Auth::getInstance();
?>
<!-- Sidebar Navigation -->
<div class="fixed left-0 top-0 h-full w-64 bg-white dark:bg-dark-secondary/95 backdrop-blur-xl border-r border-gray-200 dark:border-white/10 z-40 flex flex-col transition-all duration-300 lg:translate-x-0 -translate-x-full" role="navigation">
    <!-- Sidebar Header -->
    <div class="p-4 lg:p-6 border-b border-gray-200 dark:border-white/10 flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center p-1.5" style="background: linear-gradient(135deg, rgba(0,212,255,0.15), rgba(108,92,231,0.15)); border: 1px solid rgba(255,255,255,0.08);">
                <img src="<?= BASE_URL ?>/frontend/assets/images/muwascologo.png" alt="MUWASCO Logo" class="w-full h-full object-contain rounded-lg" loading="lazy">
            </div>
            <div>
                <h2 class="text-gray-900 dark:text-white font-bold text-sm">MUWASCO</h2>
                <p class="text-gray-500 dark:text-gray-400 text-xs">HR System</p>
            </div>
        </div>
    </div>

    <!-- Navigation Links -->
    <nav class="flex-1 overflow-y-auto p-3 lg:p-4">
        <ul class="space-y-1">
            <!-- Dashboard - All authenticated users -->
            <li>
                <a href="<?= BASE_URL ?>/?route=dashboard" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium transition-all duration-300 <?= str_contains($_SERVER['REQUEST_URI'], '/dashboard') ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white hover:border hover:border-gray-300 dark:hover:border-white/10' ?>">
                    <i class="fas fa-tachometer-alt w-5 text-center flex-shrink-0"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- Employees - Super Admin, HR Manager -->
            <?php if ($auth->isSuperAdmin() || $auth->isHRManager()): ?>
            <li>
                <a href="<?= BASE_URL ?>/?route=employees" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium transition-all duration-300 <?= str_contains($_SERVER['REQUEST_URI'], '/employees') ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white hover:border hover:border-gray-300 dark:hover:border-white/10' ?>">
                    <i class="fas fa-users w-5 text-center flex-shrink-0"></i>
                    <span>Employees</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- My Profile - All users -->
            <li>
                <a href="<?= BASE_URL ?>/?route=personal" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium transition-all duration-300 <?= str_contains($_SERVER['REQUEST_URI'], '/profile') || str_contains($_SERVER['REQUEST_URI'], '/personal') ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white hover:border hover:border-gray-300 dark:hover:border-white/10' ?>">
                    <i class="fas fa-user w-5 text-center flex-shrink-0"></i>
                    <span>My Profile</span>
                </a>
            </li>

            <!-- Departments - Super Admin, HR Manager -->
            <?php if ($auth->isSuperAdmin() || $auth->isHRManager()): ?>
            <li>
                <a href="<?= BASE_URL ?>/?route=departments" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium transition-all duration-300 <?= str_contains($_SERVER['REQUEST_URI'], '/departments') ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white hover:border hover:border-gray-300 dark:hover:border-white/10' ?>">
                    <i class="fas fa-building w-5 text-center flex-shrink-0"></i>
                    <span>Departments</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Admin - Super Admin, HR Manager -->
            <?php if ($auth->isSuperAdmin() || $auth->isHRManager()): ?>
            <li>
                <a href="<?= BASE_URL ?>/?route=admin" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium transition-all duration-300 <?= str_contains($_SERVER['REQUEST_URI'], '/admin') ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white hover:border hover:border-gray-300 dark:hover:border-white/10' ?>">
                    <i class="fas fa-cog w-5 text-center flex-shrink-0"></i>
                    <span>Admin</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Reports - Super Admin, HR Manager -->
            <?php if ($auth->isSuperAdmin() || $auth->isHRManager()): ?>
            <li>
                <a href="<?= BASE_URL ?>/?route=reports" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium transition-all duration-300 <?= str_contains($_SERVER['REQUEST_URI'], '/reports') ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white hover:border hover:border-gray-300 dark:hover:border-white/10' ?>">
                    <i class="fas fa-chart-bar w-5 text-center flex-shrink-0"></i>
                    <span>Reports</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Leave Management - Super Admin, HR Manager, Dept Head, Manager, Section Head, Sub Section Head, Officer -->
            <?php if ($auth->isSuperAdmin() || $auth->isHRManager() || $auth->isDeptHead() || in_array($auth->role(), ['officer', 'section_head', 'sub_section_head', 'manager'])): ?>
            <li>
                <a href="<?= BASE_URL ?>/?route=leave" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium transition-all duration-300 <?= str_contains($_SERVER['REQUEST_URI'], '/leave') ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white hover:border hover:border-gray-300 dark:hover:border-white/10' ?>">
                    <i class="fas fa-calendar-alt w-5 text-center flex-shrink-0"></i>
                    <span>Leave Management</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Performance - All users -->
            <li>
                <a href="<?= BASE_URL ?>/?route=performance" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium transition-all duration-300 <?= str_contains($_SERVER['REQUEST_URI'], '/performance') ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white hover:border hover:border-gray-300 dark:hover:border-white/10' ?>">
                    <i class="fas fa-star w-5 text-center flex-shrink-0"></i>
                    <span>Performance</span>
                </a>
            </li>

            <!-- Attendance - All users -->
            <li>
                <a href="<?= BASE_URL ?>/?route=attendance" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium transition-all duration-300 <?= str_contains($_SERVER['REQUEST_URI'], '/attendance') ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white hover:border hover:border-gray-300 dark:hover:border-white/10' ?>">
                    <i class="fas fa-sign-out-alt w-5 text-center flex-shrink-0"></i>
                    <span>Attendance</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar Footer -->
    <div class="p-3 lg:p-4 border-t border-gray-200 dark:border-white/10 flex-shrink-0">
        <a href="<?= BASE_URL ?>/?route=auth/logout" class="flex items-center gap-3 px-3 lg:px-4 py-2 lg:py-3 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-error/20 hover:text-error transition-all duration-300">
            <i class="fas fa-sign-out-alt w-5 text-center flex-shrink-0"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('[role="navigation"]');
    const navLinks = sidebar?.querySelectorAll('a[href]') || [];
    
    // Close sidebar when clicking a link on mobile
    navLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 1024) {
                sidebar.classList.add('-translate-x-full');
                sidebar.classList.remove('translate-x-0');
            }
        });
    });
});
</script>