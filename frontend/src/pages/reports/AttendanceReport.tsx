import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  ResponsiveContainer, AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, Legend,
} from 'recharts'
import toast from 'react-hot-toast'
import {
  CalendarCheck, Users, UserX, CalendarDays, Timer, AlertTriangle, LogOut,
  Search, Download, RefreshCw, Loader2, ChevronLeft, ChevronRight, X, Eye,
  FileText, Clock, Sparkles, Printer, Gauge,
} from 'lucide-react'
import type { ElementType } from 'react'
import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Badge from '../../components/ui/Badge'
import attendanceReportService from '../../api/services/attendanceReportService'

// ---- Types -----------------------------------------------------------------
type Filters = {
  from: string
  to: string
  department_id: string
  office_id: string
  employee_type: string
  status: string
  search: string
}

type Summary = {
  start_date: string
  end_date: string
  grouping: string
  range_days: number
  holidays_in_range: number
  attendance_records: number
  employees_with_records: number
  employees_on_leave: number
  late_arrivals: number
  auto_clockouts: number
  missing_clockouts: number
  total_hours: number
  avg_hours_per_day: number
  avg_hours_per_employee: number
  expected_working_days: number
  present_days: number
  leave_days: number
  absent_days: number
  compliance_rate: number | null
}

type TrendPoint = {
  label: string
  present: number
  late: number
  missing: number
  auto: number
  on_leave: number
  absent: number
  hours: number
}

type EmployeeRow = {
  employee_id: number
  emp_no: string
  name: string
  department: string
  office: string
  expected_days: number
  days_present: number
  absent_days: number
  leave_days: number
  late_days: number
  auto_days: number
  missing_out: number
  total_hours: number
  avg_hours: number
  attendance_rate: number | null
}

type DayRecord = {
  id: number
  attendance_date: string
  clock_in: string | null
  clock_out: string | null
  hours: number | null
  is_late: boolean
  auto_clocked_out: boolean
  status_label: string
}

type Options = {
  departments: { id: number; name: string }[]
  offices: { id: number; name: string }[]
  employee_types: { id: number; name: string }[]
  statuses: string[]
}

// ---- Constants -------------------------------------------------------------
const PER_PAGE = 10

const STATUS_LABELS: Record<string, string> = {
  present: 'Present',
  late: 'Late',
  missing: 'Missing Clock-Out',
  auto: 'Auto Clock-Out',
  absent: 'Absent',
  on_leave: 'On Leave',
}

const STATUS_COLORS: Record<string, string> = {
  present: '#10b981',
  late: '#f59e0b',
  missing: '#ef4444',
  auto: '#6366f1',
  on_leave: '#3b82f6',
  absent: '#94a3b8',
}

/** Status lenses the employee table supports (the clickable cards). */
const FILTERABLE_STATUSES = ['present', 'late', 'missing', 'auto', 'absent', 'on_leave']

const QUICK_PRESETS = [
  { key: 'today', label: 'Today' },
  { key: 'yesterday', label: 'Yesterday' },
  { key: 'week', label: 'This Week' },
  { key: 'last_week', label: 'Last Week' },
  { key: 'month', label: 'This Month' },
  { key: 'last_month', label: 'Last Month' },
  { key: 'quarter', label: 'This Quarter' },
  { key: 'year', label: 'This Year' },
  { key: 'fy', label: 'Financial Year' },
]

const SORTABLE_COLUMNS: { key: string; label: string }[] = [
  { key: 'emp_no', label: 'Emp No' },
  { key: 'name', label: 'Employee' },
  { key: 'department', label: 'Department' },
  { key: 'office', label: 'Office' },
  { key: 'expected_days', label: 'Expected' },
  { key: 'days_present', label: 'Present' },
  { key: 'absent_days', label: 'Absent' },
  { key: 'leave_days', label: 'On Leave' },
  { key: 'late_days', label: 'Late' },
  { key: 'auto_days', label: 'Auto Out' },
  { key: 'missing_out', label: 'Missing Out' },
  { key: 'total_hours', label: 'Total Hours' },
  { key: 'avg_hours', label: 'Avg Hrs' },
  { key: 'attendance_rate', label: 'Rate %' },
]

