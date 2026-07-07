/**
 * MUWASCO HR System - Page Loaders
 *
 * Each function loads and renders a full page module via the API.
 */

// ── Attendance Management ────────────────────────────────────────────────
async function loadAttendance() {
  const mainContent = document.getElementById("main-content");
  UI.showLoader(mainContent);

  try {
    const result = await API.get("/attendance/today");
    const records = result.success ? result.data?.records || [] : [];

    mainContent.innerHTML = `
      <div class="space-y-6">
        <div id="alerts-container"></div>
        
        <div class="flex justify-between items-center">
          <h2 class="text-xl font-semibold">Attendance Management</h2>
          <div class="flex gap-2">
            <button onclick="showClockInModal()" class="btn btn-success">
              <i class="fas fa-fingerprint"></i> Clock In
            </button>
            <button onclick="showClockOutModal()" class="btn btn-warning">
              <i class="fas fa-fingerprint"></i> Clock Out
            </button>
          </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
          <div class="stat-card">
            <div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-check-circle"></i></div>
            <div><div class="stat-value">${records.filter((r) => r.status === "clocked_in").length}</div><div class="stat-label">Clocked In</div></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-clock"></i></div>
            <div><div class="stat-value">${records.filter((r) => r.status === "clocked_out").length}</div><div class="stat-label">Clocked Out</div></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon bg-yellow-100 text-yellow-600"><i class="fas fa-exclamation-triangle"></i></div>
            <div><div class="stat-value">${records.filter((r) => r.is_late == 1).length}</div><div class="stat-label">Late</div></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon bg-gray-100 text-gray-600"><i class="fas fa-users"></i></div>
            <div><div class="stat-value">${records.length}</div><div class="stat-label">Total Today</div></div>
          </div>
        </div>

        <!-- Records Table -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Today's Attendance Records</h3>
            <a href="attendance_report.php" class="text-sm text-primary-600 hover:text-primary-800">
              <i class="fas fa-external-link-alt"></i> Full Report
            </a>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Department</th>
                  <th>Office</th>
                  <th>Clock In</th>
                  <th>Clock Out</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                ${
                  records.length === 0
                    ? '<tr><td colspan="6" class="text-center py-4">No records for today</td></tr>'
                    : records
                        .map(
                          (r) => `
                    <tr>
                      <td>${r.first_name} ${r.last_name}</td>
                      <td>${r.department || "-"}</td>
                      <td>${r.office_name || "-"}</td>
                      <td>${UI.formatTime(r.clock_in)}</td>
                      <td>${r.clock_out ? UI.formatTime(r.clock_out) : "-"}</td>
                      <td><span class="badge ${UI.statusBadge(r.status)}">${UI.formatStatus(r.status)}</span></td>
                    </tr>
                  `,
                        )
                        .join("")
                }
              </tbody>
            </table>
          </div>
        </div>
      </div>
    `;
  } catch (error) {
    UI.showError(mainContent, "Failed to load attendance data.");
  }
}

// ── Leave Management ─────────────────────────────────────────────────────
async function loadLeaveManagement() {
  const mainContent = document.getElementById("main-content");
  UI.showLoader(mainContent);

  try {
    const [leavesResult, typesResult] = await Promise.all([
      API.get("/leaves"),
      API.get("/leaves/types"),
    ]);

    const leaves = leavesResult.success ? leavesResult.data?.data || [] : [];
    const types = typesResult.success ? typesResult.data || [] : [];

    mainContent.innerHTML = `
      <div class="space-y-6">
        <div id="alerts-container"></div>
        
        <div class="flex justify-between items-center">
          <h2 class="text-xl font-semibold">Leave Management</h2>
          <button onclick="showLeaveModal()" class="btn btn-primary">
            <i class="fas fa-plus"></i> Apply for Leave
          </button>
        </div>

        <!-- Leave Types -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          ${types
            .map(
              (t) => `
            <div class="card text-center">
              <div class="text-2xl font-bold text-primary-600">${t.days_allowed}</div>
              <div class="text-sm text-gray-500">${t.name}</div>
            </div>
          `,
            )
            .join("")}
        </div>

        <!-- Leave Requests Table -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Leave Requests</h3>
            <a href="leave_management.php" class="text-sm text-primary-600 hover:text-primary-800">
              <i class="fas fa-external-link-alt"></i> Full Management
            </a>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Type</th>
                  <th>Start Date</th>
                  <th>End Date</th>
                  <th>Days</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                ${
                  leaves.length === 0
                    ? '<tr><td colspan="6" class="text-center py-4">No leave requests</td></tr>'
                    : leaves
                        .map(
                          (l) => `
                    <tr>
                      <td>${l.first_name} ${l.last_name}</td>
                      <td>${l.leave_type_name || "-"}</td>
                      <td>${UI.formatDate(l.start_date)}</td>
                      <td>${UI.formatDate(l.end_date)}</td>
                      <td>${Math.ceil((new Date(l.end_date) - new Date(l.start_date)) / (1000 * 60 * 60 * 24)) + 1}</td>
                      <td><span class="badge ${UI.statusBadge(l.status)}">${UI.formatStatus(l.status)}</span></td>
                    </tr>
                  `,
                        )
                        .join("")
                }
              </tbody>
            </table>
          </div>
        </div>
      </div>
    `;
  } catch (error) {
    UI.showError(mainContent, "Failed to load leave data.");
  }
}

