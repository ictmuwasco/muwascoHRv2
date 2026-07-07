/**
 * MUWASCO HR System - Dashboard Module
 *
 * Handles dashboard page rendering, stats widgets, and real-time updates.
 */

async function loadDashboard() {
  const mainContent = document.getElementById("main-content");

  try {
    const result = await API.get("/dashboard/stats");

    if (!result.success) {
      UI.showError(mainContent, result.message);
      return;
    }

    const data = result.data;

    mainContent.innerHTML = `
      <div class="space-y-6">
        <!-- Alerts Container -->
        <div id="alerts-container"></div>
        
        <!-- Stats Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div class="stat-card">
            <div class="stat-icon bg-blue-100 text-blue-600">
              <i class="fas fa-users"></i>
            </div>
            <div>
              <div class="stat-value">${data.employee_count || 0}</div>
              <div class="stat-label">Total Employees</div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon bg-green-100 text-green-600">
              <i class="fas fa-fingerprint"></i>
            </div>
            <div>
              <div class="stat-value">${data.attendance_today?.clocked_in || 0}</div>
              <div class="stat-label">Clocked In Today</div>
              <div class="text-xs text-gray-400 mt-1">${data.attendance_rate || 0}% attendance rate</div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon bg-yellow-100 text-yellow-600">
              <i class="fas fa-calendar-alt"></i>
            </div>
            <div>
              <div class="stat-value">${data.pending_leaves || 0}</div>
              <div class="stat-label">Pending Leaves</div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon bg-red-100 text-red-600">
              <i class="fas fa-exclamation-circle"></i>
            </div>
            <div>
              <div class="stat-value">${data.open_complaints || 0}</div>
              <div class="stat-label">Open Complaints</div>
            </div>
          </div>
        </div>
        
        <!-- Second Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Pending Leaves -->
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Pending Leave Requests</h3>
              <a href="#" onclick="loadPage('leaves')" class="text-sm text-primary-600 hover:text-primary-800">
                View All <i class="fas fa-arrow-right ml-1"></i>
              </a>
            </div>
            <div id="pending-leaves-list">
              <p class="text-center text-gray-500 py-4">Loading...</p>
            </div>
          </div>
          
          <!-- Recent Complaints -->
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Recent Complaints</h3>
              <a href="#" onclick="loadPage('complaints')" class="text-sm text-primary-600 hover:text-primary-800">
                View All <i class="fas fa-arrow-right ml-1"></i>
              </a>
            </div>
            <div id="recent-complaints-list">
              <p class="text-center text-gray-500 py-4">Loading...</p>
            </div>
          </div>
        </div>
        
        <!-- Third Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Department Stats -->
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Employees by Department</h3>
            </div>
            <div id="department-stats">
              ${
                data.department_stats
                  ?.map(
                    (dept) => `
                <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                  <span class="text-sm text-gray-700">${dept.department || "Unassigned"}</span>
                  <span class="badge badge-info">${dept.count} employees</span>
                </div>
              `,
                  )
                  .join("") ||
                '<p class="text-center text-gray-500 py-4">No department data</p>'
              }
            </div>
          </div>
          
          <!-- Quick Actions -->
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Quick Actions</h3>
            </div>
            <div class="space-y-3">
              <button onclick="loadPage('attendance')" class="btn btn-primary w-full justify-start">
                <i class="fas fa-fingerprint"></i> View Attendance
              </button>
              <button onclick="loadPage('leaves')" class="btn btn-secondary w-full justify-start">
                <i class="fas fa-calendar-plus"></i> Apply for Leave
              </button>
              <button onclick="loadPage('complaints')" class="btn btn-secondary w-full justify-start">
                <i class="fas fa-exclamation-circle"></i> Submit Complaint
              </button>
              <button onclick="loadPage('employees')" class="btn btn-secondary w-full justify-start">
                <i class="fas fa-user-plus"></i> Add Employee
              </button>
            </div>
          </div>
        </div>
        
        <!-- Attendance Today -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Today's Attendance</h3>
            <a href="#" onclick="loadPage('attendance')" class="text-sm text-primary-600 hover:text-primary-800">
              View All <i class="fas fa-arrow-right ml-1"></i>
            </a>
          </div>
          <div id="today-attendance">
            <p class="text-center text-gray-500 py-4">Loading...</p>
          </div>
        </div>
      </div>
    `;

    // Load sub-widgets
    loadPendingLeaves();
    loadRecentComplaints();
    loadTodayAttendance();
  } catch (error) {
    console.error("Dashboard load error:", error);
    UI.showError(
      mainContent,
      "Failed to load dashboard data. Please try again.",
    );
  }
}