// ---- Helpers ---------------------------------------------------------------
const toISODate = (d: Date): string => {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

const today = (): string => toISODate(new Date())

/** Financial year period (July -> June, matching the MUWASCO FY calendar). */
const currentFinancialYear = (): { from: string; to: string } => {
  const now = new Date()
  const year = now.getFullYear()
  const startYear = now.getMonth() >= 6 ? year : year - 1
  return { from: `${startYear}-07-01`, to: `${startYear + 1}-06-30` }
}

/** Resolve a quick period preset to a {from,to} date range. */
const quickRange = (key: string): { from: string; to: string } => {
  const now = new Date()
  const startOf = (y: number, m: number) => toISODate(new Date(y, m, 1))
  const endOf = (y: number, m: number) => toISODate(new Date(y, m + 1, 0))
  const mondayThisWeek = (d: Date): Date => {
    const day = (d.getDay() + 6) % 7 // Monday-first
    return new Date(d.getFullYear(), d.getMonth(), d.getDate() - day)
  }
  switch (key) {
    case 'today':
      return { from: today(), to: today() }
    case 'yesterday': {
      const y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1)
      return { from: toISODate(y), to: toISODate(y) }
    }
    case 'week':
      return { from: toISODate(mondayThisWeek(now)), to: today() }
    case 'last_week': {
      const thisMonday = mondayThisWeek(now)
      const lastMonday = new Date(thisMonday.getFullYear(), thisMonday.getMonth(), thisMonday.getDate() - 7)
      const lastSunday = new Date(lastMonday.getFullYear(), lastMonday.getMonth(), lastMonday.getDate() + 6)
      return { from: toISODate(lastMonday), to: toISODate(lastSunday) }
    }
    case 'month':
      return { from: startOf(now.getFullYear(), now.getMonth()), to: today() }
    case 'last_month':
      return {
        from: startOf(now.getFullYear(), now.getMonth() - 1),
        to: endOf(now.getFullYear(), now.getMonth() - 1),
      }
    case 'quarter': {
      const q = Math.floor(now.getMonth() / 3)
      return { from: startOf(now.getFullYear(), q * 3), to: today() }
    }
    case 'year':
      return { from: startOf(now.getFullYear(), 0), to: today() }
    case 'fy':
      return currentFinancialYear()
    default:
      return { from: startOf(now.getFullYear(), now.getMonth()), to: today() }
  }
}

const defaultFilters = (): Filters => ({
  from: quickRange('month').from,
  to: today(),
  department_id: '',
  office_id: '',
  employee_type: '',
  status: '',
  search: '',
})

const fmtDateTime = (value: string | null): string => {
  if (!value) return '-'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })
}

const fmtDate = (value: string | null): string => {
  if (!value) return '-'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleDateString()
}

// ---- Stat card (interactive) -----------------------------------------------
type StatCardProps = {
  title: string
  value: string | number
  icon: ElementType
  subtitle?: string
  variant?: 'default' | 'success' | 'warning' | 'danger' | 'info'
  onClick?: () => void
  selected?: boolean
}

const StatCard = ({ title, value, icon: Icon, subtitle, variant = 'default', onClick, selected = false }: StatCardProps) => {
  const accent = {
    default: 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
    success: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    warning: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    danger: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    info: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
  }[variant]

  const interactive = typeof onClick === 'function'
  const selectedRing = selected
    ? ' ring-2 ring-primary-500 border-primary-400 dark:border-primary-500'
    : ''

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={!interactive}
      title={interactive ? `Filter the report by ${title}` : undefined}
      data-testid={`stat-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`}
      className={`text-left card p-4 transition-shadow${selectedRing} ${
        interactive
          ? 'cursor-pointer hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400'
          : 'cursor-default'
      }`}
    >
      <div className="flex items-start justify-between">
        <div className="min-w-0">
          <p className="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">{title}</p>
          <p className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{value}</p>
          {subtitle && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}
        </div>
        <div className={`h-10 w-10 rounded-lg flex items-center justify-center shrink-0 ${accent}`}>
          <Icon className="h-5 w-5" />
        </div>
      </div>
    </button>
  )
}

// ---- Filter panel ----------------------------------------------------------
const FilterPanel = ({
  filters, options, onChange, onReset, onQuick, activeCount,
}: {
  filters: Filters
  options: Options | null
  onChange: (patch: Partial<Filters>) => void
  onReset: () => void
  onQuick: (key: string) => void
  activeCount: number
}) => (
  <Card className="p-4">
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      <div>
        <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From Date</label>
        <input type="date" value={filters.from} onChange={(e) => onChange({ from: e.target.value })} className="input" />
      </div>
      <div>
        <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To Date</label>
        <input type="date" value={filters.to} onChange={(e) => onChange({ to: e.target.value })} className="input" />
      </div>
      <div>
        <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Department</label>
        <select value={filters.department_id} onChange={(e) => onChange({ department_id: e.target.value })} className="input">
          <option value="">All Departments</option>
          {(options?.departments || []).map((d) => (
            <option key={d.id} value={d.id}>{d.name}</option>
          ))}
        </select>
      </div>
      <div>
        <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Office</label>
        <select value={filters.office_id} onChange={(e) => onChange({ office_id: e.target.value })} className="input">
          <option value="">All Offices</option>
          {(options?.offices || []).map((o) => (
            <option key={o.id} value={o.id}>{o.name}</option>
          ))}
        </select>
      </div>
      <div>
        <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Employee Type</label>
        <select value={filters.employee_type} onChange={(e) => onChange({ employee_type: e.target.value })} className="input">
          <option value="">All Types</option>
          {(options?.employee_types || []).map((t) => (
            <option key={t.id} value={t.id}>{t.name}</option>
          ))}
        </select>
      </div>
      <div>
        <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Attendance Status</label>
        <select value={filters.status} onChange={(e) => onChange({ status: e.target.value })} className="input">
          <option value="">All Statuses</option>
          {FILTERABLE_STATUSES.map((s) => (
            <option key={s} value={s}>{STATUS_LABELS[s] || s}</option>
          ))}
        </select>
      </div>
      <div>
        <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Search Employee</label>
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <input
            type="text"
            value={filters.search}
            onChange={(e) => onChange({ search: e.target.value })}
            placeholder="Name or emp no."
            className="input pl-9"
          />
        </div>
      </div>
      <div className="flex items-end">
        <Button variant="outline" onClick={onReset} disabled={activeCount === 0} className="w-full">
          <X className="h-4 w-4 mr-1" /> Reset Filters
        </Button>
      </div>
    </div>

    {/* Quick presets */}
    <div className="flex flex-wrap items-center gap-2 mt-4">
      <span className="text-xs font-medium text-gray-500 dark:text-gray-400">Quick:</span>
      {QUICK_PRESETS.map((p) => (
        <button
          key={p.key}
          type="button"
          onClick={() => onQuick(p.key)}
          className="px-3 py-1 text-xs rounded-full border border-gray-200 dark:border-slate-600 text-gray-600 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-slate-700 transition-colors"
        >
          {p.label}
        </button>
      ))}
    </div>
  </Card>
)

