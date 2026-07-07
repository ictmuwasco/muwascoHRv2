<?php
/**
 * Apply Leave Card Component
 *
 * Quick access to apply for leave.
 * Place: backend/app/Views/components/apply_leave_card.php
 */
?>
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-purple-300 transition-all duration-200 h-full flex flex-col" style="height:100%;">
    <div class="flex items-center gap-3 mb-3">
        <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center text-purple-600">
            <i class="fas fa-calendar-plus text-lg"></i>
        </div>
        <div>
            <p class="text-sm font-semibold text-gray-900">Apply Leave</p>
            <p class="text-xs text-gray-500">Submit leave application</p>
        </div>
    </div>
    <div class="mt-auto">
        <a href="<?= BASE_URL ?>/leave/apply" class="block w-full text-center px-4 py-2 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition-colors">
            <i class="fas fa-paper-plane mr-1"></i>Apply Leave
        </a>
    </div>
</div>