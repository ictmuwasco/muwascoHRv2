<?php
/**
 * Users View
 *
 * Displays user management interface with search, add, edit, and delete functionality.
 * Place: backend/app/Views/users/index.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/auth.php';
}

$pageTitle = 'Users Management - HR Management System';
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
<body class="min-h-screen"
      style="background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);">

<div class="container">
    <div class="main-content">
        <div class="content">

            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="alert alert-<?= $_SESSION['flash_type'] ?? 'info' ?>">
                    <?= htmlspecialchars($_SESSION['flash_message']) ?>
                    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <?php $activeTab = 'users'; include __DIR__ . '/../components/admin_tabs.php'; ?>

            <!-- Search Section -->
            <div class="search-section">
                <div class="search-header">
                    <div class="search-title">
                        <i class="fas fa-search"></i> Search Users by Name
                    </div>
                    <button onclick="document.getElementById('addUserModal').style.display='block'" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add User
                    </button>
                </div>
                
                <div class="search-box">
                    <input type="text" 
                           id="searchInput" 
                           class="search-input" 
                           placeholder="Type a name to search..."
                           onkeyup="filterTable()">
                    <button class="search-btn" onclick="filterTable()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button class="clear-btn" onclick="clearSearch()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
                
                <div class="search-stats" id="searchStats">
                    Showing <span id="visibleCount"><?= count($allUsers) ?></span> of <?= count($allUsers) ?> users
                </div>
            </div>

            <!-- Users Table -->
            <div class="table-container">
                <table class="table" id="usersTable">
                    <thead>
                        <tr>
                            <th>Name</th><th>Email</th><th>Role</th>
                            <th>Designation</th><th>Status</th><th>Created</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (empty($allUsers)): ?>
                            <tr><td colspan="7" class="text-center">No users found</td></tr>
                        <?php else: foreach ($allUsers as $u): ?>
                            <tr>
                                <td class="user-name"><?= htmlspecialchars(trim($u['first_name'].' '.$u['last_name'])) ?></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td><span class="badge <?= getRoleBadge($u['role']) ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$u['role']))) ?></span></td>
                                <td><?= htmlspecialchars($u['designation'] ?? 'N/A') ?></td>
                                <td><span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-danger' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                <td><?= formatDate($u['created_at']) ?></td>
                                <td class="action-buttons">
                                    <button onclick="openEdit(<?= htmlspecialchars(json_encode($u),ENT_QUOTES) ?>)" class="btn btn-sm btn-primary">Edit</button>
                                    <button onclick="openReset('<?= $u['id'] ?>','<?= htmlspecialchars($u['first_name'].' '.$u['last_name'],ENT_QUOTES) ?>')" class="btn btn-sm btn-warning">Reset Password</button>
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                        <button onclick="doDelete('<?= $u['id'] ?>','<?= htmlspecialchars($u['first_name'].' '.$u['last_name'],ENT_QUOTES) ?>')" class="btn btn-sm btn-danger">Delete</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- ADD USER MODAL -->
<div id="addUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New User</h3>
            <span class="close" onclick="closeModal('addUserModal')">&times;</span>
        </div>
        <form method="POST" action="/users/add">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="form-row">
                <div class="form-group"><label>First Name *</label><input type="text" class="form-control" name="first_name" required></div>
                <div class="form-group"><label>Last Name *</label><input type="text" class="form-control" name="last_name" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Surname</label><input type="text" class="form-control" name="surname"></div>
                <div class="form-group"><label>Email *</label><input type="email" class="form-control" name="email" required></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Password * <small>(min 6)</small></label>
                    <input type="password" class="form-control" name="password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select class="form-control" name="role" required>
                        <option value="">Select Role</option>
                        <?php foreach ($valid_roles as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$r))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Gender</label>
                    <select class="form-control" name="gender"><option value="">Select</option><option value="male">Male</option><option value="female">Female</option></select>
                </div>
                <div class="form-group"><label>Designation</label><input type="text" class="form-control" name="designation"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Phone</label><input type="text" class="form-control" name="phone"></div>
                <div class="form-group"><label>Employee ID</label><input type="text" class="form-control" name="employee_id"></div>
            </div>
            <div class="form-group"><label>Address</label><textarea class="form-control" name="address" rows="2"></textarea></div>
            <div class="form-actions">
                <button type="submit" class="btn btn-success">Create User</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div id="editUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit User</h3>
            <span class="close" onclick="closeModal('editUserModal')">&times;</span>
        </div>
        <form method="POST" action="/users/edit">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="e_id" name="id">
            <div class="form-row">
                <div class="form-group"><label>First Name *</label><input type="text" class="form-control" id="e_first" name="first_name" required></div>
                <div class="form-group"><label>Last Name *</label><input type="text" class="form-control" id="e_last" name="last_name" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Surname</label><input type="text" class="form-control" id="e_surname" name="surname"></div>
                <div class="form-group"><label>Email *</label><input type="email" class="form-control" id="e_email" name="email" required></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Role *</label>
                    <select class="form-control" id="e_role" name="role" required>
                        <option value="">Select Role</option>
                        <?php foreach ($valid_roles as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$r))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <select class="form-control" id="e_gender" name="gender"><option value="">Select</option><option value="male">Male</option><option value="female">Female</option></select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Designation</label><input type="text" class="form-control" id="e_desig" name="designation"></div>
                <div class="form-group"><label>Phone</label><input type="text" class="form-control" id="e_phone" name="phone"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Employee ID</label><input type="text" class="form-control" id="e_empid" name="employee_id"></div>
                <div class="form-group" style="padding-top:1.6rem">
                    <label><input type="checkbox" id="e_active" name="is_active" value="1"> Active Account</label>
                </div>
            </div>
            <div class="form-group"><label>Address</label><textarea class="form-control" id="e_addr" name="address" rows="2"></textarea></div>
            <div class="form-group">
                <label>New Password <small>(leave blank to keep current)</small></label>
                <input type="password" class="form-control" id="e_pw" name="password" placeholder="Leave blank to keep current" minlength="6">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Update User</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div id="resetModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Reset Password</h3>
            <span class="close" onclick="closeModal('resetModal')">&times;</span>
        </div>
        <form method="POST" action="/users/reset-password" id="resetForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="r_id" name="id">
            <div class="form-group">
                <p>Resetting password for: <strong id="r_name"></strong></p>
                <label>New Password * <small>(min 6)</small></label>
                <input type="password" class="form-control" id="r_pw" name="password" required minlength="6">
                <div style="height:4px;background:#eee;border-radius:2px;margin-top:4px">
                    <div id="r_bar" style="height:100%;width:0;border-radius:2px;transition:width .3s,background .3s"></div>
                </div>
            </div>
            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password" class="form-control" id="r_confirm" required>
                <div id="r_match" style="font-size:.85rem;margin-top:4px"></div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-warning">Reset Password</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('resetModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden delete form -->
<form id="deleteForm" method="POST" action="/users/delete" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" id="del_id" name="id">
</form>

<script>
// Store original table data
let originalRows = [];
let currentRows = [];

// Initialize table data
document.addEventListener('DOMContentLoaded', function() {
    const tableBody = document.getElementById('tableBody');
    const rows = tableBody.getElementsByTagName('tr');
    
    // Skip if no data or header row
    for (let row of rows) {
        if (row.cells.length > 1 && !row.classList.contains('no-results')) {
            originalRows.push({
                element: row,
                name: row.cells[0]?.textContent?.toLowerCase() || '',
                html: row.innerHTML
            });
        }
    }
    
    currentRows = [...originalRows];
    updateStats();
});

function filterTable() {
    const searchInput = document.getElementById('searchInput').value.toLowerCase().trim();
    const tableBody = document.getElementById('tableBody');
    let visibleCount = 0;
    
    // Clear table body
    tableBody.innerHTML = '';
    
    if (searchInput === '') {
        // Show all rows
        originalRows.forEach(row => {
            tableBody.appendChild(row.element);
            removeHighlight(row.element);
        });
        visibleCount = originalRows.length;
    } else {
        // Filter rows
        const searchTerms = searchInput.split(' ').filter(term => term.length > 0);
        
        originalRows.forEach(row => {
            const nameLower = row.name;
            
            // Check if all search terms are in the name
            const matches = searchTerms.every(term => nameLower.includes(term));
            
            if (matches) {
                tableBody.appendChild(row.element);
                highlightText(row.element, searchInput);
                visibleCount++;
            }
        });
    }
    
    // Show no results message if needed
    if (visibleCount === 0) {
        const noResultsRow = document.createElement('tr');
        noResultsRow.className = 'no-results';
        noResultsRow.innerHTML = '<td colspan="7" class="text-center no-results">No users match your search</td>';
        tableBody.appendChild(noResultsRow);
    }
    
    document.getElementById('visibleCount').textContent = visibleCount;
}

function highlightText(row, searchTerm) {
    const nameCell = row.cells[0];
    if (!nameCell) return;
    
    const originalText = nameCell.textContent;
    const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    nameCell.innerHTML = originalText.replace(regex, '<span class="highlight">$1</span>');
}

function removeHighlight(row) {
    const nameCell = row.cells[0];
    if (!nameCell) return;
    
    // Restore original text without highlights
    nameCell.innerHTML = nameCell.textContent;
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    
    // Restore all rows
    const tableBody = document.getElementById('tableBody');
    tableBody.innerHTML = '';
    
    originalRows.forEach(row => {
        removeHighlight(row.element);
        tableBody.appendChild(row.element);
    });
    
    document.getElementById('visibleCount').textContent = originalRows.length;
}

function updateStats() {
    document.getElementById('visibleCount').textContent = originalRows.length;
}

// Debounce search for better performance
let searchTimeout;
document.getElementById('searchInput').addEventListener('keyup', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        filterTable();
    }, 300);
});

// Modal functions
function closeModal(id) { 
    document.getElementById(id).style.display='none'; 
}

window.addEventListener('click', e => {
    ['addUserModal','editUserModal','resetModal'].forEach(id => {
        if (e.target === document.getElementById(id)) closeModal(id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key==='Escape') {
        ['addUserModal','editUserModal','resetModal'].forEach(closeModal);
    }
});

function openEdit(u) {
    document.getElementById('e_id').value = u.id || '';
    document.getElementById('e_first').value = u.first_name || '';
    document.getElementById('e_last').value = u.last_name || '';
    document.getElementById('e_surname').value = u.surname || '';
    document.getElementById('e_email').value = u.email || '';
    document.getElementById('e_role').value = u.role || '';
    document.getElementById('e_gender').value = u.gender || '';
    document.getElementById('e_desig').value = u.designation || '';
    document.getElementById('e_phone').value = u.phone || '';
    document.getElementById('e_addr').value = u.address || '';
    document.getElementById('e_empid').value = u.employee_id || '';
    document.getElementById('e_active').checked = u.is_active == 1;
    document.getElementById('e_pw').value = '';
    document.getElementById('editUserModal').style.display='block';
}

function openReset(id, name) {
    document.getElementById('r_id').value = id;
    document.getElementById('r_name').textContent = name;
    document.getElementById('r_pw').value = '';
    document.getElementById('r_confirm').value = '';
    document.getElementById('r_match').textContent = '';
    document.getElementById('r_bar').style.width = '0';
    document.getElementById('resetModal').style.display='block';
}

const rPw = document.getElementById('r_pw'), 
      rCfm = document.getElementById('r_confirm'),
      rBar = document.getElementById('r_bar'), 
      rMsg = document.getElementById('r_match');

function pwStrength(pw) {
    let s = 0;
    if (pw.length >= 6) s++; 
    if (pw.length >= 8) s++;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
    if (/\d/.test(pw)) s++; 
    if (/[^a-zA-Z\d]/.test(pw)) s++;
    return {pct: [0, 25, 50, 75, 100][Math.min(s, 4)], color: s <= 1 ? '#dc3545' : s <= 2 ? '#ffc107' : '#28a745'};
}

function checkMatch() {
    if (!rCfm.value) { rMsg.textContent = ''; return; }
    rMsg.textContent = rPw.value === rCfm.value ? '✓ Passwords match' : '✗ Passwords do not match';
    rMsg.style.color = rPw.value === rCfm.value ? '#28a745' : '#dc3545';
}

rPw.addEventListener('input', () => {
    const r = pwStrength(rPw.value);
    rBar.style.width = r.pct + '%';
    rBar.style.backgroundColor = r.color;
    checkMatch();
});

rCfm.addEventListener('input', checkMatch);

document.getElementById('resetForm').addEventListener('submit', e => {
    if (rPw.value.length < 6) { alert('Password must be at least 6 characters.'); e.preventDefault(); return; }
    if (rPw.value !== rCfm.value) { alert('Passwords do not match.'); e.preventDefault(); }
});

function doDelete(id, name) {
    if (!confirm('Delete user "' + name + '"?\n\nThis cannot be undone.')) return;
    document.getElementById('del_id').value = id;
    document.getElementById('deleteForm').submit();
}
</script>

</body>
</html>
