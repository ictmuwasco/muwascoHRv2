<?php
/**
 * Header Bar Component
 *
 * Top navigation bar with user info, theme toggle, and logout.
 * Reusable across all authenticated pages.
 *
 * Expects: $user (array) with keys: first_name, role, designation
 *
 * Place: backend/app/Views/components/header_bar.php
 */
?>
<!-- Theme initialization script (must run before DOM renders) -->
<script>
    // Initialize theme immediately to prevent flickering
    (function() {
        try {
            const savedTheme = localStorage.getItem('theme');
            const defaultTheme = 'light';
            const theme = savedTheme === 'dark' ? 'dark' : defaultTheme;
            const html = document.documentElement;
            const body = document.body;

            if (html && html.classList) {
                if (theme === 'dark') {
                    html.classList.add('dark');
                } else {
                    html.classList.remove('dark');
                }
            }

            function applyBodyTheme(currentTheme) {
                if (!document.body || !document.body.classList) return;

                const bodyClasses = document.body.classList;
                bodyClasses.remove('bg-gray-50', 'text-gray-900', 'bg-dark-bg', 'text-white');
                if (currentTheme === 'dark') {
                    bodyClasses.add('bg-dark-bg', 'text-white');
                } else {
                    bodyClasses.add('bg-gray-50', 'text-gray-900');
                }
            }

            if (body && body.classList) {
                applyBodyTheme(theme);
            } else {
                document.addEventListener('DOMContentLoaded', function() {
                    applyBodyTheme(theme);
                });
            }
        } catch (e) {
            console.warn('Theme initialization warning:', e);
        }
    })();
</script>

<!-- Top Header Bar -->
<div class="fixed top-0 left-0 lg:left-64 right-0 h-16 bg-white/95 lg:bg-white/80 dark:bg-dark-secondary/90 backdrop-blur-xl border-b border-gray-200 dark:border-white/10 z-30 flex items-center justify-between px-4 lg:px-6 transition-colors duration-300" id="header-bar">
    <!-- Left: Sidebar Toggle & Page Title -->
    <div class="flex items-center gap-2 lg:gap-4 flex-1">
        <button class="sidebar-toggle w-10 h-10 rounded-xl bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-white/10 hover:text-gray-900 dark:hover:text-white transition-all duration-300 lg:hidden" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <div class="hidden sm:flex items-center gap-2 text-gray-500 dark:text-gray-400 text-sm">
            <i class="fas fa-home text-primary-400"></i>
            <span>/</span>
            <span class="text-gray-900 dark:text-white">Dashboard</span>
        </div>
    </div>

    <!-- Right: Theme Toggle, User Info, Logout -->
    <div class="flex items-center gap-2 lg:gap-4">
        <!-- Theme Toggle -->
        <button id="theme-toggle" class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center text-lg hover:bg-gray-200 dark:hover:bg-white/10 transition-all duration-300" aria-label="Toggle dark/light mode">
            <span id="theme-icon">🌙</span>
        </button>

        <!-- User Info (hidden on mobile) -->
        <div class="hidden sm:flex items-center gap-3 px-3 lg:px-4 py-2 rounded-xl bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-primary-400 to-primary-600 flex items-center justify-center text-white text-sm font-bold shadow-glow-primary">
                <?= strtoupper(substr(htmlspecialchars($_SESSION['user_name'] ?? 'U'), 0, 1)) ?>
            </div>
            <div class="hidden md:block">
                <p class="text-sm font-medium text-gray-900 dark:text-white leading-tight" title="<?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?>">
                    <?php
                    $fullName  = $_SESSION['user_name'] ?? 'User';
                    $nameParts = explode(' ', trim($fullName), 2);
                    echo htmlspecialchars($nameParts[0] ?? 'User');
                    ?>
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 leading-tight">
                    <?= htmlspecialchars(ucwords($_SESSION['user_role'] ?? 'guest')) ?>
                </p>
            </div>
        </div>

        <!-- Logout -->
        <a href="<?= BASE_URL ?>/auth/logout" class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center text-gray-600 dark:text-gray-400 hover:bg-error/20 hover:text-error hover:border-error/30 transition-all duration-300" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const themeToggle = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('[role="navigation"]') || document.querySelector('.fixed.left-0.top-0');
    
    if (!themeToggle || !themeIcon) return; // Early exit if theme elements don't exist
    
    // Get current theme from localStorage (default to light)
    let currentTheme = localStorage.getItem('theme') || 'light';
    
    // Update theme icon based on current theme
    function updateThemeIcon() {
        if (themeIcon) themeIcon.textContent = currentTheme === 'light' ? '🌞' : '🌙';
    }
    
    // Apply theme to document
    function applyTheme(theme) {
        currentTheme = theme;
        localStorage.setItem('theme', theme);
        
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
            if (document.body) {
                document.body.classList.remove('bg-gray-50', 'text-gray-900');
                document.body.classList.add('bg-dark-bg', 'text-white');
            }
        } else {
            document.documentElement.classList.remove('dark');
            if (document.body) {
                document.body.classList.remove('bg-dark-bg', 'text-white');
                document.body.classList.add('bg-gray-50', 'text-gray-900');
            }
        }
        
        updateThemeIcon();
        
        // Send to server to update session
        fetch('<?= BASE_URL ?>/api/theme', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme: theme })
        }).catch(() => {}); // Silently fail if endpoint doesn't exist
    }
    
    // Initialize theme icon
    updateThemeIcon();
    
    // Theme toggle click handler
    if (themeToggle) {
        themeToggle.addEventListener('click', function(e) {
            e.preventDefault();
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            applyTheme(newTheme);
        });
    }
    
    // Sidebar toggle for mobile
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            if (sidebar.classList) {
                sidebar.classList.toggle('translate-x-0');
                sidebar.classList.toggle('-translate-x-full');
            }
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (sidebar && sidebarToggle && sidebar.contains && !sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                if (window.innerWidth < 1024 && sidebar.classList) {
                    sidebar.classList.add('-translate-x-full');
                    sidebar.classList.remove('translate-x-0');
                }
            }
        });
    }
});
</script>