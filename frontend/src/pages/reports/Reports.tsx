import { useState, useEffect, useMemo, useCallback } from 'react';
import apiClient from '../../api/client';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Select from '../../components/ui/Select';
import Table from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import { FileText, Download, BarChart3, Users, Search, ChevronLeft, ChevronRight, RefreshCw, Loader2, CalendarDays, UserCheck, UserX, X } from 'lucide-react';
import { toCsv, downloadCsv, csvFilenameWithDate } from '../../utils/csvUtils';
import { Link } from 'react-router-dom';
import type { ElementType } from 'react';

// ---- Types ---------------------------------------------------------------
type EmployeeRecord = {
  id: number;
  employee_id: string;
  first_name: string;
  last_name: string;
  surname: string | null;
  email: string;
  phone: string;
  gender: string;
  employee_status: string;
  employee_type: string;
  designation: string;
  department_name: string | null;
  section_name: string | null;
  date_of_birth: string | null;
  contract_start_date: string | null;
  contract_end_date: string | null;
  national_id: string;
  hire_date: string;
  created_at: string;
};

type Summary = {
  total: number;
  active: number;
  inactive: number;
  by_department: Record<string, number>;
  by_employment_type: Record<string, number>;
  by_status: Record<string, number>;
  by_gender: Record<string, number>;
};

type ReportData = {
  summary: Summary;
  records: EmployeeRecord[];
};

type StatCardProps = {
  title: string;
  value: string | number;
  icon: React.ElementType;
  subtitle?: string;
  variant?: 'default' | 'warning' | 'danger';
  onClick?: () => void;
  selected?: boolean;
};

// ---- Helpers -------------------------------------------------------------
const RETIREMENT_AGE = 60;
const RETIREMENT_WINDOW_YEARS = 5; // treat anyone within 5 years of retirement as "near retirement"
const CONTRACT_WARNING_MONTHS = 6;
const PER_PAGE = 50;

// Display names for the stat-card "quick filters" applied to the All Employees table.
const QUICK_FILTER_LABELS: Record<string, string> = {
  active: 'Active employees',
  inactive: 'Inactive employees',
  nearRetirement: `Near retirement (within ${RETIREMENT_WINDOW_YEARS} yrs of ${RETIREMENT_AGE}, excl. retired)`,
  contractsExpiring: `Contracts expiring (${CONTRACT_WARNING_MONTHS} mo window)`,
  contractsExpired: 'Expired contracts',
};

const getAge = (dob: string | null): number | null => {
  if (!dob) return null;
  const birth = new Date(dob);
  if (Number.isNaN(birth.getTime())) return null;
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const m = today.getMonth() - birth.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
  return age;
};

const formatISODate = (value: string | null): string => {
  if (!value) return '-';
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return value;
  return parsed.toLocaleDateString();
};

const monthsFromNow = (months: number): Date => {
  const d = new Date();
  d.setMonth(d.getMonth() + months);
  return d;
};

// ---- Components ----------------------------------------------------------
const StatCard: React.FC<StatCardProps> = ({ title, value, icon: Icon, subtitle, variant = 'default', onClick, selected = false }) => {
  const variantClasses = {
    default: 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700',
    warning: 'bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-900/30',
    danger: 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-900/30',
  } as const;

  const iconBg = {
    default: 'bg-blue-100 dark:bg-blue-900/30',
    warning: 'bg-amber-100 dark:bg-amber-900/30',
    danger: 'bg-red-100 dark:bg-red-900/30',
  } as const;

  const iconColor = {
    default: 'text-blue-500',
    warning: 'text-amber-500',
    danger: 'text-red-500',
  } as const;

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={!onClick}
      title={onClick ? 'Click to filter the employees table' : undefined}
      className={`rounded-xl p-4 flex items-center gap-3 text-left transition-all ${variantClasses[variant]} ${
        onClick ? 'cursor-pointer hover:shadow-md' : 'cursor-default'
      } ${selected ? 'ring-2 ring-primary-500' : ''}`}
    >
      <div className={`p-2 rounded-lg ${iconBg[variant]}`}>
        <Icon className={`h-5 w-5 ${iconColor[variant]}`} />
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider truncate">{title}</p>
        <p className="text-2xl font-bold text-gray-900 dark:text-white break-normal">{value}</p>
        {subtitle && <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">{subtitle}</p>}
      </div>
    </button>
  );
};

