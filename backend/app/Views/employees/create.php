<?php
/**
 * Create Employee View
 *
 * Place: backend/app/Views/employees/create.php
 */
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Employee - HR Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= BASE_URL ?>/frontend/assets/js/theme.js"></script>
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
        .form-section-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        /* Override compact form spacing */
        .form-group {
            margin-bottom: 1rem !important;
        }
        .form-label {
            margin-bottom: 0.5rem !important;
            font-size: 0.75rem !important;
        }
        .form-input, .form-select {
            padding: 0.5rem 0.75rem !important;
            font-size: 0.875rem !important;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-100 text-gray-900">
    <?php require __DIR__ . '/../components/navbar.php'; ?>
    <?php require __DIR__ . '/../components/header_bar.php'; ?>

    <div class="lg:pl-64 pt-16">
        <div class="w-full px-4 sm:px-6 lg:px-8 py-6">
            <div class="max-w-5xl mx-auto">
                <!-- Compact Modal-like Card -->
                <div class="bg-white rounded-lg border border-gray-200 shadow-sm">
                    <!-- Header -->
                    <div class="flex items-center justify-between p-4 border-b border-gray-200">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-user-plus text-gray-700"></i>
                            <h1 class="text-lg font-semibold text-gray-900">Add New Employee</h1>
                        </div>
                        <a href="<?= BASE_URL ?>/?route=employees" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>

                    <!-- Form Content -->
                    <form id="employeeForm" action="<?= BASE_URL ?>/?route=employees/store" method="POST" class="p-6">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        
                        <!-- Personal Information -->
                        <div class="mb-6">
                            <div class="form-section-title">Personal Information</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-group">
                                    <label class="form-label text-xs">Employee ID *</label>
                                    <input type="text" name="employee_id" required class="form-input" placeholder="Enter employee ID">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">First Name *</label>
                                    <input type="text" name="first_name" required class="form-input" placeholder="Enter first name">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Last Name *</label>
                                    <input type="text" name="last_name" required class="form-input" placeholder="Enter last name">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Surname</label>
                                    <input type="text" name="surname" class="form-input" placeholder="Enter surname">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Gender *</label>
                                    <select name="gender" required class="form-select">
                                        <option value="">Select Gender</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">National ID *</label>
                                    <input type="text" name="national_id" required class="form-input" placeholder="Enter national ID">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Email *</label>
                                    <input type="email" name="email" required class="form-input" placeholder="Enter email address">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Phone *</label>
                                    <input type="text" name="phone" required class="form-input" placeholder="Enter phone number">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Date of Birth *</label>
                                    <input type="date" name="date_of_birth" required class="form-input">
                                </div>

                                <div class="form-group md:col-span-2">
                                    <label class="form-label text-xs">Address *</label>
                                    <textarea name="address" required class="form-input" rows="2" placeholder="Enter residential address" style="resize: none;"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Employment Information -->
                        <div class="mb-6">
                            <div class="form-section-title">Employment Information</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-group">
                                    <label class="form-label text-xs">Designation *</label>
                                    <input type="text" name="designation" required class="form-input" placeholder="e.g., Software Engineer">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Hire Date *</label>
                                    <input type="date" name="hire_date" required class="form-input">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Employment Type *</label>
                                    <select name="employment_type" required class="form-select">
                                        <option value="">Select Type</option>
                                        <option value="permanent">Permanent</option>
                                        <option value="contract">Contract</option>
                                        <option value="intern">Intern</option>
                                        <option value="casual">Casual</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Employee Type *</label>
                                    <select name="employee_type" required class="form-select">
                                        <option value="">Select Type</option>
                                        <option value="officer">Officer</option>
                                        <option value="sub_section_head">Sub-Section Head</option>
                                        <option value="section_head">Section Head</option>
                                        <option value="department_head">Department Head</option>
                                    </select>
                                </div>

                                <!-- Cascading Organizational Hierarchy -->
                                <div class="form-group">
                                    <label class="form-label text-xs">Department *</label>
                                    <select name="department_id" id="departmentSelect" required class="form-select">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Section *</label>
                                    <select name="section_id" id="sectionSelect" required class="form-select" disabled>
                                        <option value="">Select Section</option>
                                    </select>
                                    <div id="sectionLoading" class="text-xs text-gray-500 mt-1 hidden">
                                        <span class="loading-spinner"></span> Loading...
                                    </div>
                                </div>

                                <div class="form-group" id="subsectionGroup" style="display: none;">
                                    <label class="form-label text-xs">Sub-Section <span id="subsectionRequired" class="text-red-500">*</span></label>
                                    <select name="subsection_id" id="subsectionSelect" class="form-select" disabled>
                                        <option value="">Select Sub-Section</option>
                                    </select>
                                    <div id="subsectionLoading" class="text-xs text-gray-500 mt-1 hidden">
                                        <span class="loading-spinner"></span> Loading...
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Office *</label>
                                    <select name="office_id" required class="form-select">
                                        <option value="">Select Office</option>
                                        <?php foreach ($offices as $office): ?>
                                            <option value="<?= $office['id'] ?>"><?= htmlspecialchars($office['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Job Group</label>
                                    <select name="scale_id" class="form-select">
                                        <option value="">Select Grade</option>
                                        <?php
                                        $jobGroups = [
                                            '1' => 'Grade 1',
                                            '2' => 'Grade 2',
                                            '3' => 'Grade 3',
                                            '3A' => 'Grade 3A',
                                            '3B' => 'Grade 3B',
                                            '3C' => 'Grade 3C',
                                            '4' => 'Grade 4',
                                            '5' => 'Grade 5',
                                            '6' => 'Grade 6',
                                            '7' => 'Grade 7',
                                            '8' => 'Grade 8',
                                            '9' => 'Grade 9',
                                            '10' => 'Grade 10'
                                        ];
                                        foreach ($jobGroups as $grade => $label):
                                        ?>
                                            <option value="<?= $grade ?>"><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Salary</label>
                                    <input type="number" name="salary" step="0.01" class="form-input" placeholder="Enter salary">
                                </div>

                                <div class="form-group">
                                    <label class="form-label text-xs">Status *</label>
                                    <select name="employee_status" required class="form-select">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="on_leave">On Leave</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Next of Kin -->
                        <div class="mb-6">
                            <div class="form-section-title">Next of Kin</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-group md:col-span-2">
                                    <label class="form-label text-xs">Next of Kin Details</label>
                                    <textarea name="next_of_kin" class="form-input" rows="2" placeholder="Enter next of kin information (name, relationship, phone)" style="resize: none;"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
                            <a href="<?= BASE_URL ?>/?route=employees" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                                Cancel
                            </a>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                <i class="fas fa-save mr-1"></i> Save Employee
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

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

        document.getElementById('employeeForm').addEventListener('submit', function(e) {
            if (subsectionGroup.style.display === 'block' && !subsectionSelect.value) {
                e.preventDefault();
                alert('Please select a sub-section.');
                subsectionSelect.focus();
                return false;
            }
        });
    })();
    </script>
</body>
</html>