// ── Employee Management ──────────────────────────────────────────────────
async function loadEmployees() {
  const mainContent = document.getElementById("main-content");
  UI.showLoader(mainContent);

  try {
    const [empResult, refResult] = await Promise.all([
      API.get("/employees", { page: 1, per_page: 30 }),
      API.get("/employees/reference"),
    ]);

    const employees = empResult.success ? empResult.data?.data || [] : [];
    const pagination = empResult.success ? empResult.data : {};
    const refs = refResult.success ? refResult.data || {} : {};

    mainContent.innerHTML = `
      <div class="space-y-6">
        <div id="alerts-container"></div>
        
        <div class="flex justify-between items-center">
          <h2 class="text-xl font-semibold">Employee Management</h2>
          <button onclick="showAddEmployeeModal()" class="btn btn-success">
            <i class="fas fa-plus"></i> Add Employee
          </button>
        </div>

        <!-- Search & Filters -->
        <div class="card">
          <div class="flex flex-wrap gap-4">
            <div class="flex-1 min-w-[200px]">
              <input type="text" id="emp-search" class="form-input" placeholder="Search by name, ID, or email..." 
                     onkeyup="if(event.key==='Enter') searchEmployees()">
            </div>
            <select id="emp-department" class="form-select w-48" onchange="searchEmployees()">
              <option value="">All Departments</option>
              ${(refs.departments || []).map((d) => `<option value="${d.id}">${d.name}</option>`).join("")}
            </select>
            <select id="emp-type" class="form-select w-48" onchange="searchEmployees()">
              <option value="">All Types</option>
              ${Object.entries(refs.employee_types || {})
                .map(([k, v]) => `<option value="${k}">${v}</option>`)
                .join("")}
            </select>
            <select id="emp-status" class="form-select w-48" onchange="searchEmployees()">
              <option value="">All Status</option>
              ${Object.entries(refs.employee_statuses || {})
                .map(([k, v]) => `<option value="${k}">${v}</option>`)
                .join("")}
            </select>
            <button onclick="searchEmployees()" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
          </div>
        </div>

        <!-- Employees Table -->
        <div class="card">
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Department</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                ${
                  employees.length === 0
                    ? '<tr><td colspan="7" class="text-center py-4">No employees found</td></tr>'
                    : employees
                        .map(
                          (e) => `
                    <tr>
                      <td>${e.employee_id}</td>
                      <td>${e.first_name} ${e.last_name}</td>
                      <td>${e.email || "-"}</td>
                      <td>${e.department_name || "-"}</td>
                      <td><span class="badge badge-info">${e.employee_type ? UI.formatStatus(e.employee_type) : "-"}</span></td>
                      <td><span class="badge ${e.employee_status === "active" ? "badge-success" : "badge-danger"}">${e.employee_status || "N/A"}</span></td>
                      <td>
                        <button onclick="editEmployee(${e.id})" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i></button>
                        <a href="personal_profile.php?token=${e.profile_token}" class="btn btn-info btn-sm"><i class="fas fa-eye"></i></a>
                        <button onclick="deleteEmployee(${e.id}, '${e.first_name} ${e.last_name}')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                      </td>
                    </tr>
                  `,
                        )
                        .join("")
                }
              </tbody>
            </table>
          </div>
          ${
            pagination.last_page > 1
              ? `
            <div class="pagination mt-4">
              ${Array.from({ length: pagination.last_page }, (_, i) => i + 1)
                .map(
                  (p) =>
                    `<a href="#" onclick="loadPage('employees', ${p})" class="${p === pagination.page ? "active" : ""}">${p}</a>`,
                )
                .join("")}
            </div>
          `
              : ""
          }
        </div>
      </div>
    `;
  } catch (error) {
    UI.showError(mainContent, "Failed to load employees.");
  }
}

