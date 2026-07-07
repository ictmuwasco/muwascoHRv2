<?php
/**
 * Notification Widget Component
 *
 * Displays user notifications on the dashboard.
 * Place: backend/app/Views/components/notification_widget.php
 */
?>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm w-full">
    <!-- Header -->
    <div class="p-5 border-b border-gray-100">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center text-blue-600">
                    <i class="fas fa-bell text-lg"></i>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Notifications</h3>
                    <p class="text-xs text-gray-500">Stay updated with your latest alerts</p>
                </div>
            </div>
            <?php if (isset($unread_count) && $unread_count > 0): ?>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700 border border-red-200">
                    <?= (int)$unread_count ?> new
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notifications List -->
    <div class="p-3">
        <div class="space-y-2 min-h-[180px] max-h-[400px] overflow-y-auto">
            <?php if (empty($notifications)): ?>
                <div class="flex flex-col items-center justify-center py-12 px-4">
                    <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mb-3">
                        <i class="fas fa-bell-slash text-2xl text-gray-400"></i>
                    </div>
                    <p class="text-sm font-medium text-gray-700 mb-1">No notifications yet</p>
                    <p class="text-xs text-gray-500 text-center">Notifications will appear here when you have updates, alerts, or messages</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $note): ?>
                    <?php
                    $noteType = $note['type'] ?? 'info';
                    $isUnread = !($note['is_read'] ?? true);

                    // Color schemes based on type
                    $iconColors = [
                        'success' => 'text-green-600 bg-green-50 border-green-200',
                        'warning' => 'text-amber-600 bg-amber-50 border-amber-200',
                        'error'   => 'text-red-600 bg-red-50 border-red-200',
                        'info'    => 'text-blue-600 bg-blue-50 border-blue-200',
                    ];
                    $iconNames = [
                        'success' => 'fa-check-circle',
                        'warning' => 'fa-exclamation-triangle',
                        'error'   => 'fa-times-circle',
                        'info'    => 'fa-info-circle',
                    ];
                    $colors = $iconColors[$noteType] ?? $iconColors['info'];
                    $iconName = $iconNames[$noteType] ?? $iconNames['info'];
                    list($textColor, $bgColor, $borderColor) = explode(' ', $colors);
                    ?>
                    <div class="group relative p-3 rounded-lg border transition-all duration-200 hover:shadow-sm <?= $isUnread ? 'bg-blue-50/50 border-blue-200' : 'bg-gray-50 border-gray-200 hover:bg-gray-100' ?>">
                        <?php if ($isUnread): ?>
                            <div class="absolute top-3 right-3">
                                <span class="inline-block w-2 h-2 rounded-full bg-blue-600"></span>
                            </div>
                        <?php endif; ?>

                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 mt-0.5">
                                <div class="w-8 h-8 rounded-lg <?= $bgColor ?> border <?= $borderColor ?> flex items-center justify-center">
                                    <i class="fas <?= $iconName ?> <?= $textColor ?> text-sm"></i>
                                </div>
                            </div>

                            <div class="flex-1 min-w-0 pr-6">
                                <div class="flex items-start justify-between gap-2 mb-1">
                                    <p class="text-sm font-semibold text-gray-900 leading-tight">
                                        <?= htmlspecialchars($note['title'] ?? 'Notification') ?>
                                    </p>
                                </div>
                                <p class="text-xs text-gray-600 leading-relaxed mb-2">
                                    <?= htmlspecialchars($note['message'] ?? '') ?>
                                </p>
                                <div class="flex items-center gap-2 text-xs text-gray-500">
                                    <i class="far fa-clock"></i>
                                    <span><?= date('d M Y, H:i', strtotime($note['created_at'] ?? 'now')) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($notifications)): ?>
        <!-- Footer -->
        <div class="px-5 py-3 border-t border-gray-100 bg-gray-50/50 rounded-b-xl">
            <a href="<?= BASE_URL ?>/notifications" class="flex items-center justify-center gap-2 text-sm text-blue-600 hover:text-blue-700 font-medium transition-colors">
                <span>View all notifications</span>
                <i class="fas fa-arrow-right text-xs"></i>
            </a>
        </div>
    <?php endif; ?>
</div>