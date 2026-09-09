<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * InstrumentedMysqliStmt — \mysqli_stmt subclass that folds the full lifecycle
 * of ONE prepared statement into a single PerfTiming::recordQuery() event.
 *
 * A prepared query's cost is split between execute() (server round-trip for
 * the statement) and get_result() (row transfer). Both are accumulated into
 * the same logical query and flushed exactly once — at get_result(), or at
 * close() for statements that never fetch (INSERT/UPDATE/DELETE).
 *
 * Compatibility: supports both PHP 8.0 (no params) and PHP 8.1+ (?array $params).
 */
final class InstrumentedMysqliStmt extends \mysqli_stmt
{
    /** Accumulated ms across execute()/get_result() of this one query. */
    private float $pendingMs = 0.0;

    /** Whether execute() has been issued (only then is there a query to count). */
    private bool $issued = false;

    /** Whether parent::close() has been run (close() is idempotent — the app
     * runs with MYSQLI_REPORT_STRICT, so a second close() would otherwise
     * throw "Statement object is already closed"). */
    private bool $closed = false;

    public function __construct(\mysqli $link, string $query)
    {
        parent::__construct($link, $query);
    }

    public function execute(?array $params = null): bool
    {
        $start                = microtime(true);
        $result               = parent::execute($params);
        $this->pendingMs     += (microtime(true) - $start) * 1000.0;
        $this->issued         = true;
        return $result;
    }

    public function get_result(): \mysqli_result|bool
    {
        $start            = microtime(true);
        $result           = parent::get_result();
        $this->pendingMs += (microtime(true) - $start) * 1000.0;
        $this->flushOnce();
        return $result;
    }

    public function close(): bool
    {
        if ($this->closed) {
            return true;
        }
        $this->closed = true;
        $this->flushOnce();
        return parent::close();
    }

    /**
     * Emit the accumulated query exactly once. Subsequent close() calls are
     * no-ops so a double-close never double-counts.
     */
    private function flushOnce(): void
    {
        if (!$this->issued) {
            return;
        }
        PerfTiming::recordQuery($this->pendingMs);
        $this->pendingMs = 0.0;
        $this->issued    = false;
    }
}