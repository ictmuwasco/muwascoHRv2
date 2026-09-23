<?php

declare(strict_types=1);

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Database\QueryLogger;
use App\Services\AuditService;

/**
 * Query Log Controller
 *
 * REST API for query performance monitoring.
 * Requires 'system:view' permission (reads); reset requires 'system:manage'.
 */
class QueryLogController extends BaseController
{
    /**
     * GET /api/system/query-log/statistics
     */
    public function statistics(): void
    {
        $this->requirePermission('system', 'view');
        
        $this->success(QueryLogger::getStatistics(), 'Query statistics retrieved');
    }

    /**
     * GET /api/system/query-log/slow
     */
    public function slow(): void
    {
        $this->requirePermission('system', 'view');
        
        $this->success([
            'slow_queries' => QueryLogger::getSlowQueries(),
            'threshold_ms' => QueryLogger::getSlowQueryThreshold(),
        ], 'Slow queries retrieved');
    }

    /**
     * GET /api/system/query-log
     */
    public function index(): void
    {
        $this->requirePermission('system', 'view');
        
        $this->success([
            'log' => QueryLogger::getQueryLog(),
            'statistics' => QueryLogger::getStatistics(),
        ], 'Query log retrieved');
    }

    /**
     * POST /api/system/query-log/reset
     *
     * Mutation (clears in-memory query metrics) — requires system:manage and
     * is audited explicitly; the route-level safety-net audit does not apply
     * because this is not a domain-data change.
     */
    public function reset(): void
    {
        $this->requirePermission('system', 'manage');

        QueryLogger::reset();

        AuditService::getInstance()->log(
            AuditService::MODULE_SYSTEM,
            AuditService::ACTION_RESET,
            'Query log metrics reset',
            [
                'target_type' => 'QueryLog',
                'metadata'    => ['component' => 'QueryLogger'],
            ]
        );

        $this->success(null, 'Query log reset');
    }
}
