<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;

/**
 * Roles Controller - REST API for the canonical roles reference.
 *
 * Serves the `roles` lookup table (migration 083) so forms and pages can
 * render role lists, labels and ordering WITHOUT hardcoding role strings.
 *
 * Response shape: { data: [ { key, label, description, is_system, sort_order } ] }
 *
 * Reference-data policy: role keys and labels are non-sensitive (they are
 * already visible in permission matrices and audit snapshots), so this
 * endpoint is authenticated-only — like /employees/reference and /holidays —
 * rather than gated on a settings permission. Creating/assigning roles
 * remains guarded by users:* permissions on the user endpoints.
 *
 * Fallback contract: if the roles table is missing or empty (fresh database
 * before migrations run), the catalog in config/permissions.php is served so
 * consumers never render an empty role list.
 *
 * Place: backend/app/Controllers/Settings/RolesController.php
 */
class RolesController extends BaseController
{
    /**
     * GET /api/roles - Active roles for dropdowns and role-aware UIs.
     */
    public function indexAction(): void
    {
        try {
            $roles = $this->allRoles();
            $this->success($roles);
        } catch (\Exception $e) {
            \logger()->error('Roles listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve roles. Please try again.', 500);
        }
    }

    /**
     * Active roles ordered for display, from the roles table with a
     * catalog fallback.
     *
     * @return array<int, array{key:string, label:string, description:?string, is_system:bool}>
     */
    private function allRoles(): array
    {
        try {
            $conn = db()->getConnection();
            $table = $conn->query("SHOW TABLES LIKE 'roles'");
            if ($table && $table->num_rows > 0) {
                $table->free();
                $stmt = $conn->prepare(
                    'SELECT `key`, label, description, is_system
                     FROM roles
                     WHERE is_active = 1
                     ORDER BY sort_order ASC, label ASC'
                );
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = $result->fetch_all(\MYSQLI_ASSOC);
                $stmt->close();

                if (!empty($rows)) {
                    return array_map(static fn (array $row): array => [
                        'key'         => (string) $row['key'],
                        'label'       => (string) $row['label'],
                        'description' => $row['description'] ?? null,
                        'is_system'   => (int) $row['is_system'] === 1,
                    ], $rows);
                }
            } elseif ($table) {
                $table->free();
            }
        } catch (\Throwable $e) {
            \logger()->warning('Roles table unavailable, falling back to catalog', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->catalogRoles();
    }

    /**
     * Fallback: roles from config/permissions.php (single source of truth
     * before migration 083 is applied).
     *
     * @return array<int, array{key:string, label:string, description:null, is_system:true}>
     */
    private function catalogRoles(): array
    {
        $catalog = null;
        if (function_exists('config')) {
            $catalog = config('permissions');
        }
        if (!is_array($catalog) || empty($catalog['roles'])) {
            $path = defined('CONFIG_PATH')
                ? CONFIG_PATH . '/permissions.php'
                : __DIR__ . '/../../../config/permissions.php';
            $catalog = is_file($path) ? require $path : ['roles' => []];
        }

        $labels = is_array($catalog['role_labels'] ?? null) ? $catalog['role_labels'] : [];
        $roles = [];

        foreach (($catalog['roles'] ?? []) as $key) {
            $roles[] = [
                'key'         => (string) $key,
                'label'       => (string) ($labels[$key] ?? ucwords(str_replace('_', ' ', (string) $key))),
                'description' => null,
                'is_system'   => true,
            ];
        }

        return $roles;
    }
}