// ---- Drill-down drawer -----------------------------------------------------
const DrillDownDrawer = ({
  open, employeeId, filters, onClose,
}: {
  open: boolean
  employeeId: number | null
  filters: Filters
  onClose: () => void
}) => {
  const [loading, setLoading] = useState(false)
  const [data, setData] = useState<any>(null)
  const [error, setError] = useState('')
  const [page, setPage] = useState(1)
  const [retryKey, setRetryKey] = useState(0)

  useEffect(() => {
    setPage(1)
  }, [employeeId, open])

  useEffect(() => {
    if (!open || !employeeId) return
    let cancelled = false
    setLoading(true)
    setError('')
    attendanceReportService
      .records({
        from: filters.from,
        to: filters.to,
        department_id: filters.department_id || undefined,
        office_id: filters.office_id || undefined,
        employee_type: filters.employee_type || undefined,
        status: filters.status || undefined,
        employee_id: employeeId,
        page,
        per_page: 15,
      })
      .then((res) => {
        if (!cancelled) setData(res)
      })
      .catch((err: any) => {
        if (!cancelled) setError(err?.response?.data?.message || 'Failed to load attendance details.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [open, employeeId, page, retryKey, filters.from, filters.to, filters.department_id, filters.office_id, filters.employee_type, filters.status])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50" role="dialog" aria-modal="true">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="absolute right-0 top-0 h-full w-full max-w-xl bg-white dark:bg-slate-800 shadow-xl overflow-y-auto border-l dark:border-slate-700">
        <div className="sticky top-0 bg-white dark:bg-slate-800 border-b dark:border-slate-700 px-6 py-4 flex items-start justify-between z-10">
          <div>
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
              {data?.employee?.name || 'Employee Attendance Details'}
            </h3>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {data?.employee ? `${data.employee.emp_no} · ${data.employee.department}` : ''}
              {data ? ` · ${data.total} record(s) in range` : ''}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close details"
            className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="p-6">
          {loading ? (
            <div className="flex justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-gray-400" />
            </div>
          ) : error ? (
            <div className="py-12 text-center">
              <p className="text-sm text-red-600 dark:text-red-400">{error}</p>
              <Button variant="outline" size="sm" className="mt-3" onClick={() => setRetryKey((k) => k + 1)}>
                <RefreshCw className="h-4 w-4 mr-1" /> Retry
              </Button>
            </div>
          ) : !data || data.items.length === 0 ? (
            <DrawerEmpty />
          ) : (
            <DrawerTable data={data} setPage={setPage} />
          )}
        </div>
      </div>
    </div>
  )
}

const DrawerEmpty = () => (
  <div className="py-12 text-center">
    <CalendarCheck className="h-8 w-8 text-gray-300 mx-auto mb-2" />
    <p className="text-gray-500 dark:text-gray-400 font-medium">No attendance records found</p>
    <p className="text-sm text-gray-400 mt-1">
      The employee has no clock-in records for the selected period and filters.
    </p>
  </div>
)

const DrawerTable = ({ data, setPage }: { data: any; setPage: (fn: (p: number) => number) => void }) => (
  <>
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
        <thead className="bg-gray-50 dark:bg-slate-900">
          <tr>
            {['Date', 'Clock In', 'Clock Out', 'Hours', 'Status'].map((h) => (
              <th key={h} className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
          {data.items.map((r: DayRecord) => (
            <tr key={r.id}>
              <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{fmtDate(r.attendance_date)}</td>
              <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{fmtDateTime(r.clock_in)}</td>
              <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{fmtDateTime(r.clock_out)}</td>
              <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{r.hours !== null ? `${r.hours}h` : '-'}</td>
              <td className="px-3 py-2 whitespace-nowrap text-sm">
                <Badge
                  variant={
                    r.status_label === 'Late' ? 'warning'
                      : r.status_label === 'Missing Clock-Out' ? 'danger'
                        : r.status_label === 'Auto Clock-Out' ? 'default'
                          : 'success'
                  }
                >
                  {r.status_label}
                </Badge>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    {data.total > 15 && (
      <div className="flex items-center justify-between mt-4">
        <p className="text-sm text-gray-500">Page {data.page} of {data.last_page} · {data.total} records</p>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" disabled={data.page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <Button variant="outline" size="sm" disabled={data.page >= data.last_page} onClick={() => setPage((p) => Math.min(data.last_page, p + 1))}>
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      </div>
    )}
  </>
)

// ---- Main component --------------------------------------------------------
const AttendanceReport = () => {
  const [options, setOptions] = useState<Options | null>(null)
  const [filters, setFilters] = useState<Filters>(defaultFilters)

  const [summary, setSummary] = useState<Summary | null>(null)
  const [trends, setTrends] = useState<{ grouping: string; points: TrendPoint[] } | null>(null)
  const [byStatus, setByStatus] = useState<{ status: string; count: number }[]>([])
  const [departments, setDepartments] = useState<any[]>([])
  const [lateData, setLateData] = useState<any>(null)
  const [hours, setHours] = useState<any>(null)
  const [compliance, setCompliance] = useState<any>(null)
  const [insights, setInsights] = useState<string[]>([])

  const [employees, setEmployees] = useState<any>(null)
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState('days_present')
  const [dir, setDir] = useState<'asc' | 'desc'>('desc')

  const [loading, setLoading] = useState(true)
  const [loadingEmployees, setLoadingEmployees] = useState(true)
  const [exporting, setExporting] = useState(false)
  const [error, setError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)

  const [drawerEmployee, setDrawerEmployee] = useState<number | null>(null)

  // Ref of the latest filters for stable callbacks.
  const appliedRef = useRef(filters)
  appliedRef.current = filters

  /** Build the shared query params from the current filters. */
  const queryParams = useCallback((f: Filters): Record<string, any> => ({
    from: f.from,
    to: f.to,
    department_id: f.department_id || undefined,
    office_id: f.office_id || undefined,
    employee_type: f.employee_type || undefined,
    status: f.status || undefined,
  }), [])

  const activeFilterCount = useMemo(() => {
    const d = defaultFilters()
    let n = 0
    if (filters.from !== d.from) n++
    if (filters.to !== d.to) n++
    if (filters.department_id) n++
    if (filters.office_id) n++
    if (filters.employee_type) n++
    if (filters.status) n++
    if (filters.search) n++
    return n
  }, [filters])

  // Filter dropdown options (loaded once; non-fatal when unavailable).
  useEffect(() => {
    attendanceReportService.options()
      .then(setOptions)
      .catch(() => { /* selects simply stay empty */ })
  }, [])

  // Core analytics: refetched whenever any filter changes. Search is
  // intentionally excluded - it only narrows the employee detail table.
  const { from, to, department_id, office_id, employee_type, status, search } = filters
  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    const params = { from, to, department_id: department_id || undefined, office_id: office_id || undefined, employee_type: employee_type || undefined, status: status || undefined }

    Promise.all([
      attendanceReportService.summary(params),
      attendanceReportService.trends(params),
      attendanceReportService.byStatus(params),
      attendanceReportService.byDepartment(params),
      attendanceReportService.lateArrivals(params),
      attendanceReportService.workingHours(params),
      attendanceReportService.compliance(params),
      attendanceReportService.insights(params),
    ])
      .then(([sum, trd, st, dep, late, hrs, comp, ins]) => {
        if (cancelled) return
        setSummary(sum)
        setTrends(trd)
        setByStatus(st || [])
        setDepartments(dep || [])
        setLateData(late)
        setHours(hrs)
        setCompliance(comp)
        setInsights(ins?.insights || [])
      })
      .catch((err: any) => {
        if (!cancelled) setError(err?.response?.data?.message || 'Failed to load the attendance report. Please try again.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [from, to, department_id, office_id, employee_type, status, reloadKey])

  // Paginated employee table (server-side search, sort and paging).
  useEffect(() => {
    let cancelled = false
    setLoadingEmployees(true)
    attendanceReportService.employees({
      ...queryParams(appliedRef.current),
      search: appliedRef.current.search || undefined,
      page,
      per_page: PER_PAGE,
      sort,
      dir,
    })
      .then((res) => {
        if (!cancelled) setEmployees(res)
      })
      .catch(() => {
        if (!cancelled) setEmployees({ items: [], total: 0, page: 1, last_page: 1 })
      })
      .finally(() => {
        if (!cancelled) setLoadingEmployees(false)
      })
    return () => {
      cancelled = true
    }
  }, [from, to, department_id, office_id, employee_type, status, search, page, sort, dir, queryParams])

  // Back to page 1 whenever the lens or search changes.
  useEffect(() => {
    setPage(1)
  }, [from, to, department_id, office_id, employee_type, status, search])

  const applyFilters = useCallback((patch: Partial<Filters>) => {
    setFilters((prev) => ({ ...prev, ...patch }))
  }, [])

  const onQuick = useCallback((key: string) => {
    const r = quickRange(key)
    setFilters((prev) => ({ ...prev, from: r.from, to: r.to }))
  }, [])

  const resetFilters = useCallback(() => {
    setFilters(defaultFilters())
  }, [])

  /** Stat-card click: toggle the matching status lens (server-side filter). */
  const toggleStatus = useCallback((value: string) => {
    setFilters((prev) => ({ ...prev, status: prev.status === value ? '' : value }))
  }, [])

  const handleSort = useCallback((key: string) => {
    setSort((prevSort) => {
      if (prevSort === key) {
        setDir((d) => (d === 'asc' ? 'desc' : 'asc'))
        return prevSort
      }
      setDir(key === 'emp_no' || key === 'name' || key === 'department' || key === 'office' ? 'asc' : 'desc')
      return key
    })
  }, [])

  const handleExport = useCallback(async () => {
    setExporting(true)
    try {
      const blob = await attendanceReportService.exportCsv(queryParams(appliedRef.current))
      const url = window.URL.createObjectURL(new Blob([blob], { type: 'text/csv' }))
      const link = document.createElement('a')
      link.href = url
      link.download = `attendance_report_${today()}.csv`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
      toast.success('Attendance report exported')
    } catch {
      toast.error('Failed to export the report. Please try again.')
    } finally {
      setExporting(false)
    }
  }, [queryParams])

  const reportPeriodLabel = filters.from === filters.to
    ? fmtDate(filters.from)
    : `${fmtDate(filters.from)} – ${fmtDate(filters.to)}`

  const statusData = byStatus
    .map((s) => ({ name: STATUS_LABELS[s.status] || s.status, value: Number(s.count) || 0 }))
    .filter((s) => s.value > 0)

  const trendData = (trends?.points || []).map((p) => ({
    label: p.label,
    Present: p.present,
    Absent: p.absent,
    'On Leave': p.on_leave,
  }))

  const deptChartData = departments.slice(0, 10).map((d) => ({
    name: d.department,
    Present: d.present,
    Absent: d.absent,
  }))

  const hourTrend = (hours?.trend || []).map((t: any) => ({
    label: t.label,
    Hours: Number(t.hours) || 0,
    'Avg Hours': Number(t.avg_hours) || 0,
  }))

  const complianceSeries = (compliance?.series || []).map((s: any) => ({
    label: s.label,
    Rate: s.rate,
    Present: s.present,
    Absent: s.absent,
  }))

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Attendance Reports</h1>
          <p className="text-gray-500 dark:text-gray-400">Monitor attendance, working hours and compliance across the organisation.</p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <span className="inline-flex items-center gap-1 text-sm text-gray-600 dark:text-gray-300 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-md px-3 py-2">
            <CalendarCheck className="h-4 w-4 text-gray-400" />
            {reportPeriodLabel}
          </span>
          <Button variant="outline" size="sm" onClick={() => window.location.reload()}>
            <RefreshCw className="h-4 w-4 mr-1" /> Refresh
          </Button>
          <Button variant="outline" size="sm" onClick={() => window.print()}>
            <Printer className="h-4 w-4 mr-1" /> Print
          </Button>
          <Button variant="primary" size="sm" onClick={handleExport} disabled={exporting}>
            {exporting ? <Loader2 className="h-4 w-4 mr-1 animate-spin" /> : <Download className="h-4 w-4 mr-1" />}
            Export CSV
          </Button>
        </div>
      </div>

      {/* Filters */}
      <FilterPanel
        filters={filters}
        options={options}
        onChange={applyFilters}
        onReset={resetFilters}
        onQuick={onQuick}
        activeCount={activeFilterCount}
      />

      {/* Error state */}
      {error && (
        <Card className="p-4 border-red-200 dark:border-red-800">
          <div className="flex flex-col items-center py-4 text-center">
            <AlertTriangle className="h-8 w-8 text-red-500 mb-2" />
            <p className="text-red-600 dark:text-red-400 text-sm font-medium">{error}</p>
            <Button variant="outline" className="mt-3" onClick={() => setReloadKey((k) => k + 1)}>
              <RefreshCw className="h-4 w-4 mr-1" /> Retry
            </Button>
          </div>
        </Card>
      )}

      {/* KPI cards */}
      {loading ? (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 animate-pulse">
          {Array.from({ length: 10 }).map((_, i) => (
            <div key={i} className="h-24 bg-gray-100 dark:bg-slate-800 rounded-xl" />
          ))}
        </div>
      ) : summary ? (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
          <StatCard title="Attendance Records" value={summary.attendance_records} icon={FileText} variant="default" subtitle={`${summary.range_days} day(s) in range`} onClick={() => toggleStatus('')} selected={filters.status === ''} />
          <StatCard title="Present" value={summary.employees_with_records} icon={Users} variant="success" subtitle="Employees with records" onClick={() => toggleStatus('present')} selected={filters.status === 'present'} />
          <StatCard title="Absent Days" value={summary.absent_days} icon={UserX} variant="danger" subtitle="Expected but not recorded" onClick={() => toggleStatus('absent')} selected={filters.status === 'absent'} />
          <StatCard title="On Leave" value={summary.leave_days} icon={CalendarDays} variant="info" subtitle="Approved leave days" onClick={() => toggleStatus('on_leave')} selected={filters.status === 'on_leave'} />
          <StatCard title="Late Arrivals" value={summary.late_arrivals} icon={Timer} variant="warning" subtitle="Clocked in after cutoff" onClick={() => toggleStatus('late')} selected={filters.status === 'late'} />
          <StatCard title="Missing Clock-Outs" value={summary.missing_clockouts} icon={AlertTriangle} variant="warning" subtitle="Requires review" onClick={() => toggleStatus('missing')} selected={filters.status === 'missing'} />
          <StatCard title="Auto Clock-Outs" value={summary.auto_clockouts} icon={LogOut} variant="info" subtitle="System-generated" onClick={() => toggleStatus('auto')} selected={filters.status === 'auto'} />
          <StatCard title="Total Hours" value={summary.total_hours} icon={Clock} variant="default" subtitle="Across selected period" />
          <StatCard title="Avg Hours/Day" value={summary.avg_hours_per_day} icon={Gauge} variant="default" subtitle={`Avg ${summary.avg_hours_per_employee} hrs/employee`} />
          <StatCard title="Compliance Rate" value={summary.compliance_rate !== null ? `${summary.compliance_rate}%` : 'N/A'} icon={Gauge} variant={summary.compliance_rate !== null && summary.compliance_rate >= 90 ? 'success' : 'warning'} subtitle={`${summary.present_days} of ${summary.expected_working_days} expected day(s)`} />
        </div>
      ) : null}

      {/* Attendance trend */}
      <Card title="Attendance Trend" subtitle={trends ? `Grouped ${trends.grouping} · present, absent and approved leave` : undefined}>
        <div className="h-72">
          {loading ? (
            <div className="h-full bg-gray-100 dark:bg-slate-900/50 rounded-lg animate-pulse" />
          ) : trendData.length === 0 ? (
            <p className="text-center text-sm text-gray-400 py-24">No attendance data for this period.</p>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={trendData}>
                <defs>
                  <linearGradient id="presentFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#10b981" stopOpacity={0.35} />
                    <stop offset="100%" stopColor="#10b981" stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="absentFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#ef4444" stopOpacity={0.25} />
                    <stop offset="100%" stopColor="#ef4444" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                <Tooltip />
                <Legend />
                <Area type="monotone" dataKey="Present" stroke="#10b981" fill="url(#presentFill)" strokeWidth={2} />
                <Area type="monotone" dataKey="Absent" stroke="#ef4444" fill="url(#absentFill)" strokeWidth={2} />
                <Area type="monotone" dataKey="On Leave" stroke="#3b82f6" fill="transparent" strokeWidth={2} strokeDasharray="5 3" />
              </AreaChart>
            </ResponsiveContainer>
          )}
        </div>
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Status distribution */}
        <Card title="Attendance Status Distribution" subtitle="How the period breaks down by status">
          <div className="h-72">
            {loading ? (
              <div className="h-full bg-gray-100 dark:bg-slate-900/50 rounded-lg animate-pulse" />
            ) : statusData.length === 0 ? (
              <p className="text-center text-sm text-gray-400 py-24">No data</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie data={statusData} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={55} outerRadius={90} paddingAngle={2}>
                    {statusData.map((entry, i) => (
                      <Cell key={i} fill={STATUS_COLORS[Object.keys(STATUS_LABELS).find((k) => STATUS_LABELS[k] === entry.name) || 'present']} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            )}
          </div>
        </Card>

        {/* Department analysis chart */}
        <Card title="Department Analysis" subtitle="Present vs absent by department">
          <div className="h-72">
            {loading ? (
              <div className="h-full bg-gray-100 dark:bg-slate-900/50 rounded-lg animate-pulse" />
            ) : deptChartData.length === 0 ? (
              <p className="text-center text-sm text-gray-400 py-24">No data</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={deptChartData} layout="vertical" margin={{ left: 8, right: 16 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                  <XAxis type="number" allowDecimals={false} tick={{ fontSize: 12 }} />
                  <YAxis type="category" dataKey="name" width={120} tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Legend />
                  <Bar dataKey="Present" fill="#10b981" radius={[0, 4, 4, 0]} />
                  <Bar dataKey="Absent" fill="#ef4444" radius={[0, 4, 4, 0]} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>
        </Card>
      </div>

      {/* Department table */}
      <Card title="Department Attendance Performance" subtitle="Compliance comparison across departments">
        {loading ? (
          <div className="h-24 bg-gray-100 dark:bg-slate-900/50 rounded-lg animate-pulse" />
        ) : departments.length === 0 ? (
          <p className="text-center text-sm text-gray-400 py-8">No department data for this period.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
              <thead className="bg-gray-50 dark:bg-slate-900">
                <tr>
                  {['Department', 'Present', 'Absent', 'On Leave', 'Late', 'Auto Out', 'Missing Out', 'Expected', 'Hours', 'Rate'].map((h) => (
                    <th key={h} className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                {departments.map((d) => (
                  <tr key={d.department_id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                    <td className="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{d.department}</td>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{d.present}</td>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{d.absent}</td>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{d.on_leave}</td>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{d.late}</td>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{d.auto}</td>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{d.missing}</td>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{d.expected_days}</td>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{d.total_hours}</td>
                    <td className="px-3 py-2 text-sm">
                      <Badge variant={d.attendance_rate === null ? 'default' : d.attendance_rate >= 90 ? 'success' : d.attendance_rate >= 75 ? 'warning' : 'danger'}>
                        {d.attendance_rate === null ? 'N/A' : `${d.attendance_rate}%`}
                      </Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Working hours */}
        <Card title="Working Hours Analysis" subtitle={hours ? `Total ${hours.total_hours}h · avg ${hours.avg_hours_per_day}h/day · avg ${hours.avg_hours_per_employee}h/employee` : undefined}>
          <div className="h-64">
            {loading ? (
              <div className="h-full bg-gray-100 dark:bg-slate-900/50 rounded-lg animate-pulse" />
            ) : hourTrend.length === 0 ? (
              <p className="text-center text-sm text-gray-400 py-20">No working-hours data for this period.</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={hourTrend}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                  <Tooltip />
                  <Bar dataKey="Hours" fill="#6366f1" radius={[4, 4, 0, 0]} />
                  <Bar dataKey="Avg Hours" fill="#a5b4fc" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>
          <p className="mt-3 text-xs text-gray-400">
            Hours reflect completed clock-in → clock-out sessions. Records without a clock-out appear under Missing Clock-Outs instead.
          </p>
        </Card>

        {/* Late arrivals */}
        <Card title="Late Arrival Analysis" subtitle={lateData ? `${lateData.total_late} late arrival(s) · ${lateData.repeat_offenders} employee(s) late ${lateData.threshold}+ time(s)` : undefined}>
          {loading ? (
            <div className="h-64 bg-gray-100 dark:bg-slate-900/50 rounded-lg animate-pulse" />
          ) : !lateData || (lateData.employees || []).length === 0 ? (
            <p className="text-center text-sm text-gray-400 py-20">No late arrivals recorded for this period.</p>
          ) : (
            <div className="space-y-3">
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                  <thead className="bg-gray-50 dark:bg-slate-900">
                    <tr>
                      {['Employee', 'Department', 'Late Days'].map((h) => (
                        <th key={h} className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                    {lateData.employees.slice(0, 5).map((e: any) => (
                      <tr key={e.employee_id}>
                        <td className="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{e.name}</td>
                        <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{e.department}</td>
                        <td className="px-3 py-2 text-sm">
                          <Badge variant={e.late_days >= lateData.threshold ? 'danger' : 'warning'}>{e.late_days}</Badge>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {(lateData.by_department || []).length > 0 && (
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  Highest by department: {lateData.by_department.slice(0, 3).map((d: any) => `${d.department} (${d.late_days})`).join(', ')}
                </p>
              )}
              <p className="text-xs text-gray-400">Employee-level detail is limited to users with report access and their organisational scope.</p>
            </div>
          )}
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Compliance */}
        <Card title="Attendance Compliance" subtitle={compliance ? `${compliance.compliance_rate !== null ? `${compliance.compliance_rate}%` : 'N/A'} compliance · ${compliance.present_days} of ${compliance.expected_working_days} expected day(s)` : undefined}>
          <div className="h-56">
            {loading ? (
              <div className="h-full bg-gray-100 dark:bg-slate-900/50 rounded-lg animate-pulse" />
            ) : complianceSeries.length === 0 ? (
              <p className="text-center text-sm text-gray-400 py-16">No compliance data for this period.</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={complianceSeries}>
                  <defs>
                    <linearGradient id="rateFill" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#10b981" stopOpacity={0.35} />
                      <stop offset="100%" stopColor="#10b981" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                  <YAxis domain={[0, 100]} tick={{ fontSize: 12 }} unit="%" />
                  <Tooltip />
                  <Area type="monotone" dataKey="Rate" stroke="#10b981" fill="url(#rateFill)" strokeWidth={2} connectNulls />
                </AreaChart>
              </ResponsiveContainer>
            )}
          </div>
          {compliance?.lowest?.length > 0 && (
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <span className="text-xs font-medium text-gray-500 dark:text-gray-400">Lowest attendance:</span>
              {compliance.lowest.map((p: any, i: number) => (
                <span key={i} className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800">
                  {p.label} · {p.rate}%
                </span>
              ))}
            </div>
          )}
        </Card>

        {/* Insights */}
        <Card title="Attendance Insights" subtitle="Dynamically derived from the current filters">
          {loading ? (
            <div className="h-56 bg-gray-100 dark:bg-slate-900/50 rounded-lg animate-pulse" />
          ) : insights.length === 0 ? (
            <p className="text-center text-sm text-gray-400 py-16">No insights for this period.</p>
          ) : (
            <div className="space-y-3">
              {insights.map((insight, i) => (
                <div key={i} className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                  <Sparkles className="h-4 w-4 mt-0.5 text-primary-500 shrink-0" />
                  <span>{insight}</span>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      {/* Employee attendance table */}
      <Card
        title="Employee Attendance Report"
        subtitle={employees ? `${employees.total} employee(s) match the active filters` : undefined}
      >
        {loadingEmployees ? (
          <div className="flex justify-center py-10">
            <Loader2 className="h-6 w-6 animate-spin text-gray-400" />
          </div>
        ) : !employees || employees.items.length === 0 ? (
          <div className="py-12 text-center">
            <CalendarCheck className="h-8 w-8 text-gray-300 mx-auto mb-2" />
            <p className="text-gray-500 dark:text-gray-400 font-medium">No attendance records found</p>
            <p className="text-sm text-gray-400 mt-1">
              Try changing the selected period or adjusting your filters.
            </p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                <thead className="bg-gray-50 dark:bg-slate-900">
                  <tr>
                    {SORTABLE_COLUMNS.map((c) => (
                      <th key={c.key} className="px-3 py-2 text-left">
                        <button
                          type="button"
                          onClick={() => handleSort(c.key)}
                          className={`text-xs font-medium uppercase tracking-wider flex items-center gap-1 ${
                            sort === c.key
                              ? 'text-primary-600 dark:text-primary-400'
                              : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'
                          }`}
                        >
                          {c.label}
                          {sort === c.key && <span aria-hidden="true">{dir === 'asc' ? '↑' : '↓'}</span>}
                        </button>
                      </th>
                    ))}
                    <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Details
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                  {employees.items.map((row: EmployeeRow) => (
                    <EmployeeTableRow key={row.employee_id} row={row} onOpen={() => setDrawerEmployee(row.employee_id)} />
                  ))}
                </tbody>
              </table>
            </div>
            {employees.total > 0 && (
              <div className="flex items-center justify-between mt-4">
                <p className="text-sm text-gray-500">
                  Page {employees.page} of {employees.last_page} · {employees.total} employee(s)
                  {filters.status ? ` · filtered by ${STATUS_LABELS[filters.status] || filters.status}` : ''}
                </p>
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" disabled={employees.page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                    <ChevronLeft className="h-4 w-4" />
                  </Button>
                  <Button variant="outline" size="sm" disabled={employees.page >= employees.last_page} onClick={() => setPage((p) => Math.min(employees.last_page, p + 1))}>
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </Card>

      {/* Drill-down drawer */}
      <DrillDownDrawer
        open={drawerEmployee !== null}
        employeeId={drawerEmployee}
        filters={filters}
        onClose={() => setDrawerEmployee(null)}
      />
    </div>
  )
}

/** One employee table row (extracted to keep the table readable). */
const EmployeeTableRow = ({ row, onOpen }: { row: EmployeeRow; onOpen: () => void }) => (
  <tr className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
    <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{row.emp_no}</td>
    <td className="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{row.name}</td>
    <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{row.department}</td>
    <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{row.office || '-'}</td>
    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{row.expected_days}</td>
    <td className="px-3 py-2 text-sm text-green-700 dark:text-green-400">{row.days_present}</td>
    <td className="px-3 py-2 text-sm text-red-700 dark:text-red-400">{row.absent_days}</td>
    <td className="px-3 py-2 text-sm text-blue-700 dark:text-blue-400">{row.leave_days}</td>
    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{row.late_days}</td>
    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{row.auto_days}</td>
    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{row.missing_out}</td>
    <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{row.total_hours}</td>
    <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{row.avg_hours}</td>
    <td className="px-3 py-2 text-sm">
      <Badge variant={row.attendance_rate === null ? 'default' : row.attendance_rate >= 90 ? 'success' : row.attendance_rate >= 75 ? 'warning' : 'danger'}>
        {row.attendance_rate === null ? 'N/A' : `${row.attendance_rate}%`}
      </Badge>
    </td>
    <td className="px-3 py-2 text-right">
      <Button variant="outline" size="sm" onClick={onOpen} aria-label={`View ${row.name} attendance details`}>
        <Eye className="h-4 w-4" />
      </Button>
    </td>
  </tr>
)

export default AttendanceReport
