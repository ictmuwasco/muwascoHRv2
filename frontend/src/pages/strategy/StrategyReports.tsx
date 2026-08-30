import { useState, useEffect, useCallback } from 'react';
import { Link, useLocation } from 'react-router-dom';
import apiClient from '../../api/client';
import Card from '../../components/ui/Card';
import Table from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import { BarChart3, AlertTriangle, RefreshCw, Target, FileText, Users } from 'lucide-react';

interface ReportPayload {
  generated_at: string;
  scope: { department_id: number | null };
  plan_progress: {
    id: number; name: string; start_date: string; end_date: string;
    goals: number; targets: number; contracts: number;
  }[];
  departmental_performance: {
    department_id: number; department: string;
    contracts: number; workplans: number; kpis: number;
  }[];
  kpi_achievement: {
    kpi_name: string; target: string | null; latest_score: string | null;
    contract_name: string | null; department_name: string | null;
  }[];
  workplan_summary: { total_objectives: number; with_year_targets: number; note: string | null };
}

const TABS = [
  { to: '/strategy/strategic-plan', label: 'Strategic Plan' },
  { to: '/strategy/performance-contracts', label: 'Performance Contracts' },
  { to: '/strategy/workplans', label: 'Workplans' },
  { to: '/strategy/reports', label: 'Performance Reports' },
];

export default function StrategyReports() {
  const location = useLocation();
  const [data, setData] = useState<ReportPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await apiClient.get('/reports/strategic-performance');
      setData(res.data?.data ?? null);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to generate the strategic performance report.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    );
  }

  if (!data) {
    return <Card title="Performance Reports"><p className="text-gray-500">{error || 'No report data available.'}</p></Card>;
  }

  const statCards = [
    { label: 'Strategic plans', value: data.plan_progress.length, icon: Target },
    { label: 'Departments reporting', value: data.departmental_performance.length, icon: Users },
    { label: 'Workplan objectives', value: data.workplan_summary.total_objectives, icon: FileText },
    { label: 'KPIs recorded', value: data.kpi_achievement.length, icon: BarChart3 },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Performance Reports</h1>
          <p className="text-gray-500">
            Strategic performance report · generated {new Date(data.generated_at).toLocaleString()}
            {data.scope.department_id ? ' · scoped to your department' : ''}
          </p>
        </div>
        <Button variant="outline" onClick={load}><RefreshCw className="h-4 w-4 mr-2" />Refresh</Button>
      </div>

      {/* Strategy sub-navigation */}
      <div className="flex space-x-1 border-b overflow-x-auto">
        {TABS.map((tab) => (
          <Link key={tab.to} to={tab.to}
            className={`px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${
              location.pathname === tab.to ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}>
            {tab.label}
          </Link>
        ))}
      </div>

      {error && (
        <div className="rounded-lg px-4 py-3 text-sm flex items-start gap-2 bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300">
          <AlertTriangle className="h-4 w-4 mt-0.5" />
          <span>{error}</span>
        </div>
      )}

      {/* Summary cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {statCards.map(({ label, value, icon: Icon }) => (
          <Card key={label}>
            <div className="flex items-center gap-3">
              <div className="rounded-lg bg-primary-50 dark:bg-slate-700 p-2.5">
                <Icon className="h-5 w-5 text-primary-600" />
              </div>
              <div>
                <p className="text-xs text-gray-500">{label}</p>
                <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{value}</p>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {/* Plan progress */}
      <Card title="Strategic plan progress">
        {data.plan_progress.length === 0 ? (
          <p className="text-gray-500">No strategic plans recorded yet.</p>
        ) : (
          <Table
            columns={[
              { key: 'name', label: 'Plan' },
              {
                key: 'period',
                label: 'Period',
                render: (_v, row) =>
                  `${new Date(row.start_date).toLocaleDateString('en-GB')} – ${new Date(row.end_date).toLocaleDateString('en-GB')}`,
              },
              { key: 'goals', label: 'Goals' },
              { key: 'targets', label: 'Org. Goals' },
              { key: 'contracts', label: 'Contracts' },
            ]}
            data={data.plan_progress}
          />
        )}
      </Card>
{/* Departmental performance */}
      <Card title="Departmental performance">
        {data.departmental_performance.length === 0 ? (
          <p className="text-gray-500">No departmental performance data available.</p>
        ) : (
          <Table
            columns={[
              { key: 'department', label: 'Department' },
              { key: 'contracts', label: 'Contracts' },
              { key: 'workplans', label: 'Workplan objectives' },
              { key: 'kpis', label: 'KPIs' },
              {
                key: 'coverage',
                label: 'Contract coverage',
                render: (_v, row) => (Number(row.contracts) > 0
                  ? <Badge variant="success">Active</Badge>
                  : <Badge variant="default">No contracts</Badge>),
              },
            ]}
            data={data.departmental_performance}
          />
        )}
      </Card>

      {/* KPI achievement */}
      <Card title="KPI achievement"
        subtitle="Only KPIs with recorded scores are shown as measured; the rest are awaiting evidence.">
        {data.kpi_achievement.length === 0 ? (
          <p className="text-gray-500">No performance data available — no KPI scores have been recorded yet.</p>
        ) : (
          <Table
            columns={[
              { key: 'kpi_name', label: 'KPI' },
              { key: 'target', label: 'Target', render: (v) => v ?? '—' },
              { key: 'latest_score', label: 'Latest score', render: (v) => v ?? 'Awaiting score' },
              { key: 'contract_name', label: 'Contract', render: (v) => v ?? '—' },
              { key: 'department_name', label: 'Department', render: (v) => v ?? '—' },
            ]}
            data={data.kpi_achievement.filter((k) => k.latest_score !== null)}
          />
        )}
      </Card>

      {/* Workplan summary */}
      <Card title="Workplan completion">
        <div className="space-y-1 text-sm">
          <p><span className="font-medium">Total workplan objectives:</span> {data.workplan_summary.total_objectives}</p>
          <p><span className="font-medium">With year targets captured:</span> {data.workplan_summary.with_year_targets}</p>
          {data.workplan_summary.note && (
            <p className="text-xs text-gray-400 mt-1">{data.workplan_summary.note}</p>
          )}
        </div>
      </Card>
    </div>
  );
}