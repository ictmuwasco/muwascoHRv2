<?php
/**
 * Employees Search/Filter Partial
 *
 * Displays search form and filter options.
 *
 * Place: backend/app/Views/employees/partials/search.php
 */
?>
<div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl shadow-2xl p-6">
    <h3 class="text-lg font-semibold text-white mb-4">
        <i class="fas fa-search mr-2 text-primary-400"></i>Search & Filter Employees
    </h3>

    <form method="GET" action="/employees" class="space-y-4">
        <input type="hidden" name="tab" value="search">

        <!-- Search Input -->
        <div>
            <label class="block text-xs text-gray-500 mb-2">Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search by name, email, or employee ID..."
                   class="w-full px-4 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm
                          focus:outline-none focus:border-primary-400 focus:ring-1 focus:ring-primary-400">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Department Filter -->
            <div>
                <label class="block text-xs text-gray-500 mb-2">Department</label>
                <select name="department" class="w-full px-4 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $department_filter == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Section Filter -->
            <div>
                <label class="block text-xs text-gray-500 mb-2">Section</label>
                <select name="section" class="w-full px-4 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= $section['id'] ?>" <?= $section_filter == $section['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($section['name']) ?> (<?= htmlspecialchars($section['department_name'] ?? '') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Employee Type Filter -->
            <div>
                <label class="block text-xs text-gray-500 mb-2">Employee Type</label>
                <select name="type" class="w-full px-4 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                    <option value="">All Types</option>
                    <option value="full_time" <?= $type_filter == 'full_time' ? 'selected' : '' ?>>Full Time</option>
                    <option value="part_time" <?= $type_filter == 'part_time' ? 'selected' : '' ?>>Part Time</option>
                    <option value="contract" <?= $type_filter == 'contract' ? 'selected' : '' ?>>Contract</option>
                    <option value="temporary" <?= $type_filter == 'temporary' ? 'selected' : '' ?>>Temporary</option>
                    <option value="officer" <?= $type_filter == 'officer' ? 'selected' : '' ?>>Officer</option>
                    <option value="section_head" <?= $type_filter == 'section_head' ? 'selected' : '' ?>>Section Head</option>
                    <option value="manager" <?= $type_filter == 'manager' ? 'selected' : '' ?>>Manager</option>
                    <option value="hr_manager" <?= $type_filter == 'hr_manager' ? 'selected' : '' ?>>HR Manager</option>
                    <option value="dept_head" <?= $type_filter == 'dept_head' ? 'selected' : '' ?>>Department Head</option>
                    <option value="managing_director" <?= $type_filter == 'managing_director' ? 'selected' : '' ?>>Managing Director</option>
                </select>
            </div>

            <!-- Status Filter -->
            <div>
                <label class="block text-xs text-gray-500 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="on_leave" <?= $status_filter == 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                    <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="terminated" <?= $status_filter == 'terminated' ? 'selected' : '' ?>>Terminated</option>
                    <option value="resigned" <?= $status_filter == 'resigned' ? 'selected' : '' ?>>Resigned</option>
                    <option value="retired" <?= $status_filter == 'retired' ? 'selected' : '' ?>>Retired</option>
                </select>
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search mr-2"></i>Search
            </button>
            <a href="/employees" class="btn btn-secondary">
                <i class="fas fa-times mr-2"></i>Clear
            </a>
        </div>
    </form>
</div>