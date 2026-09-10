<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * InstrumentedMysqli — \mysqli subclass that transparently records every SQL
 * execution into PerfTiming (Phase 2 performance instrumentation).
 *
 * Database::connect() creates this subclass instead of a bare mysqli, so BOTH
 * the Database::query() helper path AND the many raw repository/controller
 * `$conn->query(...)` / `$conn->prepare(...)` call sites are measured without
 * touching a single query string. Compatible with PHP 8.0 (probe-verified:
 * subclassing mysqli + mysqli_stmt and constructor-prepared statements work).
 *
 * SECURITY: only execution count + duration are captured — never the SQL text,
 * bound values, results or connection credentials.
 */
final class InstrumentedMysqli extends \mysqli
{
    /**
     * Non-prepared query execution (Database helper + raw `$conn->query()`).
     */
    public function query(string $query, int $resultMode = MYSQLI_STORE_RESULT): \mysqli_result|bool
    {
        $start  = microtime(true);
        $result = parent::query($query, $resultMode);
        PerfTiming::recordQuery((microtime(true) - $start) * 1000.0);
        return $result;
    }

    /**
     * Buffered-real-mode query (rare; kept for completeness).
     */
    public function real_query(string $query): bool
    {
        $start  = microtime(true);
        $result = parent::real_query($query);
        PerfTiming::recordQuery((microtime(true) - $start) * 1000.0);
        return $result;
    }

    /**
     * Prepared statements are wrapped so execute()/get_result() timing is
     * captured as ONE logical query (see InstrumentedMysqliStmt).
     */
    public function prepare(string $query): \mysqli_stmt
    {
        return new InstrumentedMysqliStmt($this, $query);
    }

    /**
     * exec() proxy for multi-statement SQL (used by migration runner for
     * DELIMITER-bearing scripts such as 026_attendance_audit_fields.sql).
     *
     * mysqli::exec() was introduced in PHP 8.1; older versions do not
     * expose it, so we guard against a missing parent method.
     */
    public function exec(string $query): bool
    {
        $start = microtime(true);
        if (method_exists(parent::class, 'exec')) {
            $result = parent::exec($query);
        } else {
            $result = $this->multi_query($query);
            while ($this->more_results() && $this->next_result()) {
                $res = $this->store_result();
                if ($res !== false && $res !== null) { $res->free(); }
            }
        }
        PerfTiming::recordQuery((microtime(true) - $start) * 1000.0);
        return $result;
    }
}