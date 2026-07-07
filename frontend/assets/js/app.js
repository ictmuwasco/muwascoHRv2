/**
 * MUWASCO HR System - Main Application JavaScript
 *
 * Handles SPA navigation, shared UI interactions, and API communication.
 */

// ── API Base URL ──────────────────────────────────────────────────────────
const API_BASE = "/hrdemo/api";

// ── CSRF Token ────────────────────────────────────────────────────────────
const CSRF_TOKEN =
  document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
  "";

// ── API Helper ────────────────────────────────────────────────────────────
const API = {
  async get(endpoint, params = {}) {
    const url = new URL(API_BASE + endpoint, window.location.origin);
    Object.keys(params).forEach((key) =>
      url.searchParams.append(key, params[key]),
    );

    try {
      const response = await fetch(url, {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": CSRF_TOKEN,
        },
        credentials: "same-origin",
      });
      return await response.json();
    } catch (error) {
      console.error("API GET Error:", error);
      return { success: false, message: "Network error occurred" };
    }
  },

  async post(endpoint, data = {}) {
    try {
      const response = await fetch(API_BASE + endpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": CSRF_TOKEN,
        },
        body: JSON.stringify(data),
        credentials: "same-origin",
      });
      return await response.json();
    } catch (error) {
      console.error("API POST Error:", error);
      return { success: false, message: "Network error occurred" };
    }
  },

  async put(endpoint, data = {}) {
    try {
      const response = await fetch(API_BASE + endpoint, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": CSRF_TOKEN,
        },
        body: JSON.stringify(data),
        credentials: "same-origin",
      });
      return await response.json();
    } catch (error) {
      console.error("API PUT Error:", error);
      return { success: false, message: "Network error occurred" };
    }
  },

  async delete(endpoint) {
    try {
      const response = await fetch(API_BASE + endpoint, {
        method: "DELETE",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": CSRF_TOKEN,
        },
        credentials: "same-origin",
      });
      return await response.json();
    } catch (error) {
      console.error("API DELETE Error:", error);
      return { success: false, message: "Network error occurred" };
    }
  },
};