// ---- Page ---------------------------------------------------------------
const Reports = () => {
  const [activeTab, setActiveTab] = useState('employees');
  const [data, setData] = useState<ReportData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [deptFilter, setDeptFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [contractWindow, setContractWindow] = useState(CONTRACT_WARNING_MONTHS);
  const [currentPage, setCurrentPage] = useState(1);
  const [quickFilter, setQuickFilter] = useState('');
  const [exporting, setExporting] = useState(false);

  const reportTypes: { id: string; label: string; icon: ElementType; to?: string }[] = [
    { id: 'employees', label: 'Employee Reports', icon: FileText, to: '/reports' },
    { id: 'attendance', label: 'Attendance Reports', icon: BarChart3, to: '/reports/attendance' },
    { id: 'leave', label: 'Leave Reports', icon: FileText },
    { id: 'appraisal', label: 'Appraisal Reports', icon: BarChart3 },
    { id: 'documentation', label: 'Documentation', icon: FileText },
  ];

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await apiClient.get('/reports/employees');
      const payload = (response.data as { success?: boolean; data?: ReportData }).data ?? response.data;
      setData(payload as ReportData);
    } catch (err: any) {
      setError(err?.response?.data?.message || err?.message || 'Failed to load report data');
      setData(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (activeTab === 'employees') {
      fetchData();
    }
  }, [activeTab, fetchData]);


  // Derived data ----------------------------------------------------------
  const records = data?.records ?? [];

  // "Near retirement" = within RETIREMENT_WINDOW_YEARS of the retirement age.
  // Employees already marked "retired" are excluded - they are outside active
  // retirement planning (HR asked not to fetch them here).
  const nearRetirement = useMemo(
    () => records.filter((e) => {
      const a = getAge(e.date_of_birth);
      if (a === null || a < RETIREMENT_AGE - RETIREMENT_WINDOW_YEARS) return false;
      return String(e.employee_status ?? '').toLowerCase() !== 'retired';
    }),
    [records]
  );

  const atRetirement = useMemo(
    () => nearRetirement.filter((e) => (getAge(e.date_of_birth) ?? 0) >= RETIREMENT_AGE),
    [nearRetirement]
  );

  const approachingRetirement = useMemo(
    () => nearRetirement.filter((e) => {
      const a = getAge(e.date_of_birth) ?? 0;
      return a >= RETIREMENT_AGE - RETIREMENT_WINDOW_YEARS && a < RETIREMENT_AGE;
    }),
    [nearRetirement]
  );

  const contractsNearExpiry = useMemo(() => {
    const cutoff = monthsFromNow(contractWindow);
    const next = cutoff.getTime();
    const today = new Date().setHours(0, 0, 0, 0);
    return records.filter((e) => {
      if (!e.contract_end_date) return false;
      const end = new Date(e.contract_end_date).getTime();
      if (Number.isNaN(end)) return false;
      return end >= today && end <= next;
    });
  }, [records, contractWindow]);

  const contractsExpired = useMemo(() => {
    const today = new Date().setHours(0, 0, 0, 0);
    return records.filter((e) => {
      if (!e.contract_end_date) return false;
      const end = new Date(e.contract_end_date).getTime();
      if (Number.isNaN(end)) return false;
      return end < today;
    });
  }, [records]);

  const avgAge = useMemo(() => {
    const ages = records
      .map((e) => getAge(e.date_of_birth))
      .filter((a): a is number => a !== null);
    if (ages.length === 0) return null;
    return Math.round((ages.reduce((s, a) => s + a, 0) / ages.length) * 10) / 10;
  }, [records]);

  // Filtered + paginated list for "All Employees" table.
  // A stat-card "quick filter" narrows the base set first; search / dept /
  // status / type filters are then applied on top.
  const filteredRecords = useMemo(() => {
    const s = search.trim().toLowerCase();
    let base = records;
    if (quickFilter === 'active') base = records.filter((e) => e.employee_status === 'active');
    else if (quickFilter === 'inactive') base = records.filter((e) => e.employee_status !== 'active');
    else if (quickFilter === 'nearRetirement') base = nearRetirement;
    else if (quickFilter === 'contractsExpiring') base = contractsNearExpiry;
    else if (quickFilter === 'contractsExpired') base = contractsExpired;

    return base.filter((e) => {
      const matchesSearch = s
        ? e.first_name.toLowerCase().includes(s) ||
          e.last_name.toLowerCase().includes(s) ||
          e.surname?.toLowerCase().includes(s) ||
          e.employee_id.toLowerCase().includes(s) ||
          e.email.toLowerCase().includes(s) ||
          e.national_id.toLowerCase().includes(s)
        : true;
      const matchesDept = deptFilter ? (e.department_name ?? 'Unassigned') === deptFilter : true;
      const matchesStatus = statusFilter ? e.employee_status === statusFilter : true;
      const matchesType = typeFilter ? e.employee_type === typeFilter : true;
      return matchesSearch && matchesDept && matchesStatus && matchesType;
    });
  }, [records, search, deptFilter, statusFilter, typeFilter, quickFilter, nearRetirement, contractsNearExpiry, contractsExpired]);

  const totalPages = Math.max(1, Math.ceil(filteredRecords.length / PER_PAGE));
  const paginatedRecords = useMemo(() => {
    const start = (currentPage - 1) * PER_PAGE;
    return filteredRecords.slice(start, start + PER_PAGE);
  }, [filteredRecords, currentPage]);

  const departments = Array.from(new Set(records.map((e) => e.department_name ?? 'Unassigned'))).sort();
  const statuses = Array.from(new Set(records.map((e) => e.employee_status))).sort();
  const empTypes = Array.from(new Set(records.map((e) => e.employee_type))).sort();

  // Reset to first page when filters change
  useEffect(() => {
    setCurrentPage(1);
  }, [search, deptFilter, statusFilter, typeFilter, quickFilter]);

  const applyStatFilter = (key: string) => {
    setQuickFilter((prev) => (prev === key ? '' : key));
    setCurrentPage(1);
  };

  const handleExport = async (format: string) => {
    if (activeTab !== 'employees') return;
    setExporting(true);
    try {
      const response = await apiClient.get(`/reports/${activeTab}/export/${format}`, {
        responseType: 'blob',
      });
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `${activeTab}-report.${format}`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (error) {
      console.error('Failed to export report:', error);
    } finally {
      setExporting(false);
    }
  };

  const handleCsvExport = () => {
    const headers = ['Employee ID', 'Name', 'Email', 'Phone', 'Department', 'Section', 'Designation', 'Employment Type', 'Status', 'DOB', 'Contract Start', 'Contract End', 'Hire Date'];
    const rows = filteredRecords.map((e) => ({
      'Employee ID': e.employee_id,
      Name: `${e.first_name} ${e.last_name}${e.surname ? ' ' + e.surname : ''}`,
      Email: e.email,
      Phone: e.phone,
      Department: e.department_name ?? 'Unassigned',
      Section: e.section_name ?? '-',
      Designation: e.designation,
      'Employment Type': e.employee_type,
      Status: e.employee_status,
      DOB: formatISODate(e.date_of_birth),
      'Contract Start': formatISODate(e.contract_start_date),
      'Contract End': formatISODate(e.contract_end_date),
      'Hire Date': formatISODate(e.hire_date),
    }));
    const csv = toCsv(headers, rows);
    downloadCsv(csv, csvFilenameWithDate(`${activeTab}-employees`));
    };

  // -----------------------------------------------------------------------


  if (loading) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Reports</h1>
          <p className="text-gray-500">Generate and view system reports</p>
        </div>
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-8 w-8 animate-spin text-gray-400" />
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Reports</h1>
          <p className="text-gray-500">Generate and view system reports</p>
        </div>
        <Card>
          <div className="text-center py-8">
            <p className="text-red-500 mb-3">{error}</p>
            <Button onClick={fetchData} variant="outline">
              <RefreshCw className="h-4 w-4 mr-2" />
              Retry
            </Button>
          </div>
        </Card>
      </div>
    );
  }

  // Column formatters
  const employeeName = (e: EmployeeRecord) =>
    `${e.first_name} ${e.last_name}${e.surname ? ' ' + e.surname : ''}`;

  const daysRemaining = (e: EmployeeRecord): number | null => {
    if (!e.contract_end_date) return null;
    const end = new Date(e.contract_end_date);
    if (Number.isNaN(end.getTime())) return null;
    const diff = end.setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0);
    return Math.ceil(diff / (1000 * 60 * 60 * 24));
  };

  const contractBadge = (e: EmployeeRecord) => {
    const d = daysRemaining(e);
    if (d === null) return <span className="text-gray-400">—</span>;
    if (d < 0) return <Badge variant="danger">Expired</Badge>;
    if (d <= 30) return <Badge variant="danger">{d} days left</Badge>;
    if (d <= 90) return <Badge variant="warning">{d} days left</Badge>;
    return <span className="text-gray-500">{d} days left</span>;
  };

  const statusBadge = (status: string) => {
    const variant = status === 'active' ? 'success' : status === 'inactive' ? 'danger' : 'default';
    return <Badge variant={variant}>{status}</Badge>;
  };

  // Columns
  const nearRetirementColumns = [
    { key: 'employee', label: 'Employee', render: (_v: any, e: EmployeeRecord) => <span>{employeeName(e)} <span className="text-gray-400">({e.employee_id})</span></span> },
    { key: 'department_name', label: 'Department' },
    { key: 'designation', label: 'Designation' },
    { key: 'date_of_birth', label: 'DOB', render: (v: any) => formatISODate(v) },
    { key: 'age', label: 'Age', render: (_v: any, e: EmployeeRecord) => { const a = getAge(e.date_of_birth); return a !== null ? a : '-'; } },
    { key: 'employee_type', label: 'Type' },
    { key: 'employee_status', label: 'Status', render: (v: any) => statusBadge(v) },
    { key: 'contract_end_date', label: 'Contract End', render: (v: any) => formatISODate(v) },
    {
      key: 'years_to_60',
      label: 'Years to 60',
      render: (_v: any, e: EmployeeRecord) => {
        const a = getAge(e.date_of_birth);
        return a === null ? '-' : String(Math.max(RETIREMENT_AGE - a, 0));
      },
    },
  ];

  const contractColumns = [
    { key: 'employee', label: 'Employee', render: (_v: any, e: EmployeeRecord) => <span>{employeeName(e)} <span className="text-gray-400">({e.employee_id})</span></span> },
    { key: 'department_name', label: 'Department' },
    { key: 'employee_type', label: 'Employment Type', render: (v: any) => v ?? '-' },
    { key: 'contract_start_date', label: 'Contract Start', render: (v: any) => formatISODate(v) },
    { key: 'contract_end_date', label: 'Contract End', render: (v: any) => formatISODate(v) },
    { key: 'days_remaining', label: 'Days Remaining', render: (_v: any, e: EmployeeRecord) => contractBadge(e) },
  ];

  const allEmployeesColumns = [
    { key: 'employee_id', label: 'Emp. ID' },
    {
      key: 'id',
      label: 'Name',
      render: (_v: any, e: EmployeeRecord) => (
        <a href={`/employees/${e.id}/profile`} className="font-medium text-gray-900 dark:text-white hover:underline">
          {employeeName(e)}
        </a>
      ),
    },
    { key: 'email', label: 'Email' },
    { key: 'phone', label: 'Phone' },
    { key: 'department_name', label: 'Department', render: (v: any) => v ?? 'Unassigned' },
    { key: 'designation', label: 'Designation', render: (v: any) => v ?? '-' },
    { key: 'employee_type', label: 'Employment Type' },
    { key: 'employee_status', label: 'Status', render: (v: any) => statusBadge(v) },
    { key: 'date_of_birth', label: 'DOB', render: (v: any) => formatISODate(v) },
    { key: 'contract_end_date', label: 'Contract End', render: (v: any) => formatISODate(v) },
    { key: 'created_at', label: 'Created', render: (v: any) => formatISODate(v) },
  ];

  // Employees tab content
  const renderEmployeeTab = () => {
    if (!data) return null;
    const { summary } = data;

    const StatGrid = () => (
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
        <StatCard
          title="Total Employees"
          value={summary.total}
          icon={Users}
          onClick={() => applyStatFilter('')}
          selected={quickFilter === ''}
        />
        <StatCard
          title="Active"
          value={summary.active}
          icon={UserCheck}
          variant="default"
          onClick={() => applyStatFilter('active')}
          selected={quickFilter === 'active'}
        />
        <StatCard
          title="Inactive"
          value={summary.inactive}
          icon={UserX}
          variant="danger"
          onClick={() => applyStatFilter('inactive')}
          selected={quickFilter === 'inactive'}
        />
        <StatCard
          title={`Near Retirement (≤ ${RETIREMENT_WINDOW_YEARS} yrs)`}
          value={nearRetirement.length}
          icon={BarChart3}
          variant="warning"
          subtitle={`within 5 years of ${RETIREMENT_AGE} (excl. retired)`}
          onClick={() => applyStatFilter('nearRetirement')}
          selected={quickFilter === 'nearRetirement'}
        />
        <StatCard
          title="Contracts Near Expiry"
          value={contractsNearExpiry.length}
          icon={CalendarDays}
          variant="danger"
          subtitle={`Within ${contractWindow} months`}
          onClick={() => applyStatFilter('contractsExpiring')}
          selected={quickFilter === 'contractsExpiring'}
        />
        <StatCard
          title="Expired Contracts"
          value={contractsExpired.length}
          icon={CalendarDays}
          variant="danger"
          onClick={() => applyStatFilter('contractsExpired')}
          selected={quickFilter === 'contractsExpired'}
        />
        <StatCard
          title="Avg. Age"
          value={avgAge !== null ? `${avgAge}` : '-'}
          icon={Users}
          subtitle="Years"
        />
      </div>
    );

    return (
      <div className="space-y-6">
        <StatGrid />

        {/* Summary + breakdowns */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <Card title="Summary">
            <div className="space-y-3 text-sm">
              <div className="flex justify-between"><span className="text-gray-500">Total Employees</span><span className="font-medium">{summary.total}</span></div>
              <div className="flex justify-between"><span className="text-gray-500">Active</span><span className="font-medium text-green-600">{summary.active}</span></div>
              <div className="flex justify-between"><span className="text-gray-500">Inactive</span><span className="font-medium text-red-600">{summary.inactive}</span></div>
              <div className="flex justify-between"><span className="text-gray-500">Avg. Age</span><span className="font-medium">{avgAge !== null ? `${avgAge} yrs` : '-'}</span></div>
            </div>
          </Card>

          <Card title="By Department">
            <div className="space-y-2 max-h-60 overflow-y-auto">
              {Object.entries(summary.by_department).map(([dept, count]) => (
                <div key={dept} className="flex justify-between text-sm">
                  <span className="text-gray-500">{dept}</span>
                  <span className="font-medium">{count}</span>
                </div>
              ))}
            </div>
          </Card>

          <Card title="By Employment Type">
            <div className="space-y-2 max-h-60 overflow-y-auto">
              {Object.entries(summary.by_employment_type).map(([type, count]) => (
                <div key={type} className="flex justify-between text-sm">
                  <span className="text-gray-500 capitalize">{type || 'unspecified'}</span>
                  <span className="font-medium">{count}</span>
                </div>
              ))}
            </div>
          </Card>
        </div>

        {/* Near Retirement */}
        <Card title={`Near Retirement (within ${RETIREMENT_WINDOW_YEARS} years of age ${RETIREMENT_AGE})`}>
          <p className="text-sm text-gray-500 mb-3">
            {nearRetirement.length} employee(s) are within {RETIREMENT_WINDOW_YEARS} years of retirement (retired employees excluded).
            {atRetirement.length > 0 && ` ${atRetirement.length} at/over ${RETIREMENT_AGE}.`}
            {approachingRetirement.length > 0 && ` ${approachingRetirement.length} between ${RETIREMENT_AGE - RETIREMENT_WINDOW_YEARS}–${RETIREMENT_AGE - 1}.`}
          </p>
          {nearRetirement.length === 0 ? (
            <p className="text-gray-400">No employees within {RETIREMENT_WINDOW_YEARS} years of retirement.</p>
          ) : (
            <Table columns={nearRetirementColumns} data={nearRetirement} />
          )}
        </Card>

        {/* Contracts near expiry */}
        <Card title="Contracts Near Expiry">
          <div className="flex items-center gap-3 mb-3">
            <label className="text-sm text-gray-600">Look-ahead window:</label>
            <Select
              value={String(contractWindow)}
              onChange={(e) => setContractWindow(Number(e.target.value))}
              options={[
                { value: '3', label: '3 months' },
                { value: '6', label: '6 months' },
                { value: '12', label: '12 months' },
              ]}
            />
          </div>
          <p className="text-sm text-gray-500 mb-3">
            {contractsNearExpiry.length} contract(s) expiring within {contractWindow} months.
            {contractsExpired.length > 0 && ` ${contractsExpired.length} already expired.`}
          </p>
          {contractsNearExpiry.length === 0 ? (
            <p className="text-gray-400">No expiring contracts in the selected window.</p>
          ) : (
            <Table columns={contractColumns} data={contractsNearExpiry} />
          )}
        </Card>

        {/* All Employees with filters */}
        <Card title="All Employees">
          <div className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                <Input
                  type="text"
                  placeholder="Search (name, ID, email)..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="pl-10"
                />
              </div>
              <Select
                value={deptFilter}
                onChange={(e) => setDeptFilter(e.target.value)}
                options={departments.map((d) => ({ value: d, label: d }))}
              />
              <Select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                options={statuses.map((s) => ({ value: s, label: s }))}
              />
              <Select
                value={typeFilter}
                onChange={(e) => setTypeFilter(e.target.value)}
                options={empTypes.map((t) => ({ value: t, label: t }))}
              />
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  onClick={() => { setSearch(''); setDeptFilter(''); setStatusFilter(''); setTypeFilter(''); setQuickFilter(''); }}
                  className="flex-1"
                >
                  Clear
                </Button>
                <Button variant="outline" onClick={handleCsvExport} className="flex-1">
                  <Download className="h-4 w-4 mr-1" /> CSV
                </Button>
              </div>
            </div>

            {/* Active stat-card quick-filter indicator */}
            {quickFilter && (
              <div className="flex items-center gap-2 text-sm">
                <span className="text-gray-500">Filtered by:</span>
                <button
                  type="button"
                  onClick={() => applyStatFilter(quickFilter)}
                  title="Click to remove this filter"
                  className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 border border-primary-200 dark:border-primary-800 hover:bg-primary-100"
                >
                  {QUICK_FILTER_LABELS[quickFilter] ?? quickFilter}
                  <X className="h-3 w-3" />
                </button>
                {filteredRecords.length > 0 && (
                  <span className="text-gray-400">{filteredRecords.length} match(es)</span>
                )}
              </div>
            )}

            <Table columns={allEmployeesColumns} data={paginatedRecords} />

            {/* Pagination */}
            {filteredRecords.length > PER_PAGE && (
              <div className="flex items-center justify-between pt-3 border-t">
                <p className="text-sm text-gray-500">
                  Showing {Math.min((currentPage - 1) * PER_PAGE + 1, filteredRecords.length)}–{Math.min(currentPage * PER_PAGE, filteredRecords.length)} of {filteredRecords.length}
                </p>
                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCurrentPage((p: number) => Math.max(1, p - 1))}
                    disabled={currentPage === 1}
                  >
                    <ChevronLeft className="h-4 w-4" />
                  </Button>
                  <span className="text-sm">Page {currentPage} of {totalPages}</span>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCurrentPage((p: number) => Math.min(totalPages, p + 1))}
                    disabled={currentPage === totalPages}
                  >
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </div>
        </Card>

        {/* Export (server) */}
        <div className="flex gap-2 justify-end">
          <Button variant="outline" onClick={() => handleExport('csv')} disabled={exporting}>
            <Download className="h-4 w-4 mr-2" /> {exporting ? 'Exporting...' : 'Export CSV (Server)'}
          </Button>
          <Button variant="outline" onClick={() => handleExport('pdf')} disabled={exporting}>
            <Download className="h-4 w-4 mr-2" /> Export PDF
          </Button>
          <Button variant="outline" onClick={() => handleExport('excel')} disabled={exporting}>
            <Download className="h-4 w-4 mr-2" /> Export Excel
          </Button>
        </div>
      </div>
    );
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Reports</h1>
          <p className="text-gray-500">Generate and view system reports</p>
        </div>
        {activeTab === 'employees' && data && (
          <Button variant="outline" size="sm" onClick={fetchData}>
            <RefreshCw className="h-4 w-4 mr-2" /> Refresh
          </Button>
        )}
      </div>

      {/* Report Type Tabs - employees/attendance are real routes */}
      <div className="flex space-x-2 border-b border-gray-200 dark:border-gray-700 overflow-x-auto">
        {reportTypes.map((tab) => {
          if (tab.to) {
            return (
              <Link
                key={tab.id}
                to={tab.to}
                className={`flex items-center px-4 py-2 text-sm font-medium border-b-2 whitespace-nowrap transition-colors ${
                  tab.id === 'employees' && activeTab === 'employees'
                    ? 'border-primary-600 text-primary-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700'
                }`}>
                <tab.icon className="h-4 w-4 mr-2" />
                {tab.label}
              </Link>
            );
          }
          return (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex items-center px-4 py-2 text-sm font-medium border-b-2 whitespace-nowrap transition-colors ${
                activeTab === tab.id
                  ? 'border-primary-600 text-primary-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}>
              <tab.icon className="h-4 w-4 mr-2" />
              {tab.label}
            </button>
          );
        })}
      </div>

      {activeTab === 'employees' ? (
        renderEmployeeTab()
      ) : (
        <Card title={reportTypes.find((t) => t.id === activeTab)?.label || 'Reports'}>
          <div className="space-y-4">
            <p className="text-gray-600">Generate reports for {activeTab} module.</p>
            <div className="flex space-x-3">
              <Button variant="outline" onClick={() => handleExport('pdf')}>
                <Download className="h-4 w-4 mr-2" /> Export PDF
              </Button>
              <Button variant="outline" onClick={() => handleExport('csv')}>
                <Download className="h-4 w-4 mr-2" /> Export CSV
              </Button>
              <Button variant="outline" onClick={() => handleExport('excel')}>
                <Download className="h-4 w-4 mr-2" /> Export Excel
              </Button>
            </div>
          </div>
        </Card>
      )}
    </div>
  );
};

export default Reports;