// ── Payroll Management ───────────────────────────────────────────────────
async function loadPayroll() {
  const mainContent = document.getElementById("main-content");
  mainContent.innerHTML = `
    <div class="space-y-6">
      <div class="flex justify-between items-center">
        <h2 class="text-xl font-semibold">Payroll Management</h2>
        <a href="payroll.php" class="btn btn-primary"><i class="fas fa-external-link-alt"></i> Open Payroll</a>
      </div>
      <div class="card">
        <p class="text-gray-500">Payroll module is being migrated. Use the legacy interface for full functionality.</p>
      </div>
    </div>
  `;
}

// ── Complaints Management ────────────────────────────────────────────────
async function loadComplaints() {
  const mainContent = document.getElementById("main-content");
  UI.showLoader(mainContent);

  try {
    const result = await API.get("/complaints");
    const complaints = result.success ? result.data?.data || [] : [];

    mainContent.innerHTML = `
      <div class="space-y-6">
        <div id="alerts-container"></div>
        <div class="flex justify-between items-center">
          <h2 class="text-xl font-semibold">Complaints Management</h2>
          <button onclick="showComplaintModal()" class="btn btn-primary"><i class="fas fa-plus"></i> Submit Complaint</button>
        </div>
        <div class="card">
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr><th>#</th><th>Employee</th><th>Category</th><th>Subject</th><th>Priority</th><th>Status</th><th>Date</th></tr>
              </thead>
              <tbody>
                ${
                  complaints.length === 0
                    ? '<tr><td colspan="7" class="text-center py-4">No complaints</td></tr>'
                    : complaints
                        .map(
                          (c) => `
                    <tr>
                      <td>#${c.id}</td>
                      <td>${c.first_name} ${c.last_name}</td>
                      <td>${c.category_name || "-"}</td>
                      <td>${c.subject}</td>
                      <td><span class="badge ${c.priority === "urgent" ? "badge-danger" : c.priority === "high" ? "badge-warning" : "badge-info"}">${c.priority}</span></td>
                      <td><span class="badge ${UI.statusBadge(c.status)}">${UI.formatStatus(c.status)}</span></td>
                      <td>${UI.formatDate(c.created_at)}</td>
                    </tr>
                  `,
                        )
                        .join("")
                }
              </tbody>
            </table>
          </div>
        </div>
      </div>
    `;
  } catch (error) {
    UI.showError(mainContent, "Failed to load complaints.");
  }
}

// ── Performance Appraisal ────────────────────────────────────────────────
async function loadAppraisals() {
  const mainContent = document.getElementById("main-content");
  mainContent.innerHTML = `
    <div class="space-y-6">
      <div class="flex justify-between items-center">
        <h2 class="text-xl font-semibold">Performance Appraisal</h2>
        <a href="performance_appraisal.php" class="btn btn-primary"><i class="fas fa-external-link-alt"></i> Open Appraisals</a>
      </div>
      <div class="card">
        <p class="text-gray-500">Appraisal module is being migrated. Use the legacy interface for full functionality.</p>
      </div>
    </div>
  `;
}

