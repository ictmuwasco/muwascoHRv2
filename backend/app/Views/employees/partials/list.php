<?php
/**
 * Employees List Partial
 *
 * Displays paginated employee table.
 *
 * Place: backend/app/Views/employees/partials/list.php
 */
?>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="p-5 border-b border-gray-200">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-users mr-2 text-blue-600"></i>
                All Employees (<?= number_format($total) ?>)
            </h3>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="bg-gray-50">
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Employee</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Department</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Section</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                            No employees found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $emp): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars(trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''))) ?>
                                        </p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($emp['email'] ?? '') ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700">
                                <?= htmlspecialchars($emp['department_name'] ?? 'N/A') ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700">
                                <?= htmlspecialchars($emp['section_name'] ?? 'N/A') ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-700">
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $emp['employee_type'] ?? 'N/A'))) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full
                                    <?= match($emp['employee_status'] ?? 'inactive') {
                                        'active' => 'bg-green-100 text-green-700',
                                        'on_leave' => 'bg-amber-100 text-amber-700',
                                        'terminated', 'fired' => 'bg-red-100 text-red-700',
                                        default => 'bg-gray-100 text-gray-600',
                                    } ?>">
                                    <?= ucfirst($emp['employee_status'] ?? 'unknown') ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <a href="/?route=personal&token=<?= htmlspecialchars($emp['profile_token'] ?? '') ?>"
                                       class="text-blue-600 hover:text-blue-800 transition-colors"
                                       title="View Profile">
                                         <i class="fas fa-eye"></i>
                                     </a>
                                     <a href="/?route=employees/edit/<?= $emp['id'] ?>"
                                        class="text-amber-600 hover:text-amber-700 transition-colors"
                                        title="Edit">
                                         <i class="fas fa-edit"></i>
                                     </a>
                                 </div>
                             </td>
                         </tr>
                     <?php endforeach; ?>
                 <?php endif; ?>
             </tbody>
         </table>
     </div>

     <!-- Pagination -->
     <?php if ($totalPages > 1): ?>
         <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
             <p class="text-sm text-gray-600">
                 Showing <?= number_format(($page - 1) * 30 + 1) ?> to <?= number_format(min($page * 30, $total)) ?> of <?= number_format($total) ?> entries
             </p>
             <div class="flex items-center gap-2">
                     <?php if ($page > 1): ?>
                     <a href="?route=employees&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department_filter) ?>&section=<?= urlencode($section_filter) ?>&type=<?= urlencode($type_filter) ?>&status=<?= urlencode($status_filter) ?>"
                        class="px-3 py-1 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors">
                         <i class="fas fa-chevron-left"></i>
                     </a>
                 <?php endif; ?>
                 <?php if ($page < $totalPages): ?>
                     <a href="?route=employees&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department_filter) ?>&section=<?= urlencode($section_filter) ?>&type=<?= urlencode($type_filter) ?>&status=<?= urlencode($status_filter) ?>"
                        class="px-3 py-1 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors">
                         <i class="fas fa-chevron-right"></i>
                     </a>
                 <?php endif; ?>
             </div>
         </div>
     <?php endif; ?>
 </div>