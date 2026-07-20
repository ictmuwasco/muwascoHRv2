 <?php

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

<!-- Edit Employee Modal -->
<div id="editEmployeeModal" class="modal-container" style="display: none;">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-edit mr-2"></i> Edit Employee
                </h5>
                <button type="button" class="close" onclick="closeEditModal()" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="editModalBody">
            </div>
        </div>
    </div>
</div>

<!-- Modal Backdrop -->
<div id="editModalBackdrop" class="modal-backdrop" style="display: none;"></div>

<script>
// Ensure modal is hidden on page load
$(document).ready(function() {
    $('#editEmployeeModal').hide();
    $('#editModalBackdrop').hide();
});

function closeEditModal() {
    $('#editEmployeeModal').hide();
    $('#editModalBackdrop').hide();
}

function showAddModal() {
    const modal = $('#editEmployeeModal');
    const modalBody = $('#editModalBody');
    modalBody.html('<div class="text-center py-8"><div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div></div>');
    modal.find('.modal-title').html('<i class="fas fa-user-plus mr-2"></i> Add New Employee');
    modal.show();
    $('#editModalBackdrop').show();
    
    // Fetch departments, sections, subsections, offices
    fetch('/hrdemo/api/organization/hierarchy')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                populateAddForm(data.data.departments, data.data.sections, data.data.subsections, data.data.offices);
            } else {
                modalBody.html('<div class="alert alert-danger">Failed to load form data</div>');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            modalBody.html('<div class="alert alert-danger">Error loading form data</div>');
        });
}

