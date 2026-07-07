<?php
/**
 * My Appraisal Card Component
 *
 * Quick access to performance reviews.
 * Place: backend/app/Views/components/my_appraisal_card.php
 */
?>
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-amber-300 transition-all duration-200 h-full flex flex-col" style="height:100%;">
    <div class="flex items-center gap-3 mb-3">
        <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-amber-600">
            <i class="fas fa-star text-lg"></i>
        </div>
        <div>
            <p class="text-sm font-semibold text-gray-900">My Appraisal</p>
            <p class="text-xs text-gray-500">View performance reviews</p>
        </div>
    </div>
    <div class="mt-auto">
        <a href="<?= BASE_URL ?>/appraisal" class="block w-full text-center px-4 py-2 bg-amber-500 text-white text-sm rounded-lg hover:bg-amber-600 transition-colors">
            <i class="fas fa-chart-line mr-1"></i>My Appraisal
        </a>
    </div>
</div>