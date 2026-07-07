<?php
/**
 * Departments View
 *
 * Displays departments, sections, and subsections management interface.
 * Place: backend/app/Views/departments/index.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/auth.php';
}

$pageTitle = 'Departments - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">

<div class="flex pt-16">
    <!-- Sidebar -->
    <?php require __DIR__ . '/../components/navbar.php'; ?>
    
    <!-- Main Content -->
    <div class="flex-1 ml-64 p-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Departments Management</h1>
            <p class="mt-2 text-sm text-gray-600">Manage departments, sections, and sub-sections</p>
        </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($_SESSION['flash_message']) ?></span>
                    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
                    <?php unset($_SESSION['flash_error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Departments Table -->
            <div class="bg-white rounded-xl border-2 border-gray-200 shadow-sm mb-6">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800">Departments</h2>
                        <p class="text-sm text-gray-500 mt-1"><?= count($departments) ?> items</p>
                    </div>
                    <?php if (\App\Helpers\Auth::getInstance()->isHRManager() || \App\Helpers\Auth::getInstance()->isSuperAdmin()): ?>
                        <button onclick="showAddDepartmentModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition duration-200">
                            <i class="fas fa-plus mr-2"></i>Add Department
                        </button>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($departments)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No departments found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($departments as $department): ?>
                                <tr class="hover:bg-gray-50 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($department['id']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($department['name']) ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?= htmlspecialchars($department['description'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if (\App\Helpers\Auth::getInstance()->isHRManager() || \App\Helpers\Auth::getInstance()->isSuperAdmin()): ?>
                                            <button onclick="showEditDepartmentModal(<?= htmlspecialchars(json_encode($department)) ?>)" class="text-blue-600 hover:text-blue-800 font-medium mr-3">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </button>
                                            <button onclick="confirmDeleteDepartment(<?= $department['id'] ?>, '<?= htmlspecialchars($department['name']) ?>')" class="text-red-600 hover:text-red-800 font-medium">
                                                <i class="fas fa-trash mr-1"></i>Delete
                                            </button>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Sections Table -->
            <div class="bg-white rounded-xl border-2 border-gray-200 shadow-sm mb-6">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800">Sections</h2>
                        <p class="text-sm text-gray-500 mt-1"><?= count($sections) ?> items</p>
                    </div>
                    <?php if (\App\Helpers\Auth::getInstance()->isHRManager() || \App\Helpers\Auth::getInstance()->isSuperAdmin()): ?>
                        <button onclick="showAddSectionModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition duration-200">
                            <i class="fas fa-plus mr-2"></i>Add Section
                        </button>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($sections)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No sections found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sections as $section): ?>
                                <tr class="hover:bg-gray-50 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($section['id']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($section['name']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?= htmlspecialchars($section['department_name'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?= htmlspecialchars($section['description'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if (\App\Helpers\Auth::getInstance()->isHRManager() || \App\Helpers\Auth::getInstance()->isSuperAdmin()): ?>
                                            <button onclick="showEditSectionModal(<?= htmlspecialchars(json_encode($section)) ?>)" class="text-blue-600 hover:text-blue-800 font-medium mr-3">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </button>
                                            <button onclick="confirmDeleteSection(<?= $section['id'] ?>, '<?= htmlspecialchars($section['name']) ?>')" class="text-red-600 hover:text-red-800 font-medium">
                                                <i class="fas fa-trash mr-1"></i>Delete
                                            </button>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Sub-sections Table -->
            <div class="bg-white rounded-xl border-2 border-gray-200 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800">Sub-sections</h2>
                        <p class="text-sm text-gray-500 mt-1"><?= count($subsections) ?> items</p>
                    </div>
                    <?php if (\App\Helpers\Auth::getInstance()->isHRManager() || \App\Helpers\Auth::getInstance()->isSuperAdmin()): ?>
                        <button onclick="showAddSubsectionModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition duration-200">
                            <i class="fas fa-plus mr-2"></i>Add Sub-section
                        </button>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Section</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($subsections)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No sub-sections found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($subsections as $subsection): ?>
                                <tr class="hover:bg-gray-50 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($subsection['id']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($subsection['name']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?= htmlspecialchars($subsection['section_name'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?= htmlspecialchars($subsection['department_name'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?= htmlspecialchars($subsection['description'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if (\App\Helpers\Auth::getInstance()->isHRManager() || \App\Helpers\Auth::getInstance()->isSuperAdmin()): ?>
                                            <button onclick="showEditSubsectionModal(<?= htmlspecialchars(json_encode($subsection)) ?>)" class="text-blue-600 hover:text-blue-800 font-medium mr-3">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </button>
                                            <button onclick="confirmDeleteSubsection(<?= $subsection['id'] ?>, '<?= htmlspecialchars($subsection['name']) ?>')" class="text-red-600 hover:text-red-800 font-medium">
                                                <i class="fas fa-trash mr-1"></i>Delete
                                            </button>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
        </div>
    </div>
</div>
    </div>
</div>

<?php if (\App\Helpers\Auth::getInstance()->isHRManager() || \App\Helpers\Auth::getInstance()->isSuperAdmin()): ?>

<!-- Add Department Modal -->
<div id="addDepartmentModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Add New Department</h3>
            <button onclick="hideAddDepartmentModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form method="POST" action="/departments/add">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="mb-4">
                <label for="dept_name" class="block text-sm font-medium text-gray-700 mb-2">Department Name</label>
                <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="dept_name" name="name" required>
            </div>
            <div class="mb-6">
                <label for="dept_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="dept_description" name="description" rows="3"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="hideAddDepartmentModal()" class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg font-medium transition duration-200">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition duration-200">Add Department</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Department Modal -->
<div id="editDepartmentModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Edit Department</h3>
            <button onclick="hideEditDepartmentModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form method="POST" action="/departments/edit">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="edit_dept_id" name="id">
            <div class="mb-4">
                <label for="edit_dept_name" class="block text-sm font-medium text-gray-700 mb-2">Department Name</label>
                <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_dept_name" name="name" required>
            </div>
            <div class="mb-6">
                <label for="edit_dept_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_dept_description" name="description" rows="3"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="hideEditDepartmentModal()" class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg font-medium transition duration-200">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition duration-200">Update Department</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Section Modal -->
<div id="addSectionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Add New Section</h3>
            <button onclick="hideAddSectionModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form method="POST" action="/departments/section/add">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="mb-4">
                <label for="section_department_id" class="block text-sm font-medium text-gray-700 mb-2">Department</label>
                <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="section_department_id" name="department_id" required>
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label for="section_name" class="block text-sm font-medium text-gray-700 mb-2">Section Name</label>
                <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="section_name" name="section_name" required>
            </div>
            <div class="mb-6">
                <label for="section_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="section_description" name="section_description" rows="3"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="hideAddSectionModal()" class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg font-medium transition duration-200">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition duration-200">Add Section</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Section Modal -->
<div id="editSectionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Edit Section</h3>
            <button onclick="hideEditSectionModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form method="POST" action="/departments/section/edit">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="edit_section_id" name="section_id">
            <div class="mb-4">
                <label for="edit_section_department_id" class="block text-sm font-medium text-gray-700 mb-2">Department</label>
                <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_section_department_id" name="department_id" required>
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label for="edit_section_name" class="block text-sm font-medium text-gray-700 mb-2">Section Name</label>
                <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_section_name" name="section_name" required>
            </div>
            <div class="mb-6">
                <label for="edit_section_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_section_description" name="section_description" rows="3"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="hideEditSectionModal()" class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg font-medium transition duration-200">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition duration-200">Update Section</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Sub-section Modal -->
<div id="addSubsectionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Add New Sub-section</h3>
            <button onclick="hideAddSubsectionModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form method="POST" action="/departments/subsection/add">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="mb-4">
                <label for="subsection_department_id" class="block text-sm font-medium text-gray-700 mb-2">Department</label>
                <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="subsection_department_id" name="department_id" required onchange="updateSectionDropdown(this.value)">
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label for="subsection_section_id" class="block text-sm font-medium text-gray-700 mb-2">Section</label>
                <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="subsection_section_id" name="section_id" required>
                    <option value="">Select Section</option>
                </select>
            </div>
            <div class="mb-4">
                <label for="subsection_name" class="block text-sm font-medium text-gray-700 mb-2">Sub-section Name</label>
                <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="subsection_name" name="subsection_name" required>
            </div>
            <div class="mb-6">
                <label for="subsection_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="subsection_description" name="subsection_description" rows="3"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="hideAddSubsectionModal()" class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg font-medium transition duration-200">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition duration-200">Add Sub-section</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Sub-section Modal -->
<div id="editSubsectionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Edit Sub-section</h3>
            <button onclick="hideEditSubsectionModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form method="POST" action="/departments/subsection/edit">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="edit_subsection_id" name="subsection_id">
            <div class="mb-4">
                <label for="edit_subsection_department_id" class="block text-sm font-medium text-gray-700 mb-2">Department</label>
                <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_subsection_department_id" name="department_id" required onchange="updateEditSectionDropdown(this.value)">
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label for="edit_subsection_section_id" class="block text-sm font-medium text-gray-700 mb-2">Section</label>
                <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_subsection_section_id" name="section_id" required>
                    <option value="">Select Section</option>
                </select>
            </div>
            <div class="mb-4">
                <label for="edit_subsection_name" class="block text-sm font-medium text-gray-700 mb-2">Sub-section Name</label>
                <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_subsection_name" name="subsection_name" required>
            </div>
            <div class="mb-6">
                <label for="edit_subsection_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_subsection_description" name="subsection_description" rows="3"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="hideEditSubsectionModal()" class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg font-medium transition duration-200">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition duration-200">Update Sub-section</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
const _sections = <?= json_encode($sections) ?>;

// Department modals
function showAddDepartmentModal() { document.getElementById('addDepartmentModal').classList.remove('hidden'); }
function hideAddDepartmentModal() { document.getElementById('addDepartmentModal').classList.add('hidden'); }
function hideEditDepartmentModal() { document.getElementById('editDepartmentModal').classList.add('hidden'); }
function showEditDepartmentModal(dept) {
    document.getElementById('edit_dept_id').value = dept.id;
    document.getElementById('edit_dept_name').value = dept.name;
    document.getElementById('edit_dept_description').value = dept.description || '';
    document.getElementById('editDepartmentModal').classList.remove('hidden');
}

// Section modals
function showAddSectionModal() { document.getElementById('addSectionModal').classList.remove('hidden'); }
function hideAddSectionModal() { document.getElementById('addSectionModal').classList.add('hidden'); }
function hideEditSectionModal() { document.getElementById('editSectionModal').classList.add('hidden'); }
function showEditSectionModal(section) {
    document.getElementById('edit_section_id').value = section.id;
    document.getElementById('edit_section_name').value = section.name;
    document.getElementById('edit_section_description').value = section.description || '';
    document.getElementById('edit_section_department_id').value = section.department_id;
    document.getElementById('editSectionModal').classList.remove('hidden');
}

// Sub-section modals
function showAddSubsectionModal() { document.getElementById('addSubsectionModal').classList.remove('hidden'); }
function hideAddSubsectionModal() { document.getElementById('addSubsectionModal').classList.add('hidden'); }
function hideEditSubsectionModal() { document.getElementById('editSubsectionModal').classList.add('hidden'); }
function showEditSubsectionModal(sub) {
    document.getElementById('edit_subsection_id').value = sub.id;
    document.getElementById('edit_subsection_name').value = sub.name;
    document.getElementById('edit_subsection_description').value = sub.description || '';
    document.getElementById('edit_subsection_department_id').value = sub.department_id;
    updateEditSectionDropdown(sub.department_id, sub.section_id);
    document.getElementById('editSubsectionModal').classList.remove('hidden');
}

// Section dropdown helpers
function _buildSectionOptions(selectEl, departmentId, selectedId = null) {
    selectEl.innerHTML = '<option value="">Select Section</option>';
    _sections
        .filter(s => s.department_id == departmentId)
        .forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name;
            if (selectedId && s.id == selectedId) opt.selected = true;
            selectEl.appendChild(opt);
        });
}
function updateSectionDropdown(deptId) {
    _buildSectionOptions(document.getElementById('subsection_section_id'), deptId);
}
function updateEditSectionDropdown(deptId, selectedId = null) {
    _buildSectionOptions(document.getElementById('edit_subsection_section_id'), deptId, selectedId);
}

// Delete confirmations
function _submitDeleteForm(action, id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="action" value="' + action + '"><input type="hidden" name="id" value="' + id + '">';
    document.body.appendChild(form);
    form.submit();
}
function confirmDeleteDepartment(id, name) {
    if (confirm('Are you sure you want to delete the department "' + name + '"?\n\nThis will also delete all sections and sub-sections.\nThis action cannot be undone.'))
        _submitDeleteForm('delete_department', id);
}
function confirmDeleteSection(id, name) {
    if (confirm('Are you sure you want to delete the section "' + name + '"?\n\nThis will also delete all sub-sections.\nThis action cannot be undone.'))
        _submitDeleteForm('delete_section', id);
}
function confirmDeleteSubsection(id, name) {
    if (confirm('Are you sure you want to delete the sub-section "' + name + '"?\n\nThis action cannot be undone.'))
        _submitDeleteForm('delete_subsection', id);
}

// Close modal on outside click
window.onclick = function(e) {
    if (e.target.classList.contains('fixed')) {
        e.target.classList.add('hidden');
    }
};
</script>

</body>
</html>