function populateAddForm(departments, sections, subsections, offices) {
    const modalBody = $('#editModalBody');
    const jobGroups = [
        {value:'1',label:'Grade 1'},{value:'2',label:'Grade 2'},{value:'3',label:'Grade 3'},
        {value:'3A',label:'Grade 3A'},{value:'3B',label:'Grade 3B'},{value:'3C',label:'Grade 3C'},
        {value:'4',label:'Grade 4'},{value:'5',label:'Grade 5'},{value:'6',label:'Grade 6'},
        {value:'7',label:'Grade 7'},{value:'8',label:'Grade 8'},{value:'9',label:'Grade 9'},
        {value:'10',label:'Grade 10'}
    ];
    
    modalBody.html(`
        <form action="<?= BASE_URL ?>/?route=employees/store" method="POST" id="addEmployeeForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <div class="mb-3">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Personal Information</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <div class="form-group"><label class="form-label text-xs">Employee ID *</label>
                        <input type="text" name="employee_id" required class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">First Name *</label>
                        <input type="text" name="first_name" required class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">Last Name *</label>
                        <input type="text" name="last_name" required class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">Surname</label>
                        <input type="text" name="surname" class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">Gender *</label>
                        <select name="gender" required class="form-select" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important">
                            <option value="">Select</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">National ID *</label>
                        <input type="text" name="national_id" required class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">Email *</label>
                        <input type="email" name="email" required class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">Phone *</label>
                        <input type="text" name="phone" required class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">Date of Birth *</label>
                        <input type="date" name="date_of_birth" required class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group md:col-span-2"><label class="form-label text-xs">Address *</label>
                        <textarea name="address" required class="form-input" rows="1" style="resize:none;padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></textarea></div>
                </div>
            </div>

            <div class="mb-3">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Employment Information</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <div class="form-group"><label class="form-label text-xs">Designation *</label>
                        <input type="text" name="designation" required class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">Hire Date *</label>
                        <input type="date" name="hire_date" required class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">Employment Type *</label>
                        <select name="employment_type" required class="form-select" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important">
                            <option value="">Select</option><option value="permanent">Permanent</option><option value="contract">Contract</option><option value="intern">Intern</option><option value="casual">Casual</option>
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Employee Role *</label>
                        <select name="employee_type" required class="form-select" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important">
                            <option value="">Select</option><option value="officer">Officer</option><option value="sub_section_head">Sub-Section Head</option><option value="section_head">Section Head</option><option value="department_head">Department Head</option>
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Department *</label>
                        <select name="department_id" required class="form-select" id="addDepartment" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important">
                            <option value="">Select</option>
                            ${departments.map(d=>'<option value="'+d.id+'">'+d.name+'</option>').join('')}
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Section *</label>
                        <select name="section_id" required class="form-select" id="addSection" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important">
                            <option value="">Select</option>
                            ${sections.map(s=>'<option value="'+s.id+'" data-dept="'+s.department_id+'">'+s.name+'</option>').join('')}
                        </select></div>
                    <div class="form-group" id="addSubsectionGroup" style="display:none">
                        <label class="form-label text-xs">Sub-Section</label>
                        <select name="subsection_id" class="form-select" id="addSubsection" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important">
                            <option value="">Select</option>
                            ${subsections.map(s=>'<option value="'+s.id+'" data-sec="'+s.section_id+'">'+s.name+'</option>').join('')}
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Office *</label>
                        <select name="office_id" required class="form-select" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important">
                            <option value="">Select</option>
                            ${offices.map(o=>'<option value="'+o.id+'">'+o.name+'</option>').join('')}
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Job Group</label>
                        <select name="scale_id" class="form-select" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important">
                            <option value="">Select Grade</option>
                            ${jobGroups.map(j=>'<option value="'+j.value+'">'+j.label+'</option>').join('')}
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Salary</label>
                        <input type="number" name="salary" step="0.01" class="form-input" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></div>
                    <div class="form-group"><label class="form-label text-xs">Status *</label>
                        <select name="employee_status" required class="form-select" style="padding:0.4rem 0.6rem!important;font-size:0.8rem!important">
                            <option value="active">Active</option><option value="inactive">Inactive</option><option value="on_leave">On Leave</option>
                        </select></div>
                </div>
            </div>

            <div class="mb-3">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Next of Kin</div>
                <div class="grid grid-cols-1 gap-2">
                    <div class="form-group"><label class="form-label text-xs">Next of Kin Details</label>
                        <textarea name="next_of_kin" class="form-input" rows="1" style="resize:none;padding:0.4rem 0.6rem!important;font-size:0.8rem!important"></textarea></div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-200">
                <button type="button" class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-800" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                    <i class="fas fa-save mr-1"></i> Save Employee</button>
            </div>
        </form>
    `);
    
    // Handle section filtering on department change
    $('#addDepartment').on('change', function() {
        const did = this.value;
        $('#addSection option').each(function() {
            $(this).toggle(!did || $(this).data('dept') == did);
        });
        $('#addSection').val('');
        $('#addSubsectionGroup').hide();
    });
    
    // Handle subsection filtering on section change
    $('#addSection').on('change', function() {
        const sid = this.value;
        $('#addSubsection option').each(function() {
            $(this).toggle(!sid || $(this).data('sec') == sid);
        });
        $('#addSubsection').val('');
        $('#addSubsectionGroup').toggle(sid && $('#addSubsection option:not([value=""]):visible').length > 0);
    });
    
    $('#addEmployeeForm').on('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fetch(this.action, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) { closeEditModal(); location.reload(); }
                else { alert(d.message || 'Failed to create employee'); }
            })
            .catch(err => { console.error(err); alert('Error creating employee'); });
    });
}

function openEditModal(employeeId) {
    const modal = $('#editEmployeeModal');
    const modalBody = $('#editModalBody');
    
    // Show modal with loading state
    modalBody.html('<div class="text-center py-8"><div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div></div>');
    modal.show();
    $('#editModalBackdrop').show();
    
    // Fetch employee data - use correct API endpoint
    const apiUrl = '/hrdemo/api/employees/modal-data?id=' + employeeId;
    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populateEditForm(data.employee, data.departments, data.sections, data.subsections, data.offices);
            } else {
                modalBody.html('<div class="alert alert-danger">Failed to load employee data</div>');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            modalBody.html('<div class="alert alert-danger">Error loading employee data</div>');
        });
}

