<?php
/**
 * Holidays Management View
 * Place: backend/app/Views/leave/holidays.php
 */
$pageTitle = 'Holidays - HR Management System';
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
    <?php require __DIR__ . '/../components/header_bar.php'; ?>

function formatDate(?string $d): string { return $d ? date('M d, Y', strtotime($d)) : 'N/A'; }
?>
<div class="lg:pl-64 pt-16 min-h-screen <?= ($_SESSION['theme'] ?? 'light') === 'light' ? 'bg-gray-50' : 'bg-dark-bg' ?>">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php include __DIR__ . '/../components/leave_tabs.php'; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="mt-6">
            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Manage Holidays</h3>

                <form method="POST" action="/leave/holidays" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="add_holiday">
                    <h4>Add New Holiday</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Holiday Name</label>
                            <input type="text" id="name" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="date">Date</label>
                            <input type="date" id="date" name="date" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_recurring"> This is a recurring holiday
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Holiday</button>
                </form>

                <div class="table-container">
                    <h4>Current Holidays</h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th><th>Date</th><th>Description</th><th>Recurring</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($holidays)): ?>
                                <tr><td colspan="5" class="text-center">No holidays found</td></tr>
                            <?php else: foreach ($holidays as $holiday): ?>
                                <tr>
                                    <td><?= htmlspecialchars($holiday['name']) ?></td>
                                    <td><?= formatDate($holiday['date']) ?></td>
                                    <td><?= htmlspecialchars($holiday['description'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge <?= $holiday['is_recurring'] ? 'badge-success' : 'badge-secondary' ?>">
                                            <?= $holiday['is_recurring'] ? 'Yes' : 'No' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="/leave/holidays?action=delete_holiday&id=<?= (int)$holiday['id'] ?>&csrf_token=<?= htmlspecialchars($csrf_token) ?>"
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('Are you sure you want to delete this holiday?')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>