// ── Reports ──────────────────────────────────────────────────────────────
async function loadReports() {
  const mainContent = document.getElementById("main-content");
  mainContent.innerHTML = `
    <div class="space-y-6">
      <h2 class="text-xl font-semibold">Reports</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="attendance_report.php" class="card hover:shadow-lg transition-shadow">
          <div class="text-center py-4">
            <i class="fas fa-fingerprint text-3xl text-primary-600 mb-2"></i>
            <h3 class="font-semibold">Attendance Report</h3>
            <p class="text-sm text-gray-500">View attendance records and statistics</p>
          </div>
        </a>
        <a href="leave_report.php" class="card hover:shadow-lg transition-shadow">
          <div class="text-center py-4">
            <i class="fas fa-calendar-alt text-3xl text-green-600 mb-2"></i>
            <h3 class="font-semibold">Leave Report</h3>
            <p class="text-sm text-gray-500">Leave balances and usage reports</p>
          </div>
        </a>
        <a href="reports.php" class="card hover:shadow-lg transition-shadow">
          <div class="text-center py-4">
            <i class="fas fa-chart-bar text-3xl text-purple-600 mb-2"></i>
            <h3 class="font-semibold">HR Reports</h3>
            <p class="text-sm text-gray-500">Employee and department analytics</p>
          </div>
        </a>
        <a href="appraisal_report.php" class="card hover:shadow-lg transition-shadow">
          <div class="text-center py-4">
            <i class="fas fa-star text-3xl text-yellow-600 mb-2"></i>
            <h3 class="font-semibold">Appraisal Report</h3>
            <p class="text-sm text-gray-500">Performance appraisal summaries</p>
          </div>
        </a>
        <a href="audit_dashboard.php" class="card hover:shadow-lg transition-shadow">
          <div class="text-center py-4">
            <i class="fas fa-history text-3xl text-red-600 mb-2"></i>
            <h3 class="font-semibold">Audit Trail</h3>
            <p class="text-sm text-gray-500">System activity and change logs</p>
          </div>
        </a>
        <a href="documentation_report.php" class="card hover:shadow-lg transition-shadow">
          <div class="text-center py-4">
            <i class="fas fa-file-alt text-3xl text-indigo-600 mb-2"></i>
            <h3 class="font-semibold">Documentation</h3>
            <p class="text-sm text-gray-500">System documentation and guides</p>
          </div>
        </a>
      </div>
    </div>
  `;
}

// ── User Management ──────────────────────────────────────────────────────
async function loadUsers() {
  const mainContent = document.getElementById("main-content");
  mainContent.innerHTML = `
    <div class="space-y-6">
      <div class="flex justify-between items-center">
        <h2 class="text-xl font-semibold">User Management</h2>
        <a href="users.php" class="btn btn-primary"><i class="fas fa-external-link-alt"></i> Manage Users</a>
      </div>
      <div class="card">
        <p class="text-gray-500">User management module is being migrated. Use the legacy interface for full functionality.</p>
      </div>
    </div>
  `;
}

// ── Roles & Permissions ──────────────────────────────────────────────────
async function loadRoles() {
  const mainContent = document.getElementById("main-content");
  UI.showLoader(mainContent);

  try {
    const [rolesResult, modulesResult, actionsResult] = await Promise.all([
      API.get("/roles"),
      API.get("/modules"),
      API.get("/actions"),
    ]);

    const roles = rolesResult.success ? rolesResult.data || {} : {};
    const modules = modulesResult.success ? modulesResult.data || {} : {};
    const actions = actionsResult.success ? actionsResult.data || {} : {};

    mainContent.innerHTML = `
      <div class="space-y-6">
        <h2 class="text-xl font-semibold">Roles & Permissions</h2>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          ${Object.entries(roles)
            .map(
              ([role, label]) => `
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">${label}</h3>
                <span class="badge badge-info">${role}</span>
              </div>
              <div class="space-y-2">
                ${Object.entries(modules)
                  .map(
                    ([modKey, modLabel]) => `
                  <div class="flex items-center justify-between py-1 border-b border-gray-100 last:border-0">
                    <span class="text-sm">${modLabel}</span>
                    <div class="flex gap-1">
                      ${Object.entries(actions)
                        .map(
                          ([actKey, actLabel]) => `
                        <span class="text-xs px-1.5 py-0.5 rounded ${true ? "bg-green-100 text-green-700" : "bg-gray-100 text-gray-400"}">
                          ${actLabel.substring(0, 3)}
                        </span>
                      `,
                        )
                        .join("")}
                    </div>
                  </div>
                `,
                  )
                  .join("")}
              </div>
            </div>
          `,
            )
            .join("")}
        </div>
      </div>
    `;
  } catch (error) {
    UI.showError(mainContent, "Failed to load roles.");
  }
}

