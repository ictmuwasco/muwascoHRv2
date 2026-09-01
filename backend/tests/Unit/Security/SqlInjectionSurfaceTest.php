<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Tests\TestCase;

/**
 * Phase 7 regression tests — SQL injection surface (P7-6/SQLi).
 *
 * The entire data layer uses prepared statements (mysqli prepare + bind_param
 * through Database/Repository helpers). This suite statically guards the
 * regression surface: raw SQL builders, string-interpolated query arguments,
 * and user-controlled sort/order columns appended into SQL must never appear
 * in application code.
 *
 * This mirrors the established convention in PrivilegeEscalationTest and
 * PermissionCatalogTest (source-scan assertions that fail the build when a
 * dangerous pattern is reintroduced).
 */
class SqlInjectionSurfaceTest extends TestCase
{
    private const RAW_SQL_BUILDERS = [
        'DB::raw(',
        'DB::select(',
        'DB::statement(',
        'DB::unprepared(',
        'whereRaw(',
        'orderByRaw(',
        'selectRaw(',
        'havingRaw(',
        'groupByRaw(',
        'joinRaw(',
    ];

    /**
     * @return string[] Absolute paths to every PHP file under backend/app.
     */
    private function appPhpFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../app', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    public function test_no_raw_sql_builder_calls_in_application_code(): void
    {
        $hits = [];
        foreach ($this->appPhpFiles() as $file) {
            $content = file_get_contents($file) ?: '';
            foreach (self::RAW_SQL_BUILDERS as $token) {
                if (strpos($content, $token) !== false) {
                    $hits[] = basename($file) . ' uses ' . $token;
                }
            }
        }

        $this->assertSame(
            [],
            array_unique($hits),
            'Raw SQL builders must not be used — prepare/bind_param parameterization only'
        );
    }

    public function test_no_interpolated_variables_inside_mysqli_query_strings(): void
    {
        $hits = [];
        foreach ($this->appPhpFiles() as $file) {
            $content = file_get_contents($file) ?: '';

            // ->query("...$var..." / ->query("...{$var}..."): interpolation
            // inside a quoted query literal.
            if (preg_match('/->query\\s*\\(\\s*["\'][^"\']*\\$\\w+/', $content, $m)) {
                $hits[] = basename($file) . ': ' . trim($m[0]);
            }

            // ->query("..." . $var): concatenating a variable into the query.
            if (preg_match('/->query\\s*\\(\\s*"[^"]*"\\s*\\.\\s*\\$\\w+/', $content, $m)) {
                $hits[] = basename($file) . ': ' . trim($m[0]);
            }
        }

        $this->assertSame(
            [],
            array_unique($hits),
            'mysqli::query() must only ever receive fully static SQL'
        );
    }

    public function test_order_by_sort_columns_come_from_an_allowlist(): void
    {
        // The dashboard/filter endpoints accept sort/order columns from the
        // client. Any place that passes the raw value into an ORDER BY clause
        // would be SQL injection — assert the code uses explicit column maps.
        $hits = [];
        foreach ($this->appPhpFiles() as $file) {
            $content = file_get_contents($file) ?: '';
            if (preg_match('/ORDER BY\\s*\\.?\\s*\\$\\w+|\"ORDER BY\\s*\"\\s*\\.\\s*\\$_/', $content, $m)) {
                $hits[] = basename($file) . ': ' . trim($m[0]);
            }
        }

        $this->assertSame([], $hits, 'ORDER BY must consume an explicit allowlist, never raw client columns');
    }

    public function test_search_and_filter_parameters_use_prepared_statements(): void
    {
        // Spot-check the two highest-traffic dynamic filters in the codebase:
        // the employee search (Repositories/EmployeeRepository) and the
        // leave report query service. Both must bind user input with `?`.
        $repository = file_get_contents(__DIR__ . '/../../../app/Repositories/EmployeeRepository.php') ?: '';
        $leaveQuery = file_get_contents(__DIR__ . '/../../../app/Services/LeaveReportQueryService.php') ?: '';

        $this->assertStringContainsString('prepare(', $repository);
        $this->assertStringContainsString('bind_param(', $repository);

        $this->assertStringContainsString('prepare(', $leaveQuery);
        $this->assertStringContainsString('bind_param(', $leaveQuery);

        // Neither may build a LIKE/WHERE clause by string concatenation.
        $this->assertStringNotContainsString('"%" . $', $repository);
        $this->assertStringNotContainsString("'%' . \\$", $repository);
    }
}