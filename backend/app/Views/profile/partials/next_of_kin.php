<?php
/**
 * Next of Kin Partial
 *
 * Displays employee next of kin information.
 *
 * Place: backend/app/Views/profile/partials/next_of_kin.php
 */
?>
<div class="bg-white/50 dark:bg-white/10 border border-gray-200 dark:border-white/20 rounded-2xl shadow-lg dark:shadow-2xl p-6 backdrop-blur-xl">
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">
        <i class="fas fa-users mr-2 text-primary-400"></i>Next of Kin
    </h3>

    <?php if (empty($next_of_kin)): ?>
        <p class="text-gray-500 dark:text-gray-500 text-center py-8">No next of kin information provided.</p>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($next_of_kin as $kin): ?>
                <div class="p-4 bg-gray-100 dark:bg-white/5 rounded-xl border border-gray-200 dark:border-white/10">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Name</label>
                            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($kin['name'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Relationship</label>
                            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($kin['relationship'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-gray-500 mb-1">Contact</label>
                            <p class="text-gray-900 dark:text-white"><?= htmlspecialchars($kin['contact'] ?? 'N/A') ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>