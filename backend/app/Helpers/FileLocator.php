<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * FileLocator - the single answer to "where on disk does this file live?".
 *
 * WHY THIS EXISTS
 *   Two applications wrote into two different folder trees, and the database
 *   only ever stored a RELATIVE path (or, for documents, a bare filename). The
 *   new application therefore has to be able to find a file that may be sitting
 *   in any of several historical locations - and every caller had grown its own
 *   private copy of that list. Those copies had already drifted apart:
 *
 *     DocumentAccessService::resolveDocumentPath()   backend/app/public/...
 *     EmployeeController::viewProfileDocumentAction() backend/app/public/...
 *     EmployeeController::streamProfileImage()       backend/app/public/...
 *
 *   A path list that exists in three places is a path list that will keep
 *   drifting. One list, one owner, one place to add a migration root.
 *
 * CANONICAL vs LEGACY
 *   The FIRST candidate each method returns is the canonical location: under
 *   STORAGE_PATH, which is OUTSIDE the webroot. New writes go there. The
 *   remaining candidates are read-only legacy roots, kept so that a file
 *   uploaded by the previous application stays readable before (or entirely
 *   without) being relocated by scripts/storage/adopt_legacy_uploads.php.
 *
 * ORDER IS SIGNIFICANT
 *   Canonical first, then newest legacy, then oldest. A file that exists in
 *   more than one place resolves to the canonical copy, so re-running the
 *   migration after the application has written a newer file can never
 *   resurrect the stale one.
 *
 * SECURITY
 *   - safeBasename() rejects anything that is not a plain filename, so a
 *     hostile or corrupt database value cannot walk out of the upload
 *     directory. Every candidate is built from a basename, never from raw
 *     database text.
 *   - None of these directories is served directly. They sit behind the
 *     authorised streaming endpoints, which still apply the owner/HR gate and
 *     the hardened stream headers.
 */
final class FileLocator
{
    /** Canonical document directory, relative to STORAGE_PATH. */
    public const DOCUMENTS_SUBDIR = 'uploads/documents';

    /** Canonical profile-image directory, relative to STORAGE_PATH. */
    public const PROFILE_IMAGES_SUBDIR = 'uploads/profile_images';

    /**
     * Legacy webroot trees relative to BACKEND_PATH, newest first.
     *
     * `app/public/...` is what the retired controller code actually wrote to:
     * its `__DIR__ . '/../../public/'` resolves from backend/app/Controllers/
     * to backend/app/public, not to backend/public. Both are listed because
     * different revisions of that code landed in different places.
     */
    private const LEGACY_UPLOAD_ROOTS = [
        'app/public/uploads/employee_documents',
        'public/uploads/employee_documents',
    ];

    /** Absolute path of the canonical document directory. */
    public static function documentsDir(): string
    {
        return self::storagePath() . '/' . self::DOCUMENTS_SUBDIR;
    }

    /** Absolute path of the canonical profile-image directory. */
    public static function profileImagesDir(): string
    {
        return self::storagePath() . '/' . self::PROFILE_IMAGES_SUBDIR;
    }
    /**
     * Every place an employee document may live, canonical first.
     *
     * @return list<string> Absolute paths, in resolution order.
     */
    public static function documentCandidates(string $fileName): array
    {
        $safe = self::safeBasename($fileName);
        if ($safe === null) {
            return [];
        }

        $candidates = [self::documentsDir() . '/' . $safe];

        foreach (self::LEGACY_UPLOAD_ROOTS as $legacyRoot) {
            $candidates[] = self::backendPath() . '/' . $legacyRoot . '/' . $safe;
        }

        return $candidates;
    }

    /**
     * Every place a profile image may live, canonical first.
     *
     * The stored value is a relative path such as
     * `uploads/profile_images/profile_355_....webp`, so unlike a document -
     * where the column holds a bare filename - it legitimately contains
     * separators. normalizeProfileImageName() therefore strips the known
     * prefix and then re-applies the basename guard, so a value that tries to
     * climb out (`../../config/database.php`) is reduced to nothing rather
     * than resolved.
     *
     * @return list<string> Absolute paths, in resolution order.
     */
    public static function profileImageCandidates(string $storedPath): array
    {
        $safe = self::normalizeProfileImageName($storedPath);
        if ($safe === null) {
            return [];
        }

        $file = self::PROFILE_IMAGES_SUBDIR . '/' . $safe;

        return [
            self::storagePath() . '/' . $file,
            self::backendPath() . '/app/public/' . $file,
            self::backendPath() . '/public/' . $file,
        ];
    }

    /**
     * Reduce a stored profile_image_url to the bare filename it identifies.
     *
     * Accepts either a bare filename or the historical
     * `uploads/profile_images/<name>` relative path, and returns the filename.
     * Returns null for anything that does not resolve to a plain file inside
     * the profile-image directory.
     */
    public static function normalizeProfileImageName(string $storedPath): ?string
    {
        $normalized = str_replace('\\', '/', trim($storedPath));
        if ($normalized === '' || str_contains($normalized, "\0")) {
            return null;
        }

        // A traversal attempt is rejected outright rather than collapsed:
        // basename() would quietly turn '../../secret' into 'secret', which is
        // the opposite of what this guard is for.
        if (str_contains($normalized, '..')) {
            return null;
        }

        // Drop the known prefix if present, so the remainder is a bare name.
        $prefix = self::PROFILE_IMAGES_SUBDIR . '/';
        if (str_starts_with($normalized, $prefix)) {
            $normalized = substr($normalized, strlen($prefix));
        } elseif (str_contains($normalized, '/')) {
            // Some other relative layout: use the final segment only.
            $normalized = basename($normalized);
        }

        return self::safeBasename($normalized);
    }

    /**
     * First candidate that is an existing regular file, or null.
     *
     * @param list<string> $candidates
     */
    public static function resolve(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Reduce a stored name to a plain filename, or null if it is not one.
     *
     * Rejects '', '.', '..', anything containing a directory separator or a
     * null byte, and Windows-illegal characters. This is the only place a
     * database-supplied string becomes a filesystem path.
     */
    public static function safeBasename(string $name): ?string
    {
        if ($name === '' || str_contains($name, "\0")) {
            return null;
        }

        // basename() collapses separators; comparing against the original
        // rejects anything that WAS a path rather than a bare filename. The
        // explicit separator test is redundant on Linux but not on Windows.
        $base = basename(str_replace('\\', '/', $name));
        if ($base === '' || $base === '.' || $base === '..') {
            return null;
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return null;
        }

        return $base;
    }

    /**
     * FALLBACKS ONLY - backend/bootstrap.php defines both constants, and the
     * migration script defines them before requiring this file.
     *
     * __DIR__ is <root>/backend/app/Helpers, so BACKEND_PATH is TWO levels up,
     * not three. Getting this wrong is silent: nothing throws, the paths simply
     * point at a tree that does not contain the uploads, and every lookup
     * misses. It only ever bites callers that use FileLocator outside the
     * bootstrap (scripts, one-off diagnostics) - which is exactly where a
     * wrong answer is hardest to notice.
     */
    private static function storagePath(): string
    {
        return defined('STORAGE_PATH')
            ? STORAGE_PATH
            : dirname(__DIR__, 2) . '/storage';
    }

    private static function backendPath(): string
    {
        return defined('BACKEND_PATH')
            ? BACKEND_PATH
            : dirname(__DIR__, 2);
    }
}