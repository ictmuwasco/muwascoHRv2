import { useState, useEffect, useCallback } from 'react';
import { Search, Eye, RefreshCw, CheckCircle, XCircle, Clock, FileText, AlertCircle, User, Shield, Globe, MapPin, Calendar, Hash, Tag, Info } from 'lucide-react';
import Card from '../../components/ui/Card';
import Table from '../../components/ui/Table';
import Select from '../../components/ui/Select';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import { auditService } from '../../api/services/auditService';
import { userService } from '../../api/services/userService';
import type { AuditLog, AuditStatus, User as UserType } from '../../types';

interface AuditStatistics {
  total_logs: number;
  success: number;
  failed: number;
  last_30_days: number;
  by_module: Array<{ module: string; count: number }>;
}

interface AuditFilters {
  actions: string[];
  modules: string[];
  roles: string[];
  status: string[];
  users: string[];
}

interface AuditPagination {
  data: AuditLog[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
}

interface ResolvedUser {
  name: string;
  email: string;
  role: string;
  designation: string | null;
  employee_id: string | null;
}

const Audit = () => {
  // --- Data state ---
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [statistics, setStatistics] = useState<AuditStatistics | null>(null);
  const [filterOptions, setFilterOptions] = useState<AuditFilters>({
    actions: [],
    modules: [],
    roles: [],
    status: [],
    users: [],
  });
  const [pagination, setPagination] = useState<AuditPagination>({
    data: [],
    total: 0,
    page: 1,
    per_page: 20,
    pages: 0,
  });

  // --- UI state ---
  const [loading, setLoading] = useState(true);
  const [statsLoading, setStatsLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [activeFilters, setActiveFilters] = useState<Record<string, string | undefined>>({});
  const [selectedLog, setSelectedLog] = useState<AuditLog | null>(null);
  const [detailsModalOpen, setDetailsModalOpen] = useState(false);
  const [detailsLoading, setDetailsLoading] = useState(false);

  // --- Resolved user names (cache to avoid repeated API calls) ---
  const [resolvedUsers, setResolvedUsers] = useState<Record<number, ResolvedUser>>({});

  // --- Fetch all data on mount ---
  useEffect(() => {
    fetchStatistics();
    fetchFilterOptions();
  }, []);

  // --- Fetch logs when page, search, or filters change ---
  useEffect(() => {
    fetchLogs();
  }, [pagination.page, search, activeFilters]);

  const fetchStatistics = async () => {
    try {
      const stats = await auditService.getStatistics();
      setStatistics(stats);
    } catch (error) {
      console.error('Failed to fetch audit statistics:', error);
    } finally {
      setStatsLoading(false);
    }
  };

  const fetchFilterOptions = async () => {
    try {
      const options = await auditService.getFilters();
      setFilterOptions(options);
    } catch (error) {
      console.error('Failed to fetch filter options:', error);
    }
  };

  const fetchLogs = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, any> = {
        page: pagination.page,
        per_page: pagination.per_page,
      };

      // Apply active filters
      Object.entries(activeFilters).forEach(([key, value]) => {
        if (value) params[key] = value;
      });

      // Apply search
      if (search) params.search = search;

      const result = await auditService.getLogs(params);
      setLogs(result.data);
      setPagination({
        data: result.data,
        total: result.total,
        page: result.page,
        per_page: result.per_page,
        pages: result.pages,
      });
    } catch (error) {
      console.error('Failed to fetch audit logs:', error);
      setLogs([]);
    } finally {
      setLoading(false);
    }
  }, [pagination.page, pagination.per_page, search, activeFilters]);

  /**
   * Resolve a user ID to a real display name by calling the users API.
   * Results are cached in `resolvedUsers` so we don't refetch the same user.
   */
  const resolveUserName = useCallback(async (userId: number | null | undefined): Promise<ResolvedUser | null> => {
    if (!userId) return null;

    // Return cached result if available
    if (resolvedUsers[userId]) {
      return resolvedUsers[userId];
    }

    try {
      const response = await userService.getById(userId);
      const user: UserType = response.data;
      const resolved: ResolvedUser = {
        name: [user.first_name, user.last_name, user.surname].filter(Boolean).join(' ') || user.email || `User #${userId}`,
        email: user.email || '',
        role: user.role || '',
        designation: user.designation || null,
        employee_id: user.employee_id || null,
      };
      setResolvedUsers((prev) => ({ ...prev, [userId]: resolved }));
      return resolved;
    } catch (error) {
      console.error(`Failed to resolve user ${userId}:`, error);
      return null;
    }
  }, [resolvedUsers]);

  const handleViewDetails = async (log: AuditLog) => {
    setSelectedLog(log);
    setDetailsModalOpen(true);
    setDetailsLoading(true);
    try {
      // Fetch the full log with decoded JSON columns from the single-log endpoint
      const fullLog = await auditService.getLogById(log.id);
      setSelectedLog(fullLog);

      // Resolve user IDs to real names (actor + target)
      const idsToResolve: (number | null | undefined)[] = [fullLog.user_id];
      if (fullLog.target_type === 'User' && fullLog.target_id) {
        idsToResolve.push(fullLog.target_id);
      }
      await Promise.all(idsToResolve.map((id) => resolveUserName(id)));
    } catch (error) {
      console.error('Failed to fetch audit log details:', error);
      // Fall back to the row data we already have
    } finally {
      setDetailsLoading(false);
    }
  };

  const handleFilterChange = (key: string, value: string) => {
    setActiveFilters((prev) => ({
      ...prev,
      [key]: value || undefined,
    }));
    setPagination((prev) => ({ ...prev, page: 1 }));
  };

  const clearFilters = () => {
    setActiveFilters({});
    setSearch('');
    setPagination((prev) => ({ ...prev, page: 1 }));
  };

  const handlePageChange = (newPage: number) => {
    if (newPage >= 1 && newPage <= pagination.pages) {
      setPagination((prev) => ({ ...prev, page: newPage }));
    }
  };

  // --- Statistics cards ---
  const statCards = [
    {
      title: 'Total Logs',
      value: statistics?.total_logs ?? 0,
      icon: FileText,
      color: 'bg-blue-500',
      subtitle: 'All audit entries',
    },
    {
      title: 'Successful',
      value: statistics?.success ?? 0,
      icon: CheckCircle,
      color: 'bg-green-500',
      subtitle: 'Successful operations',
    },
    {
      title: 'Failed',
      value: statistics?.failed ?? 0,
      icon: XCircle,
      color: 'bg-red-500',
      subtitle: 'Failed operations',
    },
    {
      title: 'Last 30 Days',
      value: statistics?.last_30_days ?? 0,
      icon: Clock,
      color: 'bg-purple-500',
      subtitle: 'Recent activity',
    },
  ];

  // --- Table columns ---
  const columns = [
    { key: 'id', label: 'ID' },
    {
      key: 'user_name_snapshot',
      label: 'User',
      render: (value: any) => value || <span className="text-gray-400">System</span>,
    },
    { key: 'action', label: 'Action' },
    { key: 'module', label: 'Module' },
    {
      key: 'status',
      label: 'Status',
      render: (value: AuditStatus) => (
        <Badge variant={value === 'SUCCESS' ? 'success' : 'danger'}>
          {value}
        </Badge>
      ),
    },
    { key: 'description', label: 'Description' },
    { key: 'ip_address', label: 'IP Address' },
    { key: 'created_at', label: 'Timestamp' },
    {
      key: 'actions',
      label: 'Actions',
      render: (_value: any, row: AuditLog) => (
        <Button
          size="sm"
          variant="outline"
          onClick={() => handleViewDetails(row)}
          className="flex items-center gap-1"
        >
          <Eye className="h-3 w-3" />
          View Details
        </Button>
      ),
    },
  ];

  // --- Render helpers ---

  /**
   * Build a human-readable display name for a user, preferring the resolved
   * real name from the API, falling back to the snapshot or a placeholder.
   */
  const getActorDisplayName = (log: AuditLog): string => {
    if (log.user_id && resolvedUsers[log.user_id]) {
      return resolvedUsers[log.user_id].name;
    }
    return log.user_name_snapshot || 'System';
  };

  const getActorDisplayRole = (log: AuditLog): string => {
    if (log.user_id && resolvedUsers[log.user_id]) {
      return resolvedUsers[log.user_id].role;
    }
    return log.user_role_snapshot || '—';
  };

  const getActorDisplayEmail = (log: AuditLog): string => {
    if (log.user_id && resolvedUsers[log.user_id]) {
      return resolvedUsers[log.user_id].email;
    }
    return '';
  };

  const getActorDisplayDesignation = (log: AuditLog): string => {
    if (log.user_id && resolvedUsers[log.user_id]) {
      return resolvedUsers[log.user_id].designation || '';
    }
    return '';
  };

  const getTargetDisplayName = (log: AuditLog): string => {
    // If we resolved the target user, use the real name
    if (log.target_type === 'User' && log.target_id && resolvedUsers[log.target_id]) {
      return resolvedUsers[log.target_id].name;
    }
    // Fall back to the stored target_name
    return log.target_name || `User #${log.target_id ?? ''}`;
  };

  const getTargetDisplayEmail = (log: AuditLog): string => {
    if (log.target_type === 'User' && log.target_id && resolvedUsers[log.target_id]) {
      return resolvedUsers[log.target_id].email;
    }
    return '';
  };

  const getTargetDisplayRole = (log: AuditLog): string => {
    if (log.target_type === 'User' && log.target_id && resolvedUsers[log.target_id]) {
      return resolvedUsers[log.target_id].role;
    }
    return '';
  };

  const getTargetDisplayDesignation = (log: AuditLog): string => {
    if (log.target_type === 'User' && log.target_id && resolvedUsers[log.target_id]) {
      return resolvedUsers[log.target_id].designation || '';
    }
    return '';
  };

  const renderJsonData = (data: Record<string, any> | null | undefined, label: string) => {
    if (!data || Object.keys(data).length === 0) {
      return (
        <p className="text-sm text-gray-400 dark:text-gray-500 italic">No data recorded</p>
      );
    }
    return (
      <div className="space-y-1">
        {Object.entries(data).map(([key, value], idx) => (
          <div
            key={key}
            className={`grid grid-cols-1 md:grid-cols-3 gap-2 py-2 px-3 rounded-md text-sm ${
              idx % 2 === 0
                ? 'bg-gray-50 dark:bg-slate-700/30'
                : 'bg-white dark:bg-slate-800/30'
            }`}
          >
            <dt className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              {key}
            </dt>
            <dd className="md:col-span-2 text-sm text-gray-900 dark:text-gray-200 break-all font-mono">
              {typeof value === 'object' && value !== null
                ? JSON.stringify(value, null, 2)
                : String(value)}
            </dd>
          </div>
        ))}
      </div>
    );
  };

  const formatTimestamp = (ts: string) => {
    if (!ts) return 'N/A';
    try {
      return new Date(ts).toLocaleString();
    } catch {
      return ts;
    }
  };

  // --- Loading state ---
  if (loading && !logs.length && statsLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Audit Trail</h1>
        <p className="text-gray-500 dark:text-gray-400">View system activity and changes</p>
      </div>

      {/* Statistics Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {statCards.map((card) => (
          <Card key={card.title}>
            <div className="flex items-center space-x-4">
              <div className={`p-3 rounded-lg ${card.color}`}>
                <card.icon className="h-6 w-6 text-white" />
              </div>
              <div>
                <p className="text-sm text-gray-500 dark:text-gray-400">{card.title}</p>
                <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                  {statsLoading ? '...' : card.value}
                </p>
                <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">{card.subtitle}</p>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {/* Top Modules Chart (if data available) */}
      {statistics?.by_module && statistics.by_module.length > 0 && (
        <Card title="Activity by Module" subtitle="Top modules by log count">
          <div className="space-y-3">
            {statistics.by_module.map((item) => (
              <div key={item.module} className="flex items-center space-x-3">
                <div className="w-32 text-sm font-medium text-gray-700 dark:text-gray-300">
                  {item.module}
                </div>
                <div className="flex-1 bg-gray-200 dark:bg-slate-700 rounded-full h-6 overflow-hidden">
                  <div
                    className="bg-primary-600 h-full rounded-full transition-all duration-300"
                    style={{
                      width: `${Math.min(100, (item.count / (statistics?.total_logs || 1)) * 100)}%`,
                    }}
                  />
                </div>
                <div className="w-12 text-sm font-bold text-gray-900 dark:text-gray-100 text-right">
                  {item.count}
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}

      {/* Filters & Search */}
      <Card>
        <div className="space-y-4">
          {/* Search */}
          <div className="relative">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-5 w-5 text-gray-400" />
            <input
              type="text"
              placeholder="Search audit logs..."
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPagination((p) => ({ ...p, page: 1 })); }}
              className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500 dark:bg-slate-900 dark:border-slate-600 dark:text-gray-100"
            />
          </div>

          {/* Filter dropdowns */}
          <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
            <Select
              label="Action"
              options={filterOptions.actions.map((a) => ({ value: a, label: a }))}
              value={activeFilters.action || ''}
              onChange={(e) => handleFilterChange('action', e.target.value)}
            />
            <Select
              label="Module"
              options={filterOptions.modules.map((m) => ({ value: m, label: m }))}
              value={activeFilters.module || ''}
              onChange={(e) => handleFilterChange('module', e.target.value)}
            />
            <Select
              label="Role"
              options={filterOptions.roles.map((r) => ({ value: r, label: r }))}
              value={activeFilters.user_role_snapshot || ''}
              onChange={(e) => handleFilterChange('user_role_snapshot', e.target.value)}
            />
            <Select
              label="Status"
              options={filterOptions.status.map((s) => ({ value: s, label: s }))}
              value={activeFilters.status || ''}
              onChange={(e) => handleFilterChange('status', e.target.value)}
            />
            <Select
              label="User"
              options={filterOptions.users.map((u) => ({ value: u, label: u }))}
              value={activeFilters.user_name_snapshot || ''}
              onChange={(e) => handleFilterChange('user_name_snapshot', e.target.value)}
            />
          </div>

          {/* Clear filters button */}
          {(Object.keys(activeFilters).length > 0 || search) && (
            <div className="flex justify-end">
              <Button variant="outline" size="sm" onClick={clearFilters}>
                Clear Filters
              </Button>
            </div>
          )}
        </div>
      </Card>

      {/* Audit Log Table */}
      <Card>
        <div className="overflow-x-auto">
          <Table columns={columns} data={logs} />
        </div>

        {/* Pagination */}
        {pagination.pages > 1 && (
          <div className="flex items-center justify-between mt-4 pt-4 border-t dark:border-slate-700">
            <p className="text-sm text-gray-600 dark:text-gray-400">
              Showing page {pagination.page} of {pagination.pages} ({pagination.total} total records)
            </p>
            <div className="flex items-center space-x-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => handlePageChange(pagination.page - 1)}
                disabled={pagination.page <= 1 || loading}
              >
                Previous
              </Button>
              {Array.from({ length: Math.min(5, pagination.pages) }, (_, i) => {
                const pageNum = i + 1;
                return (
                  <Button
                    key={pageNum}
                    size="sm"
                    variant={pageNum === pagination.page ? 'primary' : 'outline'}
                    onClick={() => handlePageChange(pageNum)}
                    disabled={loading}
                  >
                    {pageNum}
                  </Button>
                );
              })}
              {pagination.pages > 5 && (
                <span className="text-sm text-gray-500 dark:text-gray-400">...</span>
              )}
              <Button
                size="sm"
                variant="outline"
                onClick={() => handlePageChange(pagination.page + 1)}
                disabled={pagination.page >= pagination.pages || loading}
              >
                Next
              </Button>
            </div>
          </div>
        )}

        {/* Empty state */}
        {!loading && logs.length === 0 && (
          <div className="text-center py-8">
            <FileText className="h-12 w-12 text-gray-300 dark:text-gray-600 mx-auto mb-3" />
            <p className="text-gray-500 dark:text-gray-400">No audit logs found</p>
            <p className="text-sm text-gray-400 dark:text-gray-500 mt-1">
              Try adjusting your search or filter criteria
            </p>
          </div>
        )}
      </Card>

      {/* Refresh button */}
      <div className="flex justify-end">
        <Button
          variant="outline"
          size="sm"
          onClick={() => {
            fetchStatistics();
            fetchLogs();
          }}
          disabled={loading}
        >
          <RefreshCw className={`h-4 w-4 mr-2 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </Button>
      </div>

      {/* View Details Modal — redesigned with a polished card-based layout */}
      <Modal
        isOpen={detailsModalOpen}
        onClose={() => setDetailsModalOpen(false)}
        title=""
        size="2xl"
        className="p-0"
      >
        {detailsLoading ? (
          <div className="flex items-center justify-center py-12">
            <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-600"></div>
          </div>
        ) : selectedLog ? (
          <div className="p-6">
            {/* ===== Header with status, action, module badges ===== */}
            <div className="flex items-start justify-between mb-6 pb-4 border-b border-gray-200 dark:border-slate-700">
              <div className="flex items-center gap-4">
                <div className="flex-shrink-0 w-12 h-12 rounded-xl bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                  <FileText className="h-6 w-6 text-primary-600 dark:text-primary-400" />
                </div>
                <div>
                  <h2 className="text-xl font-bold text-gray-900 dark:text-gray-100">
                    Audit Log Details
                  </h2>
                  <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    Log ID: <span className="font-mono font-medium text-gray-700 dark:text-gray-300">#{selectedLog.id}</span>
                  </p>
                </div>
              </div>
              <div className="flex flex-wrap gap-2">
                <Badge variant={selectedLog.status === 'SUCCESS' ? 'success' : 'danger'} className="text-xs">
                  {selectedLog.status}
                </Badge>
                <Badge variant="primary" className="text-xs">
                  {selectedLog.action}
                </Badge>
                <Badge variant="default" className="text-xs">
                  {selectedLog.module}
                </Badge>
              </div>
            </div>

            {/* ===== Core Details Grid ===== */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
              {/* Timestamp */}
              <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                <div className="flex items-center gap-2 mb-1">
                  <Calendar className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Timestamp
                  </span>
                </div>
                <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                  {formatTimestamp(selectedLog.created_at)}
                </p>
              </div>

              {/* Action */}
              <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                <div className="flex items-center gap-2 mb-1">
                  <Tag className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Action
                  </span>
                </div>
                <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                  {selectedLog.action}
                </p>
              </div>

              {/* Module */}
              <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                <div className="flex items-center gap-2 mb-1">
                  <Shield className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Module
                  </span>
                </div>
                <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                  {selectedLog.module}
                </p>
              </div>

              {/* Status */}
              <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                <div className="flex items-center gap-2 mb-1">
                  <Info className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Status
                  </span>
                </div>
                <Badge variant={selectedLog.status === 'SUCCESS' ? 'success' : 'danger'} className="text-sm">
                  {selectedLog.status}
                </Badge>
              </div>

              {/* Actor (User) — resolved to real name */}
              <div className="md:col-span-2 bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                <div className="flex items-center gap-2 mb-1">
                  <User className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Performed By
                  </span>
                </div>
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0 w-10 h-10 rounded-full bg-gray-200 dark:bg-slate-600 flex items-center justify-center">
                    <User className="h-5 w-5 text-gray-500 dark:text-gray-400" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                      {getActorDisplayName(selectedLog)}
                    </p>
                    {getActorDisplayEmail(selectedLog) && (
                      <p className="text-sm text-gray-500 dark:text-gray-400">
                        {getActorDisplayEmail(selectedLog)}
                      </p>
                    )}
                    {getActorDisplayDesignation(selectedLog) && (
                      <p className="text-xs text-gray-500 dark:text-gray-400">
                        {getActorDisplayDesignation(selectedLog)}
                      </p>
                    )}
                    <div className="flex flex-wrap gap-2 mt-1">
                      {getActorDisplayRole(selectedLog) && (
                        <Badge variant="default" className="text-xs">
                          {getActorDisplayRole(selectedLog)}
                        </Badge>
                      )}
                      {selectedLog.user_id && (
                        <span className="text-xs text-gray-400 dark:text-gray-500 font-mono">
                          ID: {selectedLog.user_id}
                        </span>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/* ===== Description ===== */}
            {selectedLog.description && (
              <div className="mb-6">
                <div className="flex items-center gap-2 mb-2">
                  <Info className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <h4 className="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Description
                  </h4>
                </div>
                <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                  <p className="text-sm text-gray-900 dark:text-gray-200 whitespace-pre-wrap leading-relaxed">
                    {selectedLog.description}
                  </p>
                </div>
              </div>
            )}

            {/* ===== Target Record — resolved to real name ===== */}
            {(selectedLog.target_type || selectedLog.target_id || selectedLog.target_name) && (
              <div className="mb-6">
                <div className="flex items-center gap-2 mb-2">
                  <Hash className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <h4 className="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Target Record
                  </h4>
                </div>
                <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {selectedLog.target_type && (
                      <div>
                        <div className="text-xs text-gray-500 dark:text-gray-400 mb-1">Type</div>
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                          {selectedLog.target_type}
                        </div>
                      </div>
                    )}
                    {selectedLog.target_id && (
                      <div>
                        <div className="text-xs text-gray-500 dark:text-gray-400 mb-1">ID</div>
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                          {selectedLog.target_id}
                        </div>
                      </div>
                    )}
                    <div>
                      <div className="text-xs text-gray-500 dark:text-gray-400 mb-1">Name</div>
                      <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {getTargetDisplayName(selectedLog)}
                      </div>
                      {getTargetDisplayEmail(selectedLog) && (
                        <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                          {getTargetDisplayEmail(selectedLog)}
                        </p>
                      )}
                      {getTargetDisplayDesignation(selectedLog) && (
                        <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                          {getTargetDisplayDesignation(selectedLog)}
                        </p>
                      )}
                      {getTargetDisplayRole(selectedLog) && (
                        <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                          Role: {getTargetDisplayRole(selectedLog)}
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* ===== Network Information ===== */}
            {(selectedLog.ip_address || selectedLog.location || selectedLog.user_agent) && (
              <div className="mb-6">
                <div className="flex items-center gap-2 mb-2">
                  <Globe className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <h4 className="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Network Information
                  </h4>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {selectedLog.ip_address && (
                    <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                      <div className="flex items-center gap-2 mb-1">
                        <Globe className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                        <span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          IP Address
                        </span>
                      </div>
                      <p className="text-sm font-mono text-gray-900 dark:text-gray-100">
                        {selectedLog.ip_address}
                      </p>
                    </div>
                  )}
                  {selectedLog.location && (
                    <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                      <div className="flex items-center gap-2 mb-1">
                        <MapPin className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                        <span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          Location
                        </span>
                      </div>
                      <p className="text-sm text-gray-900 dark:text-gray-100">
                        {selectedLog.location}
                      </p>
                    </div>
                  )}
                  {selectedLog.user_agent && (
                    <div className="md:col-span-2 bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                      <div className="flex items-center gap-2 mb-1">
                        <Info className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                        <span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          User Agent
                        </span>
                      </div>
                      <p className="text-sm text-gray-900 dark:text-gray-100 break-all">
                        {selectedLog.user_agent}
                      </p>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* ===== Data Changes ====== */}
            {(selectedLog.old_values || selectedLog.new_values) && (
              <div className="mb-6">
                <div className="flex items-center gap-2 mb-2">
                  <AlertCircle className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <h4 className="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Data Changes
                  </h4>
                </div>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                  <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                    <h5 className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
                      Old Values
                    </h5>
                    {renderJsonData(selectedLog.old_values, 'Old Values')}
                  </div>
                  <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                    <h5 className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
                      New Values
                    </h5>
                    {renderJsonData(selectedLog.new_values, 'New Values')}
                  </div>
                </div>
              </div>
            )}

            {/* ===== Metadata ===== */}
            {selectedLog.metadata && Object.keys(selectedLog.metadata).length > 0 && (
              <div className="mb-6">
                <div className="flex items-center gap-2 mb-2">
                  <Info className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                  <h4 className="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Metadata
                  </h4>
                </div>
                <div className="bg-gray-50 dark:bg-slate-700/30 rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                  {renderJsonData(selectedLog.metadata, 'Metadata')}
                </div>
              </div>
            )}
          </div>
        ) : null}
      </Modal>
    </div>
  );
};

export default Audit;