// ── Audit Trail ──────────────────────────────────────────────────────────
async function loadAudit() {
  const mainContent = document.getElementById("main-content");
  UI.showLoader(mainContent);

  try {
    const result = await API.get("/audit/logs", { limit: 50 });
    const logs = result.success ? result.data || [] : [];

    mainContent.innerHTML = `
      <div class="space-y-6">
        <div class="flex justify-between items-center">
          <h2 class="text-xl font-semibold">Audit Trail</h2>
          <a href="audit_dashboard.php" class="btn btn-primary"><i class="fas fa-external-link-alt"></i> Full Audit Dashboard</a>
        </div>
        <div class="card">
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr><th>Time</th><th>User</th><th>Action</th><th>Description</th><th>IP Address</th></tr>
              </thead>
              <tbody>
                ${
                  logs.length === 0
                    ? '<tr><td colspan="5" class="text-center py-4">No audit logs</td></tr>'
                    : logs
                        .map(
                          (l) => `
                    <tr>
                      <td class="text-xs">${UI.formatDateTime(l.created_at)}</td>
                      <td>${l.username || "System"}</td>
                      <td><span class="badge badge-info">${l.action_type}</span></td>
                      <td class="max-w-xs truncate">${l.description}</td>
                      <td class="text-xs">${l.ip_address}</td>
                    </tr>
                  `,
                        )
                        .join("")
                }
              </tbody>
            </table>
          </div>
        </div>
      </div>
    `;
  } catch (error) {
    UI.showError(mainContent, "Failed to load audit logs.");
  }
}

// ── Settings ─────────────────────────────────────────────────────────────
async function loadSettings() {
  const mainContent = document.getElementById("main-content");
  mainContent.innerHTML = `
    <div class="space-y-6">
      <h2 class="text-xl font-semibold">System Settings</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="card">
          <h3 class="card-title mb-4">General Settings</h3>
          <a href="email_config.php" class="btn btn-secondary w-full justify-start mb-2"><i class="fas fa-envelope"></i> Email Configuration</a>
          <a href="holidays.php" class="btn btn-secondary w-full justify-start mb-2"><i class="fas fa-calendar-day"></i> Holiday Management</a>
          <a href="departments.php" class="btn btn-secondary w-full justify-start"><i class="fas fa-building"></i> Department Management</a>
        </div>
        <div class="card">
          <h3 class="card-title mb-4">System Info</h3>
          <div class="space-y-2 text-sm">
            <p><strong>Version:</strong> 2.0.0 (Refactored)</p>
            <p><strong>PHP Version:</strong> ${await getPHPVersion()}</p>
            <p><strong>Database:</strong> MySQL/MariaDB</p>
            <p><strong>Environment:</strong> ${document.querySelector('meta[name="env"]')?.content || "Production"}</p>
          </div>
        </div>
      </div>
    </div>
  `;
}

// ── Helper Functions ─────────────────────────────────────────────────────
async function getPHPVersion() {
  try {
    const result = await API.get("/settings");
    return "8.2+";
  } catch {
    return "Unknown";
  }
}

function searchEmployees() {
  loadEmployees();
}

function showClockInModal() {
  UI.showModal(
    "Clock In",
    `<p class="text-gray-600">Confirm your clock-in for today?</p>
     <p class="text-sm text-gray-500 mt-2">Time: ${new Date().toLocaleTimeString()}</p>`,
    `<button onclick="this.closest('.modal-overlay').remove(); doClockIn()" class="btn btn-success">Clock In</button>
     <button onclick="this.closest('.modal-overlay').remove()" class="btn btn-secondary">Cancel</button>`,
  );
}

function showClockOutModal() {
  UI.showModal(
    "Clock Out",
    `<p class="text-gray-600">Confirm your clock-out for today?</p>
     <p class="text-sm text-gray-500 mt-2">Time: ${new Date().toLocaleTimeString()}</p>`,
    `<button onclick="this.closest('.modal-overlay').remove(); doClockOut()" class="btn btn-warning">Clock Out</button>
     <button onclick="this.closest('.modal-overlay').remove()" class="btn btn-secondary">Cancel</button>`,
  );
}

async function doClockIn() {
  const result = await API.post("/attendance/clock-in", { lat: 0, lng: 0 });
  if (result.success) {
    UI.showAlert("Clocked in successfully!", "success");
    loadAttendance();
  } else {
    UI.showAlert(result.message || "Failed to clock in", "danger");
  }
}

async function doClockOut() {
  const result = await API.post("/attendance/clock-out");
  if (result.success) {
    UI.showAlert("Clocked out successfully!", "success");
    loadAttendance();
  } else {
    UI.showAlert(result.message || "Failed to clock out", "danger");
  }
}