function populateEditForm(employee, departments, sections, subsections, offices) {
    const modalBody = $('#editModalBody');
    const jobGroups = [
        {value:'1',label:'Grade 1'},{value:'2',label:'Grade 2'},{value:'3',label:'Grade 3'},
        {value:'3A',label:'Grade 3A'},{value:'3B',label:'Grade 3B'},{value:'3C',label:'Grade 3C'},
        {value:'4',label:'Grade 4'},{value:'5',label:'Grade 5'},{value:'6',label:'Grade 6'},
        {value:'7',label:'Grade 7'},{value:'8',label:'Grade 8'},{value:'9',label:'Grade 9'},
        {value:'10',label:'Grade 10'}
    ];
    
    modalBody.html(`
        <form action="<?= BASE_URL ?>/?route=employees/update/${employee.id}" method="POST" id="editEmployeeForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="id" value="${employee.id}">
            
            <div class="mb-4">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Personal Information</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="form-group"><label class="form-label text-xs">Employee ID *</label>
                        <input type="text" name="employee_id" value="${employee.employee_id||''}" required class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">First Name *</label>
                        <input type="text" name="first_name" value="${employee.first_name||''}" required class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">Last Name *</label>
                        <input type="text" name="last_name" value="${employee.last_name||''}" required class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">Surname</label>
                        <input type="text" name="surname" value="${employee.surname||''}" class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">Gender *</label>
                        <select name="gender" required class="form-select">
                            <option value="">Select</option>
                            <option value="male" ${employee.gender==='male'?'selected':''}>Male</option>
                            <option value="female" ${employee.gender==='female'?'selected':''}>Female</option>
                            <option value="other" ${employee.gender==='other'?'selected':''}>Other</option>
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">National ID *</label>
                        <input type="text" name="national_id" value="${employee.national_id||''}" required class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">Email *</label>
                        <input type="email" name="email" value="${employee.email||''}" required class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">Phone *</label>
                        <input type="text" name="phone" value="${employee.phone||''}" required class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">Date of Birth *</label>
                        <input type="date" name="date_of_birth" value="${employee.date_of_birth||''}" required class="form-input"></div>
                    <div class="form-group md:col-span-2"><label class="form-label text-xs">Address *</label>
                        <textarea name="address" required class="form-input" rows="2" style="resize:none;">${employee.address||''}</textarea></div>
                </div>
            </div>

            <div class="mb-4">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Employment Information</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="form-group"><label class="form-label text-xs">Designation *</label>
                        <input type="text" name="designation" value="${employee.designation||''}" required class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">Hire Date *</label>
                        <input type="date" name="hire_date" value="${employee.hire_date||''}" required class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">Employment Type *</label>
                        <select name="employment_type" required class="form-select">
                            <option value="">Select</option>
                            <option value="permanent" ${employee.employment_type==='permanent'?'selected':''}>Permanent</option>
                            <option value="contract" ${employee.employment_type==='contract'?'selected':''}>Contract</option>
                            <option value="intern" ${employee.employment_type==='intern'?'selected':''}>Intern</option>
                            <option value="casual" ${employee.employment_type==='casual'?'selected':''}>Casual</option>
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Employee Role *</label>
                        <select name="employee_type" required class="form-select">
                            <option value="">Select</option>
                            <option value="officer" ${employee.employee_type==='officer'?'selected':''}>Officer</option>
                            <option value="sub_section_head" ${employee.employee_type==='sub_section_head'?'selected':''}>Sub-Section Head</option>
                            <option value="section_head" ${employee.employee_type==='section_head'?'selected':''}>Section Head</option>
                            <option value="department_head" ${employee.employee_type==='department_head'?'selected':''}>Department Head</option>
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Department *</label>
                        <select name="department_id" required class="form-select" id="editDepartment">
                            <option value="">Select</option>
                            ${departments.map(d=>'<option value="'+d.id+'" '+(employee.department_id==d.id?'selected':'')+'>'+d.name+'</option>').join('')}
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Section *</label>
                        <select name="section_id" required class="form-select" id="editSection">
                            <option value="">Select</option>
                            ${sections.map(s=>'<option value="'+s.id+'" '+(employee.section_id==s.id?'selected':'')+'>'+s.name+'</option>').join('')}
                        </select></div>
                    <div class="form-group" id="editSubsectionGroup" style="${employee.subsection_id?'display:block':'display:none'}">
                        <label class="form-label text-xs">Sub-Section</label>
                        <select name="subsection_id" class="form-select" id="editSubsection">
                            <option value="">Select</option>
                            ${subsections.map(s=>'<option value="'+s.id+'" '+(employee.subsection_id==s.id?'selected':'')+'>'+s.name+'</option>').join('')}
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Office *</label>
                        <select name="office_id" required class="form-select">
                            <option value="">Select</option>
                            ${offices.map(o=>'<option value="'+o.id+'" '+(employee.office_id==o.id?'selected':'')+'>'+o.name+'</option>').join('')}
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Job Group</label>
                        <select name="scale_id" class="form-select">
                            <option value="">Select Grade</option>
                            ${jobGroups.map(j=>'<option value="'+j.value+'" '+(employee.scale_id==j.value?'selected':'')+'>'+j.label+'</option>').join('')}
                        </select></div>
                    <div class="form-group"><label class="form-label text-xs">Salary</label>
                        <input type="number" name="salary" step="0.01" value="${employee.salary||''}" class="form-input"></div>
                    <div class="form-group"><label class="form-label text-xs">Status *</label>
                        <select name="employee_status" required class="form-select">
                            <option value="active" ${employee.employee_status==='active'?'selected':''}>Active</option>
                            <option value="inactive" ${employee.employee_status==='inactive'?'selected':''}>Inactive</option>
                            <option value="on_leave" ${employee.employee_status==='on_leave'?'selected':''}>On Leave</option>
                        </select></div>
                </div>
            </div>

            <div class="mb-4">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Next of Kin</div>
                <div class="grid grid-cols-1 gap-3">
                    <div class="form-group"><label class="form-label text-xs">Next of Kin Details</label>
                        <textarea name="next_of_kin" class="form-input" rows="2" style="resize:none;">${employee.next_of_kin||''}</textarea></div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
                <button type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                    <i class="fas fa-save mr-1"></i> Update Employee</button>
            </div>
        </form>
    `);
    
    $('#editEmployeeForm').on('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fetch(this.action,{method:'POST',body:fd})
            .then(r=>r.json())
            .then(d=>{if(d.success){closeEditModal();location.reload()}else{alert(d.message||'Failed')}})
            .catch(e=>{console.error(e);alert('Error')});
    });
    
    $('#editDepartment').on('change', function() {
        const did=this.value;
        const ss=$('#editSection');
        ss.html('<option value="">Select</option>');
        $('#editSubsectionGroup').hide();
        if(!did)return;
        fetch('/hrdemo/api/organization/sections?department_id='+did)
            .then(r=>r.json())
            .then(d=>{if(d.success&&d.data.length>0)d.data.forEach(s=>ss.append('<option value="'+s.id+'" '+(employee.section_id==s.id?'selected':'')+'>'+s.name+'</option>'))});
    });
    
    $('#editSection').on('change', function() {
        const sid=this.value;
        const sub=$('#editSubsection');
        sub.html('<option value="">Select</option>');
        $('#editSubsectionGroup').hide();
        if(!sid)return;
        fetch('/hrdemo/api/organization/sub-sections?section_id='+sid)
            .then(r=>r.json())
            .then(d=>{if(d.success&&d.data.length>0){d.data.forEach(s=>sub.append('<option value="'+s.id+'" '+(employee.subsection_id==s.id?'selected':'')+'>'+s.name+'</option>'));$('#editSubsectionGroup').show()}});
    });
}
</script>

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
                                    <a href="<?= BASE_URL ?>/?route=personal&token=<?= htmlspecialchars($emp['profile_token'] ?? '') ?>"
                                       class="text-blue-600 hover:text-blue-800 transition-colors"
                                       title="View Profile">
                                          <i class="fas fa-eye"></i>
                                      </a>
                                      <button onclick="openEditModal(<?= $emp['id'] ?>)"
                                         class="text-amber-600 hover:text-amber-700 transition-colors"
                                         title="Edit">
                                          <i class="fas fa-edit"></i>
                                      </button>
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