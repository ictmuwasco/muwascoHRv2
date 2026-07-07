<?php
/**
 * Change Password Partial
 *
 * Allows user to change their password.
 *
 * Place: backend/app/Views/profile/partials/password.php
 */
?>
<div class="bg-white/50 dark:bg-white/10 border border-gray-200 dark:border-white/20 rounded-2xl shadow-lg dark:shadow-2xl p-6 backdrop-blur-xl">
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">
        <i class="fas fa-lock mr-2 text-primary-400"></i>Change Password
    </h3>

    <form method="POST" action="/profile/update" class="space-y-4 max-w-md">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="change_password">

        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-2">Current Password</label>
            <input type="password" name="current_password" required
                   class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm
                          focus:outline-none focus:border-primary-400 focus:ring-1 focus:ring-primary-400">
        </div>

        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-2">New Password</label>
            <input type="password" name="new_password" required minlength="8"
                   class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm
                          focus:outline-none focus:border-primary-400 focus:ring-1 focus:ring-primary-400">
            <p class="text-xs text-gray-600 dark:text-gray-500 mt-1">Minimum 8 characters</p>
        </div>

        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-2">Confirm New Password</label>
            <input type="password" name="confirm_password" required minlength="8"
                   class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm
                          focus:outline-none focus:border-primary-400 focus:ring-1 focus:ring-primary-400">
        </div>

        <button type="submit" class="btn btn-primary w-full">
            <i class="fas fa-save mr-2"></i>Update Password
        </button>
    </form>
</div>