function showLeaveModal() {
  UI.showModal(
    "Apply for Leave",
    `<form id="leave-form" class="space-y-4">
      <div class="form-group">
        <label class="form-label">Leave Type</label>
        <select class="form-select" id="leave-type" required>
          <option value="">Select Type</option>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div class="form-group">
          <label class="form-label">Start Date</label>
          <input type="date" class="form-input" id="leave-start" required>
        </div>
        <div class="form-group">
          <label class="form-label">End Date</label>
          <input type="date" class="form-input" id="leave-end" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Reason</label>
        <textarea class="form-input" id="leave-reason" rows="3"></textarea>
      </div>
    </form>`,
    `<button onclick="submitLeave()" class="btn btn-primary">Submit</button>
     <button onclick="this.closest('.modal-overlay').remove()" class="btn btn-secondary">Cancel</button>`,
  );

  // Load leave types
  API.get("/leaves/types").then((result) => {
    if (result.success) {
      const select = document.getElementById("leave-type");
      (result.data || []).forEach((t) => {
        select.innerHTML += `<option value="${t.id}">${t.name} (${t.days_allowed} days)</option>`;
      });
    }
  });
}

async function submitLeave() {
  const data = {
    leave_type_id: document.getElementById("leave-type").value,
    start_date: document.getElementById("leave-start").value,
    end_date: document.getElementById("leave-end").value,
    reason: document.getElementById("leave-reason").value,
  };

  if (!data.leave_type_id || !data.start_date || !data.end_date) {
    UI.showAlert("Please fill all required fields", "danger");
    return;
  }

  const result = await API.post("/leaves", data);
  if (result.success) {
    UI.showAlert("Leave request submitted!", "success");
    document.querySelector(".modal-overlay")?.remove();
    loadLeaveManagement();
  } else {
    UI.showAlert(result.message || "Failed to submit leave", "danger");
  }
}

function showAddEmployeeModal() {
  UI.showModal(
    "Add New Employee",
    `<p class="text-gray-600">Use the full employee management form for complete data entry.</p>`,
    `<a href="employees.php" class="btn btn-primary"><i class="fas fa-external-link-alt"></i> Open Employee Form</a>
     <button onclick="this.closest('.modal-overlay').remove()" class="btn btn-secondary">Cancel</button>`,
  );
}

function editEmployee(id) {
  window.location.href = `employees.php?edit_id=${id}`;
}

function deleteEmployee(id, name) {
  UI.confirm(
    `Delete employee ${name}? This action cannot be undone.`,
    async () => {
      const result = await API.delete(`/employees/${id}`);
      if (result.success) {
        UI.showAlert("Employee deleted successfully", "success");
        loadEmployees();
      } else {
        UI.showAlert(result.message || "Failed to delete employee", "danger");
      }
    },
  );
}

function showComplaintModal() {
  UI.showModal(
    "Submit Complaint",
    `<form id="complaint-form" class="space-y-4">
      <div class="form-group">
        <label class="form-label">Subject</label>
        <input type="text" class="form-input" id="complaint-subject" required>
      </div>
      <div class="form-group">
        <label class="form-label">Category</label>
        <select class="form-select" id="complaint-category">
          <option value="">Select Category</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Priority</label>
        <select class="form-select" id="complaint-priority">
          <option value="low">Low</option>
          <option value="medium" selected>Medium</option>
          <option value="high">High</option>
          <option value="urgent">Urgent</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea class="form-input" id="complaint-desc" rows="4" required></textarea>
      </div>
    </form>`,
    `<button onclick="submitComplaint()" class="btn btn-primary">Submit</button>
     <button onclick="this.closest('.modal-overlay').remove()" class="btn btn-secondary">Cancel</button>`,
  );

  API.get("/complaints/categories").then((result) => {
    if (result.success) {
      const select = document.getElementById("complaint-category");
      (result.data || []).forEach((c) => {
        select.innerHTML += `<option value="${c.id}">${c.name}</option>`;
      });
    }
  });
}

async function submitComplaint() {
  const data = {
    subject: document.getElementById("complaint-subject").value,
    category_id: document.getElementById("complaint-category").value,
    priority: document.getElementById("complaint-priority").value,
    description: document.getElementById("complaint-desc").value,
  };

  if (!data.subject || !data.description) {
    UI.showAlert("Please fill all required fields", "danger");
    return;
  }

  const result = await API.post("/complaints", data);
  if (result.success) {
    UI.showAlert("Complaint submitted successfully!", "success");
    document.querySelector(".modal-overlay")?.remove();
    loadComplaints();
  } else {
    UI.showAlert(result.message || "Failed to submit complaint", "danger");
  }
}
