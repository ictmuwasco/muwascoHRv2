<?php
?>
<div class="flex flex-wrap items-center gap-2 border-b border-gray-200 dark:border-white/10 pb-2 mb-6">
    <a href="<?= BASE_URL ?>/?route=admin" 
       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?= ($activeTab ?? '') === 'financial-year' ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white border border-transparent' ?>">
        <i class="fas fa-calendar-alt"></i> Financial Year
    </a>
    <a href="<?= BASE_URL ?>/?route=users" 
       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?= ($activeTab ?? '') === 'users' ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white border border-transparent' ?>">
        <i class="fas fa-users"></i> Users
    </a>
    <a href="<?= BASE_URL ?>/?route=consent-management" 
       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?= ($activeTab ?? '') === 'consents' ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white border border-transparent' ?>">
        <i class="fas fa-file-signature"></i> Employee Consents
    </a>
    <a href="<?= BASE_URL ?>/?route=audit" 
       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?= ($activeTab ?? '') === 'audit' ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white border border-transparent' ?>">
        <i class="fas fa-shield-alt"></i> Audit
    </a>
    <a href="<?= BASE_URL ?>/?route=admin/permission-overrides" 
       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?= ($activeTab ?? '') === 'permission-overrides' ? 'bg-primary-400/20 text-primary-400 border border-primary-400/30' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white border border-transparent' ?>">
        <i class="fas fa-user-shield"></i> Permission Overrides
    </a>
</div>
