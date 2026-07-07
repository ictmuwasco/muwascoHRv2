<?php
/**
 * Attendance Summary Card Component
 *
 * Displays clock-in/clock-out summary for dashboard.
 * Expects: $attendance (array|null) from Attendance::getAttendanceSummary()
 *
 * Place: backend/app/Views/components/attendance_card.php
 */
?>
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md transition-all duration-200 h-full flex flex-col" style="flex:1;min-width:0;height:100%;">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center text-blue-600">
                <i class="fas fa-clock text-lg"></i>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-900">Attendance</p>
                <p class="text-xs text-gray-500">Today's summary</p>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/attendance" class="text-xs text-blue-600 hover:text-blue-800 font-medium">
            View <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>

    <div class="flex-1 flex items-center justify-center py-2">
        <?php if ($attendance && $attendance['clocked_in_today']): ?>
            <?php $record = $attendance['today_record']; ?>
            <?php if ($attendance['is_clocked_in']): ?>
                <div class="text-center">
                    <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center text-green-600 mx-auto mb-2">
                        <i class="fas fa-play-circle text-xl"></i>
                    </div>
                    <p class="font-bold text-green-700 text-sm">Clocked In</p>
                    <p class="text-xs text-gray-500 mt-0.5">Since <?= date('g:i A', strtotime($record['clock_in'])) ?></p>
                </div>
            <?php else: ?>
                <div class="text-center">
                    <div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center text-gray-500 mx-auto mb-2">
                        <i class="fas fa-stop-circle text-xl"></i>
                    </div>
                    <p class="font-bold text-gray-700 text-sm">Completed</p>
                    <p class="text-xs text-gray-500 mt-0.5">In: <?= date('g:i A', strtotime($record['clock_in'])) ?> | Out: <?= date('g:i A', strtotime($record['clock_out'])) ?></p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center">
                <div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center text-gray-500 mx-auto mb-2">
                    <i class="fas fa-clock text-xl"></i>
                </div>
                <p class="text-sm text-gray-600">Not clocked in today</p>
                <a href="<?= BASE_URL ?>/attendance" class="inline-block mt-2 px-4 py-1.5 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fas fa-sign-in-alt mr-1"></i>Clock In Now
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($attendance): ?>
        <div class="grid grid-cols-3 gap-2 pt-3 border-t border-gray-100 mt-auto">
            <div class="text-center">
                <p class="text-lg font-bold text-gray-900"><?= $attendance['week_days'] ?></p>
                <p class="text-xs text-gray-500">This Week</p>
            </div>
            <div class="text-center">
                <p class="text-lg font-bold text-yellow-600"><?= $attendance['late_days'] ?></p>
                <p class="text-xs text-gray-500">Late</p>
            </div>
            <div class="text-center">
                <p class="text-lg font-bold text-gray-900"><?= $attendance['month_hours'] ?>h</p>
                <p class="text-xs text-gray-500">This Month</p>
            </div>
        </div>
    <?php endif; ?>
</div>