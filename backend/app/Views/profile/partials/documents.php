<?php
/**
 * Documents Partial
 *
 * Displays employee documents list with upload option.
 *
 * Place: backend/app/Views/profile/partials/documents.php
 */
?>
<div class="bg-white/50 dark:bg-white/10 border border-gray-200 dark:border-white/20 rounded-2xl shadow-lg dark:shadow-2xl p-6 backdrop-blur-xl">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
            <i class="fas fa-file-alt mr-2 text-primary-400"></i>Documents
        </h3>
        <?php if (!$is_viewing_other): ?>
            <button onclick="document.getElementById('uploadDocumentModal').classList.remove('hidden')"
                    class="btn btn-primary btn-sm">
                <i class="fas fa-upload mr-2"></i>Upload Document
            </button>
        <?php endif; ?>
    </div>

    <?php if (empty($documents)): ?>
        <p class="text-gray-600 dark:text-gray-500 text-center py-8">No documents uploaded yet.</p>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($documents as $doc): ?>
                <div class="flex items-center justify-between p-4 bg-gray-100 dark:bg-white/5 rounded-xl border border-gray-200 dark:border-white/10">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-primary-400/20 text-primary-400 flex items-center justify-center">
                            <i class="fas fa-file"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($doc['document_name'] ?? 'Document') ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-500">
                                <?= htmlspecialchars($doc['document_type'] ?? 'N/A') ?> •
                                <?= isset($doc['created_at']) ? date('d M Y', strtotime($doc['created_at'])) : 'N/A' ?>
                            </p>
                        </div>
                    </div>
                    <a href="/uploads/documents/<?= htmlspecialchars($doc['file_path'] ?? '') ?>"
                       target="_blank"
                       class="text-primary-400 hover:text-primary-300 transition-colors">
                        <i class="fas fa-download"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Upload Document Modal -->
<div id="uploadDocumentModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-md w-full">
        <div class="p-6 border-b border-gray-200 dark:border-white/10 flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">Upload Document</h3>
            <button onclick="document.getElementById('uploadDocumentModal').classList.add('hidden')"
                    class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form method="POST" action="/profile/upload-document" enctype="multipart/form-data" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-500 mb-2">Document Type</label>
                <select name="document_type" class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm">
                    <option value="certificate">Certificate</option>
                    <option value="contract">Contract</option>
                    <option value="id_copy">ID Copy</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-500 mb-2">Select File</label>
                <input type="file" name="document" class="w-full px-4 py-2 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm">
            </div>
            <button type="submit" class="btn btn-primary w-full">
                <i class="fas fa-upload mr-2"></i>Upload
            </button>
        </form>
    </div>
</div>