async function loadPendingLeaves() {
  const container = document.getElementById("pending-leaves-list");
  const result = await API.get("/dashboard/pending-leaves");

  if (result.success && result.data?.length > 0) {
    container.innerHTML = result.data
      .slice(0, 5)
      .map(
        (leave) => `
      <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
        <div>
          <p class="text-sm font-medium">${leave.first_name} ${leave.last_name}</p>
          <p class="text-xs text-gray-500">${leave.leave_type_name || "Leave"}</p>
        </div>
        <div class="text-right">
          <p class="text-xs text-gray-500">${UI.formatDate(leave.start_date)}</p>
          <span class="badge badge-warning">Pending</span>
        </div>
      </div>
    `,
      )
      .join("");
  } else {
    container.innerHTML =
      '<p class="text-center text-gray-500 py-4">No pending leave requests</p>';
  }
}

async function loadRecentComplaints() {
  const container = document.getElementById("recent-complaints-list");
  const result = await API.get("/dashboard/recent-complaints");

  if (result.success && result.data?.length > 0) {
    container.innerHTML = result.data
      .slice(0, 5)
      .map(
        (complaint) => `
      <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
        <div>
          <p class="text-sm font-medium">${complaint.first_name} ${complaint.last_name}</p>
          <p class="text-xs text-gray-500">${complaint.category_name || "General"}</p>
        </div>
        <span class="badge ${UI.statusBadge(complaint.status)}">${UI.formatStatus(complaint.status)}</span>
      </div>
    `,
      )
      .join("");
  } else {
    container.innerHTML =
      '<p class="text-center text-gray-500 py-4">No recent complaints</p>';
  }
}

async function loadTodayAttendance() {
  const container = document.getElementById("today-attendance");
  const result = await API.get("/dashboard/attendance-today");

  if (result.success && result.data?.records?.length > 0) {
    const records = result.data.records;

    // Stats summary
    const statsHtml = `
      <div class="flex gap-4 mb-4 pb-4 border-b">
        <div class="text-center">
          <p class="text-lg font-bold text-green-600">${result.data.stats.clocked_in}</p>
          <p class="text-xs text-gray-500">Clocked In</p>
        </div>
        <div class="text-center">
          <p class="text-lg font-bold text-blue-600">${result.data.stats.clocked_out}</p>
          <p class="text-xs text-gray-500">Clocked Out</p>
        </div>
        <div class="text-center">
          <p class="text-lg font-bold text-yellow-600">${result.data.stats.late}</p>
          <p class="text-xs text-gray-500">Late</p>
        </div>
        <div class="text-center">
          <p class="text-lg font-bold text-gray-600">${result.data.stats.total}</p>
          <p class="text-xs text-gray-500">Total</p>
        </div>
      </div>
    `;

    // Records table
    const tableHtml = `
      <div class="table-container">
        <table class="data-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Department</th>
              <th>Clock In</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            ${records
              .slice(0, 10)
              .map(
                (record) => `
              <tr>
                <td>${record.first_name} ${record.last_name}</td>
                <td>${record.department || "-"}</td>
                <td>${UI.formatTime(record.clock_in)}</td>
                <td><span class="badge ${UI.statusBadge(record.status)}">${UI.formatStatus(record.status)}</span></td>
              </tr>
            `,
              )
              .join("")}
          </tbody>
        </table>
      </div>
    `;

    container.innerHTML = statsHtml + tableHtml;
  } else {
    container.innerHTML =
      '<p class="text-center text-gray-500 py-4">No attendance records for today</p>';
  }
}
