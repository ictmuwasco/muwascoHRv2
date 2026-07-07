<?php
/**
 * Tabs Component
 *
 * Reusable tabbed interface for organizing content into sections.
 *
 * Usage:
 *   $tabs = [
 *       ['id' => 'tab1', 'label' => 'Tab 1', 'active' => true],
 *       ['id' => 'tab2', 'label' => 'Tab 2'],
 *   ];
 *   $activeTab = 'tab1';
 *   require __DIR__ . '/tabs.php';
 *
 * Place: backend/app/Views/components/tabs.php
 */
?>
<div class="tabs-container">
    <div class="tabs-header flex border-b border-white/10 mb-6">
        <?php foreach ($tabs as $tab): ?>
            <button class="tab-btn px-6 py-3 text-sm font-medium transition-all duration-200
                          <?= $tab['active'] || $tab['id'] === $activeTab
                              ? 'border-b-2 border-primary-400 text-primary-400 bg-white/5'
                              : 'text-gray-400 hover:text-white hover:bg-white/5' ?>"
                    data-tab="<?= htmlspecialchars($tab['id']) ?>">
                <?= htmlspecialchars($tab['label']) ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="tabs-content">
        <?php foreach ($tabs as $tab): ?>
            <div class="tab-panel <?= $tab['active'] || $tab['id'] === $activeTab ? '' : 'hidden' ?>"
                 id="panel-<?= htmlspecialchars($tab['id']) ?>">
                <?= $tab['content'] ?? '' ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
.tabs-header { gap: 0; }
.tab-btn { border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab-btn:hover { border-bottom-color: rgba(99, 102, 241, 0.5); }
.hidden { display: none; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const tabId = this.dataset.tab;
            
            // Update buttons
            document.querySelectorAll('.tab-btn').forEach(b => {
                b.classList.remove('border-primary-400', 'text-primary-400', 'bg-white/5');
                b.classList.add('text-gray-400');
            });
            this.classList.add('border-primary-400', 'text-primary-400', 'bg-white/5');
            this.classList.remove('text-gray-400');
            
            // Update panels
            document.querySelectorAll('.tab-panel').forEach(panel => {
                panel.classList.add('hidden');
            });
            const activePanel = document.getElementById('panel-' + tabId);
            if (activePanel) {
                activePanel.classList.remove('hidden');
            }
        });
    });
});
</script>