<?php
/** @var array $employee */
/** @var array $permissions */
/** @var array $effective_permissions */
/** @var string $csrf_token */
?>

<div class="admin-tab-content">
    <?php include __DIR__ . '/../../components/admin_tabs.php'; ?>
    
    <div class="container-fluid px-4 py-6">
        <!-- Employee Information Card -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="bg-blue-600 text-white px-6 py-4 rounded-t-lg">
                <h5 class="text-lg font-semibold">
                    <i class="fas fa-user mr-2"></i>Employee Information
                </h5>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <p class="mb-2"><strong class="text-gray-700">Name:</strong> <span class="text-gray-900"><?= htmlspecialchars($employee['full_name']) ?></span></p>
                        <p class="mb-2"><strong class="text-gray-700">Employee ID:</strong> <span class="text-gray-900"><?= htmlspecialchars($employee['employee_id']) ?></span></p>
                        <p class="mb-2"><strong class="text-gray-700">Email:</strong> <span class="text-gray-900"><?= htmlspecialchars($employee['email']) ?></span></p>
                    </div>
                    <div>
                        <p class="mb-2"><strong class="text-gray-700">Department:</strong> <span class="text-gray-900"><?= htmlspecialchars($employee['department'] ?? 'N/A') ?></span></p>
                        <p class="mb-2"><strong class="text-gray-700">Section:</strong> <span class="text-gray-900"><?= htmlspecialchars($employee['section'] ?? 'N/A') ?></span></p>
                        <p class="mb-2">
                            <strong class="text-gray-700">Current Role:</strong> 
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ml-2 <?= $employee['role'] === 'super_admin' ? 'bg-red-100 text-red-800' : ($employee['role'] === 'hr_manager' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800') ?>">
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $employee['role']))) ?>
                            </span>
                        </p>
                        <p class="mb-2">
                            <strong class="text-gray-700">Status:</strong> 
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ml-2 <?= $employee['employee_status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                <?= ucfirst($employee['employee_status']) ?>
                            </span>
                        </p>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="<?= BASE_URL ?>/?route=admin/permission-overrides" class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white font-medium rounded-lg transition duration-200">
                        <i class="fas fa-arrow-left mr-2"></i>Back to List
                    </a>
                </div>
            </div>
        </div>

        <form id="permissionForm" method="POST" action="<?= BASE_URL ?>/?route=admin/permission-overrides/save/<?= $employee['user_id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Permission Management -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg shadow mb-6">
                        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                            <h5 class="text-lg font-semibold text-gray-800">
                                <i class="fas fa-key mr-2"></i>Permission Overrides
                            </h5>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-info-circle mr-1"></i>Select permission state for each page
                            </span>
                        </div>
                        <div class="p-6">
                            <div class="bg-blue-50 border-l-4 border-blue-400 text-blue-800 p-4 rounded-lg mb-6">
                                <p class="font-semibold mb-2">How to use:</p>
                                <ul class="list-disc list-inside space-y-1 text-sm">
                                    <li><strong>Inherit:</strong> Use the role's default permission (recommended for most cases)</li>
                                    <li><strong>Allow:</strong> Explicitly grant access even if role doesn't allow it</li>
                                    <li><strong>Deny:</strong> Explicitly deny access even if role allows it (highest priority)</li>
                                </ul>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($permissions as $perm): ?>
                                    <div class="border border-gray-200 rounded-lg p-4 hover:border-blue-400 hover:shadow-md transition duration-200" data-page="<?= htmlspecialchars($perm['page_id']) ?>">
                                        <div class="flex justify-between items-center mb-3 pb-3 border-b border-gray-200">
                                            <strong class="text-gray-900"><?= htmlspecialchars($perm['page_name']) ?></strong>
                                            <?php if ($perm['role_has_permission']): ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                                    <i class="fas fa-inheritance mr-1"></i>Inherited from Role
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex gap-4 items-center">
                                            <div class="flex items-center">
                                                <input class="w-4 h-4 text-gray-600 border-gray-300 focus:ring-blue-500 cursor-pointer" 
                                                       type="radio" 
                                                       name="permissions[<?= htmlspecialchars($perm['page_id']) ?>]" 
                                                       value="inherit" 
                                                       id="inherit_<?= htmlspecialchars($perm['page_id']) ?>"
                                                       <?= $perm['override_type'] === null ? 'checked' : '' ?>>
                                                <label class="ml-2 text-sm text-gray-700 cursor-pointer" for="inherit_<?= htmlspecialchars($perm['page_id']) ?>">
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700">Inherit</span>
                                                </label>
                                            </div>
                                            <div class="flex items-center">
                                                <input class="w-4 h-4 text-green-600 border-gray-300 focus:ring-green-500 cursor-pointer" 
                                                       type="radio" 
                                                       name="permissions[<?= htmlspecialchars($perm['page_id']) ?>]" 
                                                       value="allow" 
                                                       id="allow_<?= htmlspecialchars($perm['page_id']) ?>"
                                                       <?= $perm['override_type'] === 'allow' ? 'checked' : '' ?>>
                                                <label class="ml-2 text-sm text-gray-700 cursor-pointer" for="allow_<?= htmlspecialchars($perm['page_id']) ?>">
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Allow</span>
                                                </label>
                                            </div>
                                            <div class="flex items-center">
                                                <input class="w-4 h-4 text-red-600 border-gray-300 focus:ring-red-500 cursor-pointer" 
                                                       type="radio" 
                                                       name="permissions[<?= htmlspecialchars($perm['page_id']) ?>]" 
                                                       value="deny" 
                                                       id="deny_<?= htmlspecialchars($perm['page_id']) ?>"
                                                       <?= $perm['override_type'] === 'deny' ? 'checked' : '' ?>>
                                                <label class="ml-2 text-sm text-gray-700 cursor-pointer" for="deny_<?= htmlspecialchars($perm['page_id']) ?>">
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">Deny</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="lg:col-span-1">
                    <!-- Effective Permissions Preview -->
                    <div class="bg-white rounded-lg shadow mb-6 sticky-top" style="top: 20px;">
                        <div class="bg-green-600 text-white px-6 py-4 rounded-t-lg">
                            <h5 class="text-lg font-semibold">
                                <i class="fas fa-eye mr-2"></i>Effective Permissions Preview
                            </h5>
                        </div>
                        <div class="p-6">
                            <div class="bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded-lg mb-4">
                                <p class="text-sm"><i class="fas fa-exclamation-triangle mr-1"></i>This shows the final access after applying all overrides.</p>
                            </div>
                            <div class="space-y-2 max-h-96 overflow-y-auto">
                                <?php foreach ($effective_permissions as $eff): ?>
                                    <div class="border-b border-gray-200 pb-2 last:border-b-0">
                                        <div class="flex justify-between items-center">
                                            <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($eff['page_name']) ?></span>
                                            <?php if ($eff['allowed']): ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                    <i class="fas fa-check mr-1"></i>Allowed
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                    <i class="fas fa-times mr-1"></i>Denied
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-1">
                                            <span class="text-xs text-gray-600">Source: </span>
                                            <?php if ($eff['source'] === 'Role'): ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700">Inherited from Role</span>
                                            <?php elseif ($eff['source'] === 'User Grant'): ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Explicitly Allowed</span>
                                            <?php elseif ($eff['source'] === 'User Deny'): ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">Explicitly Denied</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-700 text-white">Default Deny</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-6">
                            <div class="space-y-3">
                                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg font-medium transition duration-200 flex items-center justify-center" id="saveButton">
                                    <i class="fas fa-save mr-2"></i>Save Changes
                                </button>
                <a href="<?= BASE_URL ?>/?route=admin/permission-overrides" class="w-full bg-gray-600 hover:bg-gray-700 text-white px-4 py-3 rounded-lg font-medium transition duration-200 flex items-center justify-center">
                    <i class="fas fa-times mr-2"></i>Cancel
                </a>
                            </div>
                            <div class="mt-4" id="changeIndicator" style="display: none;">
                                <div class="bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded-lg">
                                    <p class="text-sm"><i class="fas fa-exclamation-circle mr-1"></i><span id="changeText">You have unsaved changes</span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('permissionForm');
    const changeIndicator = document.getElementById('changeIndicator');
    const changeText = document.getElementById('changeText');
    const saveButton = document.getElementById('saveButton');
    let hasChanges = false;
    let originalValues = {};

    // Store original values
    document.querySelectorAll('.permission-radio').forEach(radio => {
        originalValues[radio.name] = radio.checked;
    });

    // Check for changes
    document.querySelectorAll('.permission-radio').forEach(radio => {
        radio.addEventListener('change', function() {
            hasChanges = true;
            changeIndicator.style.display = 'block';
            changeText.textContent = 'You have unsaved changes';
        });
    });

    // Handle form submission
    form.addEventListener('submit', function(e) {
        if (!hasChanges) {
            e.preventDefault();
            return;
        }

        if (!confirm('Are you sure you want to save these permission changes?')) {
            e.preventDefault();
            return;
        }

        saveButton.disabled = true;
        saveButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
    });

    // Warn before leaving with unsaved changes
    window.addEventListener('beforeunload', function(e) {
        if (hasChanges) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            return e.returnValue;
        }
    });
});
</script>