// ── UI Helpers ────────────────────────────────────────────────────────────
const UI = {
  // Show loading spinner
  showLoader(container) {
    container.innerHTML = `
            <div class="flex items-center justify-center h-64">
                <div class="text-center">
                    <div class="loader mx-auto mb-4"></div>
                    <p class="text-gray-500">Loading...</p>
                </div>
            </div>
        `;
  },

  // Show error message
  showError(container, message = "An error occurred while loading data.") {
    container.innerHTML = `
            <div class="flex items-center justify-center h-64">
                <div class="text-center">
                    <i class="fas fa-exclamation-circle text-4xl text-red-400 mb-4"></i>
                    <p class="text-gray-600">${message}</p>
                    <button onclick="location.reload()" class="btn btn-primary mt-4">
                        <i class="fas fa-redo"></i> Try Again
                    </button>
                </div>
            </div>
        `;
  },

  // Show alert
  showAlert(message, type = "info") {
    const alerts = document.getElementById("alerts-container");
    if (!alerts) return;

    const alert = document.createElement("div");
    alert.className = `alert alert-${type} flex items-center justify-between`;
    alert.innerHTML = `
            <span>${message}</span>
            <button onclick="this.parentElement.remove()" class="ml-2">
                <i class="fas fa-times"></i>
            </button>
        `;
    alerts.appendChild(alert);

    setTimeout(() => {
      alert.style.transition = "opacity .5s";
      alert.style.opacity = "0";
      setTimeout(() => alert.remove(), 500);
    }, 5000);
  },

  // Format date
  formatDate(dateStr) {
    if (!dateStr) return "-";
    const date = new Date(dateStr);
    return date.toLocaleDateString("en-KE", {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  },

  // Format datetime
  formatDateTime(dateStr) {
    if (!dateStr) return "-";
    const date = new Date(dateStr);
    return date.toLocaleString("en-KE", {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  },

  // Format time
  formatTime(dateStr) {
    if (!dateStr) return "-";
    const date = new Date(dateStr);
    return date.toLocaleTimeString("en-KE", {
      hour: "2-digit",
      minute: "2-digit",
    });
  },

  // Get status badge class
  statusBadge(status) {
    const map = {
      active: "badge-success",
      inactive: "badge-gray",
      pending: "badge-warning",
      approved: "badge-success",
      rejected: "badge-danger",
      clocked_in: "badge-success",
      clocked_out: "badge-info",
      late: "badge-warning",
      absent: "badge-danger",
      on_leave: "badge-warning",
      resolved: "badge-success",
      open: "badge-warning",
      suspended: "badge-danger",
      completed: "badge-success",
    };
    return map[status] || "badge-gray";
  },

  // Format status text
  formatStatus(status) {
    if (!status) return "-";
    return status.replace(/_/g, " ").replace(/\b\w/g, (l) => l.toUpperCase());
  },

  // Create modal
  showModal(title, body, footer = "") {
    const overlay = document.createElement("div");
    overlay.className = "modal-overlay";
    overlay.onclick = (e) => {
      if (e.target === overlay) overlay.remove();
    };

    overlay.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold">${title}</h3>
                    <button onclick="this.closest('.modal-overlay').remove()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">${body}</div>
                ${footer ? `<div class="modal-footer">${footer}</div>` : ""}
            </div>
        `;

    document.body.appendChild(overlay);
    return overlay;
  },

  // Confirm dialog
  confirm(message, onConfirm) {
    this.showModal(
      "Confirm Action",
      `<p class="text-gray-600">${message}</p>`,
      `
                <button onclick="this.closest('.modal-overlay').remove()" class="btn btn-secondary">Cancel</button>
                <button onclick="this.closest('.modal-overlay').remove(); (${onConfirm.toString()})()" class="btn btn-danger">Confirm</button>
            `,
    );
  },
};

// ── SPA Navigation ────────────────────────────────────────────────────────
let currentPage = "";

function loadPage(page) {
  currentPage = page;
  const mainContent = document.getElementById("main-content");
  const pageTitle = document.getElementById("page-title");

  // Update page title
  const titles = {
    dashboard: "Dashboard",
    attendance: "Attendance Management",
    leaves: "Leave Management",
    employees: "Employee Management",
    payroll: "Payroll Management",
    complaints: "Complaints Management",
    appraisals: "Performance Appraisal",
    reports: "Reports",
    users: "User Management",
    roles: "Roles & Permissions",
    audit: "Audit Trail",
    settings: "Settings",
  };
  pageTitle.textContent = titles[page] || "MUWASCO HR System";

  // Update active nav item
  document.querySelectorAll(".nav-item").forEach((item) => {
    item.classList.toggle("active", item.dataset.page === page);
  });

  // Show loader
  UI.showLoader(mainContent);

  // Load page content
  switch (page) {
    case "dashboard":
      loadDashboard();
      break;
    case "attendance":
      loadAttendance();
      break;
    case "leaves":
      loadLeaveManagement();
      break;
    case "employees":
      loadEmployees();
      break;
    case "payroll":
      loadPayroll();
      break;
    case "complaints":
      loadComplaints();
      break;
    case "appraisals":
      loadAppraisals();
      break;
    case "reports":
      loadReports();
      break;
    case "users":
      loadUsers();
      break;
    case "roles":
      loadRoles();
      break;
    case "audit":
      loadAudit();
      break;
    case "settings":
      loadSettings();
      break;
    default:
      mainContent.innerHTML =
        '<p class="text-center text-gray-500 py-8">Page not found</p>';
  }
}

// ── Sidebar Toggle ────────────────────────────────────────────────────────
function toggleSidebar() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("sidebar-overlay");
  sidebar.classList.toggle("hidden");
  overlay.classList.toggle("hidden");
}

// ── Notifications ─────────────────────────────────────────────────────────
function toggleNotifications() {
  const dropdown = document.getElementById("notif-dropdown");
  dropdown.classList.toggle("hidden");

  if (!dropdown.classList.contains("hidden")) {
    loadNotifications();
  }
}

async function loadNotifications() {
  const list = document.getElementById("notif-list");
  const result = await API.get("/dashboard/notifications");

  if (result.success && result.data) {
    const notifs = result.data.notifications || [];

    if (notifs.length === 0) {
      list.innerHTML =
        '<p class="text-center text-gray-500 py-4">No notifications</p>';
    } else {
      list.innerHTML = notifs
        .map(
          (n) => `
                <div class="p-3 border-b hover:bg-gray-50 cursor-pointer ${n.is_read ? "" : "bg-blue-50"}">
                    <p class="text-sm font-medium">${n.title}</p>
                    <p class="text-xs text-gray-500 mt-1">${n.message}</p>
                    <p class="text-xs text-gray-400 mt-1">${UI.formatDateTime(n.created_at)}</p>
                </div>
            `,
        )
        .join("");
    }

    // Update badge
    const badge = document.getElementById("notif-badge");
    const count = result.data.unread_count || 0;
    if (badge) {
      badge.textContent = Math.min(count, 99);
      badge.style.display = count > 0 ? "flex" : "none";
    }
  }
}

async function markAllRead() {
  const result = await API.post("/notifications/mark-all-read");
  if (result.success) {
    document.getElementById("notif-badge").style.display = "none";
    document.getElementById("notif-list").innerHTML =
      '<p class="text-center text-gray-500 py-4">No notifications</p>';
  }
}

// ── User Menu ─────────────────────────────────────────────────────────────
function toggleUserMenu() {
  document.getElementById("user-menu").classList.toggle("hidden");
}

// Close dropdowns on outside click
document.addEventListener("click", function (e) {
  // User menu
  if (
    !e.target.closest("#user-menu") &&
    !e.target.closest('[onclick="toggleUserMenu()"]')
  ) {
    const menu = document.getElementById("user-menu");
    if (menu) menu.classList.add("hidden");
  }

  // Notification dropdown
  if (
    !e.target.closest("#notif-dropdown") &&
    !e.target.closest('[onclick="toggleNotifications()"]')
  ) {
    const dropdown = document.getElementById("notif-dropdown");
    if (dropdown) dropdown.classList.add("hidden");
  }
});

// ── Page Loader Initialization ────────────────────────────────────────────
// Each page module should define its load function
// These are provided by the individual page JS files (dashboard.js, etc.)
