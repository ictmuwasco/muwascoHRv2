<?php

declare(strict_types=1);

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Database\QueryLogger;

/**
 * Query Log Controller
 * 
 * REST API for query performance monitoring.
 * Requires 'system:view' permission.
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
     */
    public function reset(): void
    {
        $this->requirePermission('system', 'view');
        
        QueryLogger::reset();
        $this->success(null, 'Query log reset');
    }
}
