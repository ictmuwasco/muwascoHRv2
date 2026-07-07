<?php
/**
 * Performance Appraisal Subtabs Component
 * 
 * Subtabs for Performance Appraisal module.
 */
$currentPage = basename($_SERVER['PHP_SELF']);

// Determine active subtab
$activeSubtab = 'regular';
if (str_contains($currentPage, 'performance_appraisal_escalated')) $activeSubtab = 'escalated';
if (str_contains($currentPage, 'performance_appraisal_pending')) $activeSubtab = 'pending';

// Get counts for badges (if available)
$escalatedCount = $escalatedCount ?? 0;
$pendingCount = $pendingCount ?? 0;

$subtabButtons = [
    ['id' => 'regular', 'label' => 'Regular Appraisals', 'url' => '/appraisal/performance', 'active' => $activeSubtab === 'regular'],
    ['id' => 'escalated', 'label' => 'Escalated Appraisals', 'url' => '/appraisal/performance/escalated', 'active' => $activeSubtab === 'escalated', 'badge' => $escalatedCount > 0 ? $escalatedCount : null, 'badge_class' => 'badge-danger'],
    ['id' => 'pending', 'label' => 'Pending Approval', 'url' => '/appraisal/performance/pending', 'active' => $activeSubtab === 'pending', 'badge' => $pendingCount > 0 ? $pendingCount : null, 'badge_class' => 'badge-warning'],
];
?>
<div class="tab-buttons">
    <?php foreach ($subtabButtons as $tab): ?>
        <a href="<?= htmlspecialchars($tab['url']) ?>" 
           class="tab-button <?= $tab['active'] ? 'active' : ?>">
            <?= htmlspecialchars($tab['label']) ?>
            <?php if (isset($tab['badge']) && $tab['badge'] > 0): ?>
                <span class="badge <?= htmlspecialchars($tab['badge_class']) ?>"><?= $tab['badge'] ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>