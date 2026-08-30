import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  ResponsiveContainer, AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, Legend,
} from 'recharts'
import { useSearchParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import {
  CalendarRange, RefreshCw, Download, ChevronLeft, ChevronRight,
  FileText, Users, CheckCircle2, Clock, XCircle, CalendarDays, Filter, Search,
  Loader2, Sparkles,
} from 'lucide-react'
import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Badge from '../../components/ui/Badge'
import leaveReportService from '../../api/services/leaveReportService'

// ---- Types -----------------------------------------------------------------
type Filters = {
  date_basis: 'applied_at' | 'start_date' | 'end_date'
  from: string
  to: string
  department_id: string
  leave_type_id: string
  financial_year_id: string
  status: string
  search: string
}

type Kpi = {
  total_applications: number
  total_days: number
  avg_duration: number
  approved: number
  pending: number
  rejected: number
  cancelled: number
  invalidated: number
  approved_pct: number
  rejected_pct: number
}

const STATUS_LABELS: Record<string, string> = {
  approved: 'Approved',
  pending: 'Pending',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
  invalidated: 'Invalidated',
}

const STATUS_COLORS: Record<string, string> = {
  approved: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
  pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
  rejected: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  cancelled: 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-gray-300',
  invalidated: 'bg-gray-200 text-gray-700 dark:bg-slate-700 dark:text-gray-300',
}

const PIE_COLORS = ['#3b82f6', '#f59e0b', '#ef4444', '#6b7280', '#8b5cf6']

// ---- Helpers ---------------------------------------------------------------
const toISODate = (d: Date): string => {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

const today = (): string => toISODate(new Date())

/** Financial year period for a given date (July → June). Returns {start,end}. */
const currentFinancialYear = (): { start: string; end: string } => {
  const now = new Date()
  const year = now.getFullYear()
  const startYear = now.getMonth() >= 6 ? year : year - 1
  return {
    start: `${startYear}-07-01`,
    end: `${startYear + 1}-06-30`,
  }
}

/** Resolve a quick period preset to a {from,to} date range. */
const quickRange = (key: string): { from: string; to: string } | null => {
  const now = new Date()
  const startOf = (y: number, m: number) => toISODate(new Date(y, m, 1))
  const startOfWeek = (d: Date): Date => {
    const day = (d.getDay() + 6) % 7 // Monday-first
    return new Date(d.getFullYear(), d.getMonth(), d.getDate() - day)
  }
  switch (key) {
    case 'today':
      return { from: today(), to: today() }
    case 'week':
      return { from: toISODate(startOfWeek(now)), to: today() }
    case 'month':
      return { from: startOf(now.getFullYear(), now.getMonth()), to: today() }
    case 'last_month': {
      const first = new Date(now.getFullYear(), now.getMonth() - 1, 1)
      return {
        from: toISODate(first),
        to: toISODate(new Date(now.getFullYear(), now.getMonth(), 0)),
      }
    }
    case 'quarter': {
      const q = Math.floor(now.getMonth() / 3)
      const qStart = new Date(now.getFullYear(), q * 3, 1)
      return { from: toISODate(qStart), to: today() }
    }
    case 'year':
      return { from: `${now.getFullYear()}-01-01`, to: today() }
    case 'fy': {
      const fy = endFinancialYear()
      return { from: fy.start, to: today() }
    }
    default:
      return null
  }
}

const endFinancialYear = (): { start: string; end: string } => {
  const now = new Date()
  const year = now.getFullYear()
  const startYear = now.getMonth() >= 6 ? year : year - 1
  return { start: `${startYear}-07-01`, end: `${startYear + 1}-06-30` }
}

const formatDateLabel = (value: string): string => {
  if (!value) return value
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const monthLabel = (value: string): string => {
  if (!value || value.length < 7) return value
  const [y, m] = value.split('-')
  const d = new Date(Number(y), Number(m) - 1, 1)
  return d.toLocaleDateString('en-US', { month: 'short', year: '2-digit' })
}

/** Format a trend label according to the chosen grouping. */
const labelString = (grouping: string, value: string): string => {
  if (!value) return value
  if (grouping === 'monthly') return monthLabel(value)
  return formatDateLabel(value)
}

/** Trigger a browser download for a blob returned by the API. */
const downloadBlob = (blob: Blob, filename: string): void => {
  const url = window.URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  window.URL.revokeObjectURL(url)
}

const PER_PAGE = 15

// ---- Stat card -------------------------------------------------------------
const StatCard = ({ title, value, icon: Icon, subtitle, accent = 'default' }: any) => {
  const accents: Record<string, string> = {
    default: 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300',
    success: 'bg-green-100 text-green-600 dark:bg-green-900/40 dark:text-green-300',
    warning: 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900/40 dark:text-yellow-300',
    danger: 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300',
    info: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300',
  }
  return (
    <Card className="p-4">
      <div className="flex items-start justify-between">
        <div>
          <p className="text-sm font-medium text-gray-500 dark:text-gray-400">{title}</p>
          <p className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{value}</p>
          {subtitle && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}
        </div>
        <div className={`h-10 w-10 rounded-lg flex items-center justify-center ${accent}`}>
          <Icon className="h-5 w-5" />
        </div>
      </div>
    </Card>
  )
}

// ---- Filter panel ----------------------------------------------------------
const QUICK_PRESETS = [
  { key: 'today', label: 'Today' },
  { key: 'week', label: 'This Week' },
  { key: 'month', label: 'This Month' },
  { key: 'last_month', label: 'Last Month' },
  { key: 'quarter', label: 'This Quarter' },
  { key: 'year', label: 'This Year' },
  { key: 'fy', label: 'Financial Year' },
]

const FilterPanel = ({
  filters, options, onChange, onReset, onQuick, activeCount,
}: any) => {
  const basisOptions = [
    { value: 'applied_at', label: 'Application Date' },
    { value: 'start_date', label: 'Leave Start Date' },
    { value: 'end_date', label: 'Leave End Date' },
  ]
  return (
    <Card className="p-4">
      <div className="flex items-center gap-2 mb-3">
        <Filter className="h-4 w-4 text-gray-400" />
        <h3 className="text-sm font-medium text-gray-700 dark:text-gray-200">Filters</h3>
        {activeCount > 0 && (
          <Badge className="ml-1">{activeCount} active</Badge>
        )}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Date basis */}
        <div>
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
            Filter Date By
          </label>
          <select
            value={filters.date_basis}
            onChange={(e) => onChange({ date_basis: e.target.value })}
            className="input"
          >
            {basisOptions.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
        </div>

        {/* From */}
        <div>
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From Date</label>
          <input type="date" value={filters.from} onChange={(e) => onChange({ from: e.target.value })} className="input" />
        </div>

        {/* To */}
        <div>
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To Date</label>
          <input type="date" value={filters.to} onChange={(e) => onChange({ to: e.target.value })} className="input" />
        </div>

        {/* Financial year */}
        <div>
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Financial Year</label>
          <select
            value={filters.financial_year_id}
            onChange={(e) => onChange({ financial_year_id: e.target.value })}
            className="input"
          >
            <option value="">All Years</option>
            {(options?.financial_years || []).map((fy: any) => (
              <option key={fy.id} value={fy.id}>{fy.year_name}</option>
            ))}
          </select>
        </div>

        {/* Department */}
        <div>
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Department</label>
          <select
            value={filters.department_id}
            onChange={(e) => onChange({ department_id: e.target.value })}
            className="input"
          >
            <option value="">All Departments</option>
            {(options?.departments || []).map((d: any) => (
              <option key={d.id} value={d.id}>{d.name}</option>
            ))}
          </select>
        </div>

        {/* Leave type */}
        <div>
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Leave Type</label>
          <select
            value={filters.leave_type_id}
            onChange={(e) => onChange({ leave_type_id: e.target.value })}
            className="input"
          >
            <option value="">All Leave Types</option>
            {(options?.leave_types || []).map((t: any) => (
              <option key={t.id} value={t.id}>{t.name}</option>
            ))}
          </select>
        </div>

        {/* Status */}
        <div>
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
          <select
            value={filters.status}
            onChange={(e) => onChange({ status: e.target.value })}
            className="input"
          >
            <option value="">All Statuses</option>
            {(options?.statuses || []).map((s: string) => (
              <option key={s} value={s}>{STATUS_LABELS[s] || s}</option>
            ))}
          </select>
        </div>

        {/* Search */}
        <div>
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Search</label>
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
      </div>

      {/* Quick presets */}
      <div className="flex flex-wrap items-center gap-2 mt-4">
        <span className="text-xs font-medium text-gray-500 dark:text-gray-400">Quick:</span>
        {QUICK_PRESETS.map((p) => (
          <button
            key={p.key}
            type="button"
            onClick={() => onQuick(p.key, filters.date_basis)}
            className="px-3 py-1 text-xs rounded-full border border-gray-200 dark:border-slate-600 text-gray-600 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-slate-700 transition-colors"
          >
            {p.label}
          </button>
        ))}
        <div className="ml-auto flex items-center gap-2">
          <Button size="sm" variant="outline" onClick={onReset} disabled={activeCount === 0}>
            Reset
          </Button>
        </div>
      </div>
    </Card>
  )
}

// ---- Main component --------------------------------------------------------
const LeaveReports = () => {
  const [searchParams, setSearchParams] = useSearchParams()

  const [options, setOptions] = useState<any>(null)
  const [summary, setSummary] = useState<Kpi | null>(null)
  const [trends, setTrends] = useState<any>({ grouping: 'monthly', points: [] })
  const [byType, setByType] = useState<any[]>([])
  const [byDept, setByDept] = useState<any[]>([])
  const [byStatus, setByStatus] = useState<any[]>([])
  const [duration, setDuration] = useState<any[]>([])
  const [insights, setInsights] = useState<string[]>([])

  const [records, setRecords] = useState<any[]>([])
  const [pagination, setPagination] = useState({ total: 0, page: 1, per_page: PER_PAGE, last_page: 1 })

  const [loading, setLoading] = useState(true)
  const [loadingRecords, setLoadingRecords] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [error, setError] = useState('')

  const fy = useMemo(() => currentFinancialYear(), [])

  // Initial filters from URL query params (deep-linkable / persisted).
  const [filters, setFilters] = useState<Filters>(() => ({
    date_basis: (searchParams.get('date_basis') as any) || 'start_date',
    from: searchParams.get('from') || fy.start,
    to: searchParams.get('to') || today(),
    department_id: searchParams.get('department_id') || '',
    leave_type_id: searchParams.get('leave_type_id') || '',
    financial_year_id: searchParams.get('financial_year_id') || '',
    status: searchParams.get('status') || '',
    search: searchParams.get('search') || '',
  }))
  const [page, setPage] = useState(1)
  const debounceRef = useRef<any>(null)

  // Sync filters to the URL query string.
  const updateUrl = useCallback((next: Filters) => {
    const params: Record<string, string> = {}
    if (next.date_basis) params.date_basis = next.date_basis
    if (next.from) params.from = next.from
    if (next.to) params.to = next.to
    if (next.department_id) params.department_id = next.department_id
    if (next.leave_type_id) params.leave_type_id = next.leave_type_id
    if (next.financial_year_id) params.financial_year_id = next.financial_year_id
    if (next.status) params.status = next.status
    if (next.search) params.search = next.search
    setSearchParams(params, { replace: true })
  }, [setSearchParams])

  const queryParams = useCallback((f: Filters, extra: Record<string, any> = {}) => {
    const p: Record<string, any> = { ...extra }
    if (f.date_basis) p.date_basis = f.date_basis
    if (f.from) p.from = f.from
    if (f.to) p.to = f.to
    if (f.department_id) p.department_id = f.department_id
    if (f.leave_type_id) p.leave_type_id = f.leave_type_id
    if (f.financial_year_id) p.financial_year_id = f.financial_year_id
    if (f.status) p.status = f.status
    if (f.search) p.search = f.search
    return p
  }, [])

  // Load filter options once.
  useEffect(() => {
    leaveReportService
      .options()
      .then(setOptions)
      .catch((err) => {
        console.error('Failed to load report options', err)
      })
  }, [])

  // Load analytics whenever filters change.
  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    const params = queryParams(filters)

    const loadAll = async () => {
      try {
        const [sum, tr, tp, dp, st, dr, ins] = await Promise.all([
          leaveReportService.summary(params),
          leaveReportService.trends(params),
          leaveReportService.byType(params),
          leaveReportService.byDepartment(params),
          leaveReportService.byStatus(params),
          leaveReportService.duration(params),
          leaveReportService.insights(params),
        ])
        if (cancelled) return
        setSummary(sum)
        setTrends(tr || { points: [] })
        setByType(tp || [])
        setByDept(dp || [])
        setByStatus(st || [])
        setDuration(dr || [])
        setInsights(Array.isArray(ins) ? ins : [])
      } catch (err: any) {
        if (!cancelled) setError(err?.response?.data?.message || 'Failed to load leave report analytics.')
      } finally {
        if (!cancelled) setLoading(false)
      }
    }
    loadAll()
    return () => {
      cancelled = true
    }
  }, [filters, queryParams])

  // Load detail records (page-dependent).
  useEffect(() => {
    let cancelled = false
    setLoadingRecords(true)
    const params = queryParams(filters, { page, per_page: PER_PAGE })
    leaveReportService
      .records(params)
      .then((data) => {
        if (cancelled) return
        setRecords(data?.items || [])
        setPagination({
          total: data?.total || 0,
          page: data?.page || 1,
          per_page: data?.per_page || PER_PAGE,
          last_page: data?.last_page || 1,
        })
      })
      .catch((err) => {
        if (!cancelled) console.error('Failed to load leave records', err)
      })
      .finally(() => {
        if (!cancelled) setLoadingRecords(false)
      })
    return () => {
      cancelled = true
    }
  }, [filters, page, queryParams])

  const applyFilters = useCallback((patch: Partial<Filters>) => {
    setFilters((prev) => {
      const next = { ...prev, ...patch }
      // Debounce the free-text search so we don't re-query on every keystroke.
      if ('search' in patch) {
        if (debounceRef.current) clearTimeout(debounceRef.current)
        debounceRef.current = setTimeout(() => {
          setFilters((cur) => {
            const merged = { ...cur, search: patch.search ?? '' }
            updateUrl(merged)
            return merged
          })
        }, 400)
        return prev
      }
      updateUrl(next)
      setPage(1)
      return next
    })
  }, [updateUrl])

  const onQuick = useCallback((key: string) => {
    const range = quickRange(key)
    if (!range) return
    setFilters((prev) => {
      const next = { ...prev, from: range.from, to: range.to }
      updateUrl(next)
      setPage(1)
      return next
    })
  }, [updateUrl])

  const resetFilters = useCallback(() => {
    const next: Filters = {
      date_basis: 'start_date',
      from: fy.start,
      to: today(),
      department_id: '',
      leave_type_id: '',
      financial_year_id: '',
      status: '',
      search: '',
    }
    setFilters(next)
    updateUrl(next)
    setPage(1)
  }, [fy, updateUrl])

  const activeFilterCount = useMemo(() => {
    let c = 0
    const f = filters
    if (f.date_basis !== 'start_date') c++
    if (f.from || f.to) c++
    if (f.department_id) c++
    if (f.leave_type_id) c++
    if (f.financial_year_id) c++
    if (f.status) c++
    if (f.search) c++
    return c
  }, [filters])

  const handleExport = useCallback(async () => {
    setExporting(true)
    try {
      const blob = await leaveReportService.exportCsv(queryParams(filters))
      const filename = `leave_report_${today()}.csv`
      downloadBlob(blob, filename)
      toast.success('Leave report exported')
    } catch (err: any) {
      const msg = err?.response?.data?.message || 'Export failed. You may not have export permission.'
      toast.error(msg)
    } finally {
      setExporting(false)
    }
  }, [filters, queryParams])

  const trendData = useMemo(() => {
    const points = Array.isArray(trends?.points) ? trends.points : []
    return points.map((p: any) => ({
      ...p,
      label: (trends.grouping === 'monthly' || String(p.label).length >= 10) ? labelString(trends.grouping, p.label) : p.label,
    }))
  }, [trends])

  const statusData = useMemo(() => {
    return (byStatus || []).map((s: any) => ({
      name: STATUS_LABELS[s.status] || s.status,
      value: s.count,
    }))
  }, [byStatus])

  const deptChartData = useMemo(() => {
    return (byDept || []).slice(0, 12).map((d: any) => ({
      name: d.department,
      Applications: d.count,
      'Leave Days': d.days,
    }))
  }, [byDept])

  const durationData = useMemo(() => (duration || []), [duration])

  const tableColumns = useMemo(() => [
    {
      key: 'employee_name',
      label: 'Employee',
      render: (_: any, row: any) => (
        <div>
          <div className="font-medium text-gray-900 dark:text-gray-100">{row.employee_name}</div>
          <div className="text-xs text-gray-500 dark:text-gray-400">{row.employee_number}</div>
        </div>
      ),
    },
    { key: 'department', label: 'Department' },
    { key: 'leave_type', label: 'Leave Type' },
    { key: 'applied_at', label: 'Applied', render: (v: any) => v ? formatDateLabel(v) : '—' },
    { key: 'start_date', label: 'Start', render: (v: any) => v ? formatDateLabel(v) : '—' },
    { key: 'end_date', label: 'End', render: (v: any) => v ? formatDateLabel(v) : '—' },
    { key: 'days', label: 'Days' },
    {
      key: 'status',
      label: 'Status',
      render: (v: any) => (
        <Badge className={STATUS_COLORS[v] || 'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-gray-300'}>
          {STATUS_LABELS[v] || v}
        </Badge>
      ),
    },
  ], [])

  const reportPeriodLabel = useMemo(() => {
    if (filters.from && filters.to) {
      return `${new Date(filters.from).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })} – ${new Date(filters.to).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })}`
    }
    return 'All dates'
  }, [filters])

  const emptyState = summary !== null && summary.total_applications === 0

  // ---- Charts --------------------------------------------------------------
  const renderCharts = !loading && !emptyState && (
    <div className="space-y-6">
      <Card title="Leave Applications Trend" subtitle={`Grouped ${trends.grouping}`}>
        {trendData.length === 0 ? (
          <p className="text-center text-sm text-gray-400 py-8">No application data in this range.</p>
        ) : (
          <ResponsiveContainer width="100%" height={260}>
            <AreaChart data={trendData}>
              <defs>
                <linearGradient id="trendFill" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="#3b82f6" stopOpacity={0.3} />
                  <stop offset="100%" stopColor="#3b82f6" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
              <XAxis dataKey="label" tick={{ fontSize: 12 }} />
              <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
              <Tooltip />
              <Area type="monotone" dataKey="count" name="Applications" stroke="#3b82f6" fill="url(#trendFill)" />
            </AreaChart>
          </ResponsiveContainer>
        )}
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card title="Leave Type Distribution" subtitle="Applications by leave type">
          <div className="h-64">
            {byType.length === 0 ? (
              <p className="text-center text-sm text-gray-400 py-24">No data</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={byType}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                  <XAxis dataKey="leave_type" tick={{ fontSize: 11 }} interval={0} angle={-20} textAnchor="end" height={60} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                  <Tooltip />
                  <Bar dataKey="count" name="Applications" fill="#3b82f6" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>
        </Card>

        <Card title="Status Distribution">
          <div className="h-64">
            {statusData.length === 0 ? (
              <p className="text-center text-sm text-gray-400 py-24">No data</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie data={statusData} dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={80} label>
                    {statusData.map((_, i) => (
                      <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            )}
          </div>
        </Card>
      </div>

      <Card title="Department Leave Analysis" subtitle="Applications and leave days by department">
        <div className="h-72">
          {deptChartData.length === 0 ? (
            <p className="text-center text-sm text-gray-400 py-24">No data</p>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={deptChartData} layout="vertical" margin={{ left: 8, right: 16 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                <XAxis type="number" allowDecimals={false} tick={{ fontSize: 12 }} />
                <YAxis type="category" dataKey="name" width={140} tick={{ fontSize: 12 }} />
                <Tooltip />
                <Legend />
                <Bar dataKey="Applications" fill="#3b82f6" radius={[0, 4, 4, 0]} />
                <Bar dataKey="Leave Days" fill="#8b5cf6" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>
      </Card>

      <Card title="Leave Duration Analysis">
        <div className="h-56">
          {durationData.length === 0 ? (
            <p className="text-center text-sm text-gray-400 py-16">No data</p>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={durationData}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                <XAxis dataKey="bucket" tick={{ fontSize: 12 }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                <Tooltip />
                <Bar dataKey="count" name="Applications" fill="#f59e0b" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>
      </Card>
    </div>
  )

  // ---- Detailed table ------------------------------------------------------
  const renderTable = (
    <Card title="Detailed Leave Report" subtitle={`${pagination.total} record(s)`}>
      {loadingRecords ? (
        <div className="flex justify-center py-10"><Loader2 className="h-6 w-6 animate-spin text-gray-400" /></div>
      ) : records.length === 0 ? (
        <div className="py-12 text-center">
          <p className="text-gray-500 dark:text-gray-400 font-medium">No leave records found</p>
          <p className="text-sm text-gray-400 mt-1">Try adjusting your filters or selecting a different reporting period.</p>
        </div>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
              <thead className="bg-gray-50 dark:bg-slate-900">
                <tr>
                  {tableColumns.map((c) => (
                    <th key={c.key} className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{c.label}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                {records.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                    {tableColumns.map((c) => (
                      <td key={c.key} className="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                        {c.render ? c.render(row[c.key], row) : row[c.key]}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {pagination.total > PER_PAGE && (
            <div className="flex items-center justify-between mt-4">
              <p className="text-sm text-gray-500">
                Page {pagination.page} of {pagination.last_page} · {pagination.total} records
              </p>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                <Button variant="outline" size="sm" disabled={page >= pagination.last_page} onClick={() => setPage((p) => Math.min(pagination.last_page, p + 1))}>
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </Card>
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Leave Reports</h1>
          <p className="text-gray-500 dark:text-gray-400">Analyze employee leave patterns, applications, approvals and leave trends.</p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <span className="inline-flex items-center gap-1 text-sm text-gray-600 dark:text-gray-300 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-md px-3 py-2">
            <CalendarRange className="h-4 w-4 text-gray-400" />
            Reporting Period: {reportPeriodLabel}
          </span>
          <Button variant="outline" size="sm" onClick={() => window.location.reload()}>
            <RefreshCw className="h-4 w-4 mr-1" /> Refresh
          </Button>
          <Button variant="outline" size="sm" onClick={handleExport} loading={exporting}>
            <Download className="h-4 w-4 mr-1" /> Export CSV
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

      {error && (
        <Card className="p-4 border-red-200 dark:border-red-800">
          <div className="flex items-center gap-2 text-red-600 dark:text-red-400">
            <XCircle className="h-5 w-5" />
            <span className="text-sm font-medium">{error}</span>
          </div>
        </Card>
      )}

      {/* KPI cards */}
      {loading ? (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 animate-pulse">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="h-28 bg-gray-100 dark:bg-slate-800 rounded-xl" />
          ))}
        </div>
      ) : summary ? (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
          <StatCard title="Total Applications" value={summary.total_applications} icon={FileText} accent="default" />
          <StatCard title="Approved" value={`${summary.approved}`} icon={CheckCircle2} accent="success" subtitle={`${summary.approved_pct}%`} />
          <StatCard title="Pending" value={summary.pending} icon={Clock} accent="warning" subtitle="Requires action" />
          <StatCard title="Rejected" value={summary.rejected} icon={XCircle} accent="danger" subtitle={`${summary.rejected_pct}%`} />
          <StatCard title="Total Leave Days" value={summary.total_days} icon={CalendarDays} accent="info" />
          <StatCard title="Avg Duration" value={`${summary.avg_duration} Days`} icon={Users} accent="default" />
        </div>
      ) : null}

      {/* Insights */}
      {!loading && insights.length > 0 && (
        <Card title="Leave Insights">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {insights.map((insight, i) => (
              <div key={i} className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                <Sparkles className="h-4 w-4 mt-0.5 text-primary-500 shrink-0" />
                <span>{insight}</span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {/* Charts */}
      {renderCharts}

      {/* Empty state */}
      {!loading && emptyState && (
        <Card className="p-10 text-center">
          <FileText className="h-10 w-10 mx-auto text-gray-300 mb-2" />
          <p className="text-gray-700 dark:text-gray-200 font-medium">No leave records found</p>
          <p className="text-sm text-gray-400 mt-1">Try adjusting your filters or selecting a different reporting period.</p>
        </Card>
      )}

      {/* Detailed table */}
      {!loading && renderTable}
    </div>
  )
}

export default LeaveReports