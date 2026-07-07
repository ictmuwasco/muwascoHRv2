<?php
/**
 * Apply Leave View
 * Place: backend/app/Views/leave/apply.php
 * 
 * Variables passed from controller:
 *   $employees - array of employee records
 *   $userEmployee - current user's employee record or null
 *   $eligibleDelegates - array of eligible delegate employees
 *   $flash - flash message array or null
 *   $csrf_token - CSRF token string
 *   $leaveTypes - array of leave types [id => name]
 *   $studyLeaveId - ID of Study Leave type (0 if not found)
 *   $sickLeaveId - ID of Sick Leave type (0 if not found)
 */
$pageTitle = 'Apply Leave - HR Management System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen <?= ($_SESSION['theme'] ?? 'light') === 'light' ? 'bg-gray-50 text-gray-900' : 'bg-dark-bg text-white' ?>">

    <!-- Sidebar Navigation -->
    <?php require __DIR__ . '/../components/navbar.php'; ?>
    
    <!-- Top Header Bar -->
    <?php require __DIR__ . '/../components/header_bar.php'; ?>

<div class="lg:pl-64 pt-16 min-h-screen <?= ($_SESSION['theme'] ?? 'light') === 'light' ? 'bg-gray-50' : 'bg-dark-bg' ?>">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Apply for Leave</h1>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Submit a new leave application</p>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash): ?>
        <div class="px-6 py-4 rounded-2xl mb-6 border-2 backdrop-blur-sm
                    <?= $flash['type'] === 'success' ? 'bg-gradient-to-r from-success to-emerald-600 text-white border-success' : '' ?>
                    <?= $flash['type'] === 'error' ? 'bg-gradient-to-r from-error to-red-600 text-white border-error' : '' ?>
                    <?= $flash['type'] === 'warning' ? 'bg-gradient-to-r from-warning to-orange-500 text-white border-warning' : '' ?>">
            <i class="fas fa-info-circle mr-2"></i>
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="px-6 py-4 rounded-2xl mb-6 border-2 backdrop-blur-sm bg-gradient-to-r from-error to-red-600 text-white border-error">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <?= htmlspecialchars($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if ($userEmployee || !empty($employees)): ?>
        <form method="POST" action="/leave/apply/submit" id="leaveApplicationForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <!-- Leave Details Card -->
            <div class="bg-white/50 dark:bg-white/10 border border-gray-200 dark:border-white/20 rounded-2xl shadow-lg dark:shadow-2xl p-6 mb-6 backdrop-blur-xl">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">
                    <i class="fas fa-calendar-alt mr-2 text-primary-400"></i>Leave Details
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-2">Employee</label>
                        <input list="employee-options" id="employee_search" 
                               class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm"
                               placeholder="Type to search employees..."
                               value="<?= htmlspecialchars($userEmployee['first_name'] ?? '') ?>">
                        <datalist id="employee-options">
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= htmlspecialchars($emp['employee_id'].' - '.$emp['first_name'].' '.$emp['last_name']) ?>"
                                        data-value="<?= $emp['id'] ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <input type="hidden" id="employee_id" name="employee_id"
                               value="<?= $userEmployee['id'] ?? '' ?>">
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-2">Leave Type</label>
                        <select name="leave_type_id" id="leave_type_id" 
                                class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm"
                                required>
                            <option value="">Select Leave Type</option>
                            <?php foreach ($leaveTypes as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-2">Start Date</label>
                        <input type="date" name="start_date" id="start_date" 
                               class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm"
                               required>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-2">End Date</label>
                        <input type="date" name="end_date" id="end_date" 
                               class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm"
                               required>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-2">Calculated Days</label>
                        <input type="text" id="calculated_days" 
                               class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm"
                               readonly>
                    </div>
                </div>
            </div>

            <!-- Task Delegation Card -->
            <div class="bg-white/50 dark:bg-white/10 border border-gray-200 dark:border-white/20 rounded-2xl shadow-lg dark:shadow-2xl p-6 mb-6 backdrop-blur-xl">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                    <i class="fas fa-user-friends mr-2 text-primary-400"></i>Task Delegation
                </h3>
                <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Select a colleague to handle your responsibilities during your absence.</p>

                <div class="mb-4">
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-2">Delegate Tasks To <span class="text-error">*</span></label>
                    <select name="delegate_id" id="delegate_id" 
                            class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm"
                            required>
                        <option value="">-- Select a Delegate --</option>
                        <?php if (!empty($eligibleDelegates)): ?>
                            <?php foreach ($eligibleDelegates as $del): ?>
                                <option value="<?= $del['id'] ?>" 
                                        data-designation="<?= htmlspecialchars($del['designation']) ?>"
                                        data-department="<?= htmlspecialchars($del['department']) ?>"
                                        data-section="<?= htmlspecialchars($del['section']) ?>">
                                    <?= htmlspecialchars($del['name'] . ' (' . $del['designation'] . ') - ' . $del['department']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>No eligible delegates available in your hierarchy</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div id="delegate_info" class="hidden bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 rounded-lg p-4">
                    <div class="text-sm">
                        <strong class="text-gray-900 dark:text-white">Selected Delegate:</strong>
                        <p id="delegate_name_display" class="text-gray-700 dark:text-gray-300 mt-1"></p>
                        <p id="delegate_position_display" class="text-gray-700 dark:text-gray-300"></p>
                        <p id="delegate_department_display" class="text-gray-700 dark:text-gray-300"></p>
                    </div>
                </div>
            </div>

            <!-- Additional Information Card -->
            <div class="bg-white/50 dark:bg-white/10 border border-gray-200 dark:border-white/20 rounded-2xl shadow-lg dark:shadow-2xl p-6 mb-6 backdrop-blur-xl">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">
                    <i class="fas fa-info-circle mr-2 text-primary-400"></i>Additional Information
                </h3>

                <div class="mb-4">
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-2">Reason for Leave</label>
                    <textarea name="reason" id="reason" rows="4"
                              class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm"></textarea>
                </div>

                <!-- Study Timetable Upload (for Study Leave) -->
                <div id="study_timetable_section" class="hidden mb-4">
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-2">Study Timetable <span class="text-error">*</span></label>
                    <input type="file" name="study_timetable" id="study_timetable" 
                           class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm"
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    <small class="text-gray-600 dark:text-gray-500 text-xs mt-1 block">Upload your study timetable (PDF, DOC, DOCX, JPG, JPEG, PNG). Max size: 5MB.</small>
                    <div id="study_timetable_error" class="text-error text-sm mt-1"></div>
                </div>

                <!-- Medical Certificate Upload (for Sick Leave) -->
                <div id="medical_certificate_section" class="hidden mb-4">
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-2">Medical Certificate / Sick Sheet <span class="text-error">*</span></label>
                    <input type="file" name="medical_certificate" id="medical_certificate" 
                           class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm"
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    <small class="text-gray-600 dark:text-gray-500 text-xs mt-1 block">Upload your medical certificate (PDF, DOC, DOCX, JPG, JPEG, PNG). Max size: 5MB.</small>
                    <div id="medical_certificate_error" class="text-error text-sm mt-1"></div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-4">
                <button type="submit" class="px-6 py-3 bg-gradient-to-r from-primary-400 to-primary-600 text-white rounded-lg font-semibold hover:shadow-lg hover:shadow-primary-400/50 transition-all duration-300">
                    <i class="fas fa-paper-plane mr-2"></i>Submit Application
                </button>
                <button type="reset" class="px-6 py-3 bg-gray-200 dark:bg-white/5 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-white/10 transition-all duration-300">
                    <i class="fas fa-undo mr-2"></i>Reset
                </button>
            </div>
        </form>
    <?php else: ?>
        <div class="bg-yellow-50 dark:bg-white/10 border border-yellow-200 dark:border-warning/50 rounded-2xl shadow-lg dark:shadow-2xl p-6 backdrop-blur-xl">
            <div class="flex items-center">
                <i class="fas fa-exclamation-triangle text-warning text-2xl mr-4"></i>
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Account Not Linked</h3>
                    <p class="text-gray-700 dark:text-gray-400 mt-1">Your user account is not linked to an employee record. Please contact HR.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const delegateSelect = document.getElementById('delegate_id');
    const delegateInfo = document.getElementById('delegate_info');
    const delegateNameDisplay = document.getElementById('delegate_name_display');
    const delegatePositionDisplay = document.getElementById('delegate_position_display');
    const delegateDeptDisplay = document.getElementById('delegate_department_display');
    const employeeSearch = document.getElementById('employee_search');
    const employeeIdInput = document.getElementById('employee_id');
    const employeeOptions = document.getElementById('employee-options');
    const submitBtn = document.querySelector('button[type="submit"]');

    const STUDY_LEAVE_ID = Number(<?php echo (int)($studyLeaveId ?? 0); ?>);
    const SICK_LEAVE_ID = Number(<?php echo (int)($sickLeaveId ?? 0); ?>);

    function setHidden(el, hidden) {
        if (!el) return;
        el.classList.toggle('hidden', hidden);
    }

    if (delegateSelect && delegateInfo && delegateNameDisplay && delegatePositionDisplay && delegateDeptDisplay) {
        delegateSelect.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            if (selected && selected.value) {
                delegateNameDisplay.textContent = 'Name: ' + selected.textContent.split('(')[0].trim();
                delegatePositionDisplay.textContent = 'Position: ' + (selected.dataset.designation || 'N/A');
                delegateDeptDisplay.textContent = 'Department: ' + (selected.dataset.department || 'N/A') +
                    (selected.dataset.section ? ' / Section: ' + selected.dataset.section : '');
                setHidden(delegateInfo, false);
            } else {
                setHidden(delegateInfo, true);
            }
        });

        if (employeeSearch && employeeIdInput && employeeOptions) {
            employeeSearch.addEventListener('change', function () {
                for (let opt of employeeOptions.options) {
                    if (opt.value === this.value) {
                        const empId = opt.getAttribute('data-value');
                        employeeIdInput.value = empId;
                        loadDelegates(empId);
                        break;
                    }
                }
            });
        }

        function loadDelegates(employeeId) {
            if (!employeeId || !delegateSelect) return;
            fetch(`/leave/apply/ajax?action=get_eligible_delegates&employee_id=${employeeId}`)
                .then(r => r.json())
                .then(data => {
                    delegateSelect.innerHTML = '<option value="">-- Select a Delegate --</option>';
                    if (data.length > 0) {
                        data.forEach(d => {
                            const opt = document.createElement('option');
                            opt.value = d.id;
                            opt.textContent = `${d.name} (${d.designation}) - ${d.department}`;
                            opt.dataset.designation = d.designation;
                            opt.dataset.department = d.department;
                            opt.dataset.section = d.section;
                            delegateSelect.appendChild(opt);
                        });
                        delegateSelect.disabled = false;
                    } else {
                        delegateSelect.innerHTML += '<option value="" disabled>No eligible delegates available</option>';
                        delegateSelect.disabled = true;
                    }
                })
                .catch(err => {
                    console.error('Error loading delegates:', err);
                });
        }

        const leaveTypeSelect = document.getElementById('leave_type_id');
        const studySection = document.getElementById('study_timetable_section');
        const medicalSection = document.getElementById('medical_certificate_section');
        const studyInput = document.getElementById('study_timetable');
        const medicalInput = document.getElementById('medical_certificate');
        const studyError = document.getElementById('study_timetable_error');
        const medicalError = document.getElementById('medical_certificate_error');

        if (leaveTypeSelect && studySection && medicalSection && studyInput && medicalInput) {
            leaveTypeSelect.addEventListener('change', function () {
                const selectedType = parseInt(this.value, 10);

                setHidden(studySection, true);
                setHidden(medicalSection, true);
                studyInput.required = false;
                medicalInput.required = false;
                studyInput.value = '';
                medicalInput.value = '';

                if (studyError) studyError.textContent = '';
                if (medicalError) medicalError.textContent = '';

                if (STUDY_LEAVE_ID > 0 && selectedType === STUDY_LEAVE_ID) {
                    setHidden(studySection, false);
                    studyInput.required = true;
                } else if (SICK_LEAVE_ID > 0 && selectedType === SICK_LEAVE_ID) {
                    setHidden(medicalSection, false);
                    medicalInput.required = true;
                }
            });
        }

        const form = document.getElementById('leaveApplicationForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!delegateSelect.value) {
                    e.preventDefault();
                    alert('Please select a delegate to handle your responsibilities during your absence.');
                    delegateSelect.focus();
                    return;
                }

                const leaveTypeId = parseInt(document.getElementById('leave_type_id')?.value || '0', 10);
                const studyTimetable = document.getElementById('study_timetable');
                const medicalCertificate = document.getElementById('medical_certificate');

                if (studyError) studyError.textContent = '';
                if (medicalError) medicalError.textContent = '';

                if (STUDY_LEAVE_ID > 0 && leaveTypeId === STUDY_LEAVE_ID) {
                    if (!studyTimetable?.files || studyTimetable.files.length === 0) {
                        e.preventDefault();
                        if (studyError) studyError.textContent = 'Please upload your study timetable.';
                        studyTimetable?.focus();
                        return;
                    }
                    if (studyTimetable.files[0].size > 5 * 1024 * 1024) {
                        e.preventDefault();
                        if (studyError) studyError.textContent = 'Study timetable file size must not exceed 5MB.';
                        studyTimetable.focus();
                        return;
                    }
                    const allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                    const fileExtension = studyTimetable.files[0].name.split('.').pop().toLowerCase();
                    if (!allowedExtensions.includes(fileExtension)) {
                        e.preventDefault();
                        if (studyError) studyError.textContent = 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, JPEG, PNG.';
                        studyTimetable.focus();
                        return;
                    }
                }

                if (SICK_LEAVE_ID > 0 && leaveTypeId === SICK_LEAVE_ID) {
                    if (!medicalCertificate?.files || medicalCertificate.files.length === 0) {
                        e.preventDefault();
                        if (medicalError) medicalError.textContent = 'Please upload your medical certificate.';
                        medicalCertificate?.focus();
                        return;
                    }
                    if (medicalCertificate.files[0].size > 5 * 1024 * 1024) {
                        e.preventDefault();
                        if (medicalError) medicalError.textContent = 'Medical certificate file size must not exceed 5MB.';
                        medicalCertificate.focus();
                        return;
                    }
                    const allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                    const fileExtension = medicalCertificate.files[0].name.split('.').pop().toLowerCase();
                    if (!allowedExtensions.includes(fileExtension)) {
                        e.preventDefault();
                        if (medicalError) medicalError.textContent = 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, JPEG, PNG.';
                        medicalCertificate.focus();
                        return;
                    }
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting...';
                }
            });
        }
    }
});
</script>
</div>
</body>
</html>
