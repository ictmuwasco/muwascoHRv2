<?php
/**
 * Edit Employee View
 *
 * Place: backend/app/Views/employees/edit.php
 */
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Employee - HR Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= BASE_URL ?>/frontend/assets/js/theme.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">
    <?php require __DIR__ . '/../components/navbar.php'; ?>
    <?php require __DIR__ . '/../components/header_bar.php'; ?>

    <div class="lg:pl-64 pt-16">
        <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Edit Employee</h1>
                <p class="text-gray-600 mt-1">Update employee information</p>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <form action="<?= BASE_URL ?>/?route=employees/update/<?= $employee['id'] ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($employee['first_name'] ?? '') ?>" required class="form-input" placeholder="Enter first name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($employee['last_name'] ?? '') ?>" required class="form-input" placeholder="Enter last name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($employee['email'] ?? '') ?>" required class="form-input" placeholder="Enter email address">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($employee['phone'] ?? '') ?>" class="form-input" placeholder="Enter phone number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Department *</label>
                            <select name="department_id" id="departmentSelect" required class="form-select">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>" <?= ($employee['department_id'] ?? '') == $dept['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Section *</label>
                            <select name="section_id" id="sectionSelect" required class="form-select">
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $section): ?>
                                    <?php if (($section['department_id'] ?? $section['department_id'] ?? 0) == ($employee['department_id'] ?? 0)): ?>
                                    <option value="<?= $section['id'] ?>" data-department="<?= $section['department_id'] ?? '' ?>" <?= ($employee['section_id'] ?? '') == $section['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($section['name']) ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div id="sectionLoading" class="text-xs text-gray-500 mt-1 hidden">
                                <span class="loading-spinner"></span> Loading...
                            </div>
                        </div>

                        <div class="form-group" id="subsectionGroup" style="<?= isset($employee['subsection_id']) && $employee['subsection_id'] ? 'display:block' : 'display:none' ?>">
                            <label class="form-label">Sub-Section <span id="subsectionRequired" class="text-red-500">*</span></label>
                            <select name="subsection_id" id="subsectionSelect" class="form-select" <?= isset($employee['subsection_id']) && $employee['subsection_id'] ? '' : 'disabled' ?>>
                                <option value="">Select Sub-Section</option>
                                <?php if (isset($subsections)): foreach ($subsections as $sub): ?>
                                    <?php if (($sub['section_id'] ?? 0) == ($employee['section_id'] ?? 0)): ?>
                                    <option value="<?= $sub['id'] ?>" <?= ($employee['subsection_id'] ?? '') == $sub['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sub['name']) ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; endif; ?>
                            </select>
                            <div id="subsectionLoading" class="text-xs text-gray-500 mt-1 hidden">
                                <span class="loading-spinner"></span> Loading...
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Employee Type *</label>
                            <select name="employee_type" required class="form-select">
                                <option value="permanent" <?= ($employee['employee_type'] ?? '') === 'permanent' ? 'selected' : '' ?>>Permanent</option>
                                <option value="contract" <?= ($employee['employee_type'] ?? '') === 'contract' ? 'selected' : '' ?>>Contract</option>
                                <option value="intern" <?= ($employee['employee_type'] ?? '') === 'intern' ? 'selected' : '' ?>>Intern</option>
                                <option value="casual" <?= ($employee['employee_type'] ?? '') === 'casual' ? 'selected' : '' ?>>Casual</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Employment Status *</label>
                            <select name="employee_status" required class="form-select">
                                <option value="active" <?= ($employee['employee_status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($employee['employee_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value="on_leave" <?= ($employee['employee_status'] ?? '') === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 mt-8">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Employee
                        </button>
                        <a href="<?= BASE_URL ?>/?route=employees" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <style>
        .loading-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .dropdown-loading {
            opacity: 0.6;
            pointer-events: none;
        }
    </style>
    <script>
    (function() {
        const BASE_URL = '<?= BASE_URL ?>';
        const departmentSelect = document.getElementById('departmentSelect');
        const sectionSelect = document.getElementById('sectionSelect');
        const subsectionSelect = document.getElementById('subsectionSelect');
        const subsectionGroup = document.getElementById('subsectionGroup');
        const subsectionRequired = document.getElementById('subsectionRequired');
        const sectionLoading = document.getElementById('sectionLoading');
        const subsectionLoading = document.getElementById('subsectionLoading');

        // Store currently selected section and subsection IDs for restoration after AJAX load
        const selectedSectionId = sectionSelect.value;
        const selectedSubsectionId = subsectionSelect ? subsectionSelect.value : '';

        departmentSelect.addEventListener('change', function() {
            const departmentId = this.value;
            
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            subsectionSelect.innerHTML = '<option value="">Select Sub-Section</option>';
            subsectionGroup.style.display = 'none';
            subsectionSelect.disabled = true;
            
            if (!departmentId) {
                sectionSelect.disabled = true;
                return;
            }

            sectionSelect.disabled = true;
            sectionLoading.classList.remove('hidden');
            sectionSelect.classList.add('dropdown-loading');

            fetch(`${BASE_URL}/api/organization/sections?department_id=${departmentId}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                sectionLoading.classList.add('hidden');
                sectionSelect.classList.remove('dropdown-loading');
                
                if (data.success && data.data.length > 0) {
                    data.data.forEach(section => {
                        const option = document.createElement('option');
                        option.value = section.id;
                        option.textContent = section.name;
                        sectionSelect.appendChild(option);
                    });
                    sectionSelect.disabled = false;
                    
                    // Restore selected section if it belonged to this department
                    if (selectedSectionId) {
                        const matchingOption = Array.from(sectionSelect.options).find(opt => opt.value === selectedSectionId);
                        if (matchingOption) {
                            sectionSelect.value = selectedSectionId;
                            // Trigger subsection load
                            sectionSelect.dispatchEvent(new Event('change'));
                        }
                    }
                } else {
                    sectionSelect.innerHTML = '<option value="">No sections available</option>';
                    sectionSelect.disabled = true;
                }
            })
            .catch(error => {
                console.error('Error loading sections:', error);
                sectionLoading.classList.add('hidden');
                sectionSelect.classList.remove('dropdown-loading');
                sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
                sectionSelect.disabled = true;
            });
        });

        sectionSelect.addEventListener('change', function() {
            const sectionId = this.value;
            
            subsectionSelect.innerHTML = '<option value="">Select Sub-Section</option>';
            subsectionGroup.style.display = 'none';
            subsectionSelect.disabled = true;
            
            if (!sectionId) {
                return;
            }

            subsectionSelect.disabled = true;
            subsectionLoading.classList.remove('hidden');
            subsectionSelect.classList.add('dropdown-loading');

            fetch(`${BASE_URL}/api/organization/sub-sections?section_id=${sectionId}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                subsectionLoading.classList.add('hidden');
                subsectionSelect.classList.remove('dropdown-loading');
                
                if (data.success && data.data.length > 0) {
                    data.data.forEach(subsection => {
                        const option = document.createElement('option');
                        option.value = subsection.id;
                        option.textContent = subsection.name;
                        subsectionSelect.appendChild(option);
                    });
                    subsectionGroup.style.display = 'block';
                    subsectionSelect.disabled = false;
                    subsectionRequired.style.display = 'inline';
                    
                    // Restore selected subsection if it belonged to this section
                    if (selectedSubsectionId) {
                        const matchingOption = Array.from(subsectionSelect.options).find(opt => opt.value === selectedSubsectionId);
                        if (matchingOption) {
                            subsectionSelect.value = selectedSubsectionId;
                        }
                    }
                } else {
                    subsectionGroup.style.display = 'none';
                    subsectionSelect.disabled = true;
                }
            })
            .catch(error => {
                console.error('Error loading sub-sections:', error);
                subsectionLoading.classList.add('hidden');
                subsectionSelect.classList.remove('dropdown-loading');
                subsectionSelect.innerHTML = '<option value="">Error loading sub-sections</option>';
                subsectionSelect.disabled = true;
            });
        });

        // Auto-load sections on page load if department is already selected
        if (departmentSelect.value) {
            departmentSelect.dispatchEvent(new Event('change'));
        }
    })();
    </script>
</body>
</html>
