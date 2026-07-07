<?php
// Statistics cards - outputs 4 individual cards for grid layout
// Each card has consistent styling
?>

<!-- Total Employees Card -->
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-blue-300 transition-all duration-200 h-full flex flex-col" style="flex:1;min-width:0;">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center text-blue-600">
                <i class="fas fa-users text-lg"></i>
            </div>
            <p class="text-sm font-semibold text-gray-900">Total Employees</p>
        </div>
        <span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 font-medium">Active</span>
    </div>
    <div class="mt-auto">
        <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['total_employees'] ?? 0) ?></p>
    </div>
</div>

<!-- Departments Card -->
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-purple-300 transition-all duration-200 h-full flex flex-col" style="flex:1;min-width:0;">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center text-purple-600">
                <i class="fas fa-building text-lg"></i>
            </div>
            <p class="text-sm font-semibold text-gray-900">Departments</p>
        </div>
        <span class="text-xs px-2 py-0.5 rounded-full bg-purple-50 text-purple-600 font-medium">Units</span>
    </div>
    <div class="mt-auto">
        <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['total_departments'] ?? 0) ?></p>
    </div>
</div>

<!-- Sections Card -->
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-emerald-300 transition-all duration-200 h-full flex flex-col" style="flex:1;min-width:0;">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-600">
                <i class="fas fa-layer-group text-lg"></i>
            </div>
            <p class="text-sm font-semibold text-gray-900">Sections</p>
        </div>
        <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 font-medium">Teams</span>
    </div>
    <div class="mt-auto">
        <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['total_sections'] ?? 0) ?></p>
    </div>
</div>

<!-- Recent Hires Card -->
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-amber-300 transition-all duration-200 h-full flex flex-col" style="flex:1;min-width:0;">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-amber-600">
                <i class="fas fa-user-plus text-lg"></i>
            </div>
            <p class="text-sm font-semibold text-gray-900">Recent Hires</p>
        </div>
        <span class="text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 font-medium">30 Days</span>
    </div>
    <div class="mt-auto">
        <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['recent_hires'] ?? 0) ?></p>
    </div>
</div>