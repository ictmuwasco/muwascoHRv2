import { useState, useEffect, useRef, useCallback } from 'react'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Modal from '../../components/ui/Modal'
import Tabs from '../../components/ui/Tabs'
import { toCsv, downloadCsv, csvFilenameWithDate } from '../../utils/csvUtils'
import {
  Users,
  UserCheck,
  UserX,
  CalendarX,
  Percent,
  RefreshCw,
  Pause,
  Play,
  Download,
  Search,
  Eye,
  AlertTriangle,
  CalendarOff,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react'

/**
 * Attendance Dashboard - organisation-wide attendance monitoring.
 *
 * All statuses/figures come from GET /api/attendance/hr-dashboard where
 * AttendanceDashboardService computes them authoritatively. This component
 * never derives attendance logic itself.
 */

const PER_PAGE = 25

const STATUS_META = {
  PRESENT:           { label: 'Present',           variant: 'success' },
  LATE:              { label: 'Late',              variant: 'warning' },
  CLOCKED_OUT:       { label: 'Clocked Out',       variant: 'success' },
  NOT_CLOCKED_IN:    { label: 'Not Clocked In',    variant: 'warning' },
  ABSENT:            { label: 'Absent',            variant: 'danger' },
  ON_LEAVE:          { label: 'On Leave',          variant: 'primary' },
  HOLIDAY:           { label: 'Holiday',           variant: 'default' },
  NON_WORKING_DAY:   { label: 'Non-Working Day',   variant: 'default' },
  AUTO_CLOCKED_OUT:  { label: 'Auto Clocked Out',  variant: 'danger' },
  MISSING_CLOCK_OUT: { label: 'Missing Clock Out', variant: 'danger' },
}

const statusLabel = (status) => STATUS_META[status]?.label || status

const todayStr = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const yesterdayStr = () => {
  const d = new Date()
  d.setDate(d.getDate() - 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const formatDisplayDate = (dateStr) =>
  new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-KE', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })

const TREND_COLORS = {
  present: 'bg-green-500',
  absent: 'bg-red-500',
  on_leave: 'bg-blue-500',
}

const AttendanceDashboard = () => {
  const [preset, setPreset] = useState('today') // today | yesterday | custom
  const [customDate, setCustomDate] = useState(todayStr())
  const [departmentId, setDepartmentId] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState(null)
  const [lastUpdated, setLastUpdated] = useState(null)

  const [autoRefresh, setAutoRefresh] = useState(true)

  // Detail modal
  const [detailEmployee, setDetailEmployee] = useState(null)
  const [detailData, setDetailData] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState(null)
  // Export
  const [exporting, setExporting] = useState(false)

  const requestIdRef = useRef(0)

  const date = preset === 'today' ? todayStr() : preset === 'yesterday' ? yesterdayStr() : customDate
  const isTodayView = date === todayStr()

  const fetchDashboard = useCallback(async (opts = {}) => {
    const requestId = ++requestIdRef.current
    if (opts.silent) {
      setRefreshing(true)
    } else {
      setLoading(true)
    }
    setError(null)

    try {
      const params = {
        date,
        department_id: departmentId || undefined,
        status: statusFilter || undefined,
        search: search || undefined,
        page,
        limit: PER_PAGE,
        trend_days: 7,
        // User-initiated loads (initial, filter/page changes) are audited as
        // "view". Background auto-refresh/refresh calls pass silent=true and
        // therefore omit the flag so they are not written to the audit log.
        audit: opts.silent ? undefined : 'view',
      }
      const response = await api.get('/attendance/hr-dashboard', { params })
      if (requestId !== requestIdRef.current) return // stale response
      setData(response.data?.data ?? null)
      setLastUpdated(new Date())
    } catch (err) {
      if (requestId === requestIdRef.current) {
        setError(err?.response?.data?.message || err?.message || 'Failed to load the attendance dashboard.')
      }
    } finally {
      if (requestId === requestIdRef.current) {
        setLoading(false)
        setRefreshing(false)
      }
    }
  }, [date, departmentId, statusFilter, search, page])

  // Initial + filter-driven loads
  useEffect(() => {
    fetchDashboard()
  }, [fetchDashboard])

  // Debounce the search box so typing does not hammer the API
  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim()), 400)
    return () => clearTimeout(t)
  }, [searchInput])

  // Auto refresh (today only): every 60s while the tab is visible.
  useEffect(() => {
    if (!autoRefresh || !isTodayView) return undefined

    const tick = () => {
      if (document.visibilityState === 'visible') {
        fetchDashboard({ silent: true })
      }
    }
    const interval = setInterval(tick, 60 * 1000)
    const onVisible = () => {
      if (document.visibilityState === 'visible') tick()
    }
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(interval)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [autoRefresh, isTodayView, fetchDashboard])

  // Reset pagination whenever the result set definition changes
  useEffect(() => {
    setPage(1)
  }, [date, departmentId, statusFilter, search])

  const toggleStatusFilter = (status) => {
    setStatusFilter((prev) => (prev === status ? '' : status))
  }

  const openDetail = async (employee) => {
    setDetailEmployee(employee)
    setDetailData(null)
    setDetailError(null)
    setDetailLoading(true)
    try {
      const end = todayStr()
      const d = new Date()
      d.setDate(d.getDate() - 30)
      const start = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
      const response = await api.get('/attendance/hr-employee-history', {
        params: { employee_id: employee.employee_db_id, start_date: start, end_date: end, limit: 30 },
      })
      setDetailData(response.data?.data ?? null)
    } catch (err) {
      setDetailError(err?.response?.data?.message || 'Failed to load attendance details.')
    } finally {
      setDetailLoading(false)
    }
  }

  const closeDetail = () => {
    setDetailEmployee(null)
    setDetailData(null)
    setDetailError(null)
  }

  // Export the full filtered result set (not just the current page).
  const exportCsv = async () => {
    setExporting(true)
    try {
      const response = await api.get('/attendance/hr-dashboard', {
        params: {
          date,
          department_id: departmentId || undefined,
          status: statusFilter || undefined,
          search: search || undefined,
          page: 1,
          limit: 200,
          trend_days: 1,
          // Distinguish this client-side CSV export from a plain dashboard load
          // so the backend can emit an ACTION_EXPORT audit record.
          audit: 'export',
        },
      })
      const payload = response.data?.data
      const rows = payload?.employees ?? []
      if (rows.length === 0) return

      const headers = ['Employee', 'Employee ID', 'Department', 'Date', 'Clock In', 'Clock Out', 'Status', 'Work Hours']
      const csv = toCsv(headers, rows, (row, header) => {
        switch (header) {
          case 'Employee': return row.name
          case 'Employee ID': return row.employee_no
          case 'Department': return row.department || ''
          case 'Date': return row.date
          case 'Clock In': return row.clock_in_time || '-'
          case 'Clock Out': return row.clock_out_time || '-'
          case 'Status': return row.status_label
          case 'Work Hours': return row.work_hours || '-'
          default: return row[header]
        }
      })
      downloadCsv(csv, csvFilenameWithDate('attendance_dashboard'))
    } catch (err) {
      // eslint-disable-next-line no-console
      console.error('Export failed:', err)
    } finally {
      setExporting(false)
    }
  }

  const summary = data?.summary
  const context = data?.context
  const departments = data?.departments ?? []
  const trend = data?.trend ?? []
  const employees = data?.employees ?? []
  const pagination = data?.pagination

  const absentCount =
    (summary?.absent ?? 0) +
    ((context?.not_clocked_in_enabled ? summary?.not_clocked_in : 0) || 0)

  const statusOptions = [
    { value: '', label: 'All Statuses' },
    { value: 'PRESENT', label: 'Present' },
    ...(context?.not_clocked_in_enabled ? [{ value: 'NOT_CLOCKED_IN', label: 'Not Clocked In' }] : []),
    { value: 'ABSENT', label: 'Absent' },
    { value: 'ON_LEAVE', label: 'On Leave' },
    { value: 'HOLIDAY', label: 'Holiday' },
    { value: 'NON_WORKING_DAY', label: 'Non-Working Day' },
    { value: 'LATE', label: 'Late' },
    { value: 'MISSING_CLOCK_OUT', label: 'Missing Clock Out' },
    { value: 'AUTO_CLOCKED_OUT', label: 'Auto Clocked Out' },
  ]

  // Horizontal status tabs below the filters. They mirror statusFilter so the
  // tabs, stat cards and the status dropdown always stay in sync.
  const activeStatusTab =
    statusFilter === ''
      ? 'ALL'
      : statusFilter === 'PRESENT'
        ? 'PRESENT'
        : statusFilter === 'ABSENT' || statusFilter === 'NOT_CLOCKED_IN'
          ? 'ABSENT'
          : statusFilter === 'ON_LEAVE'
            ? 'ON_LEAVE'
            : statusFilter // statuses outside the tab set highlight no tab

  const statusTabs = [
    { id: 'ALL', name: 'All Employees' },
    { id: 'PRESENT', name: `Present (${summary?.present ?? 0})` },
    { id: 'ABSENT', name: `Absent (${absentCount})` },
    { id: 'ON_LEAVE', name: `On Leave (${summary?.on_leave ?? 0})` },
  ]

  // Each tab renders ONLY its own content: dedicated columns + heading.
  // ALL (and any statuses outside the tab set) keeps the full daily view.
  const tableColumnsBase = {
    employee: {
      key: 'name',
      label: 'Employee',
      render: (_, row) => (
        <div>
          <button
            type="button"
            onClick={() => openDetail(row)}
            className="font-medium text-primary-600 dark:text-primary-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 rounded"
          >
            {row.name}
          </button>
          <div className="text-xs text-gray-500 dark:text-gray-400">{row.employee_no}</div>
        </div>
      ),
    },
    department: {
      key: 'department',
      label: 'Department',
      render: (value, row) => (
        <div>
          <div>{value || 'Unassigned'}</div>
          {row.section && <div className="text-xs text-gray-500 dark:text-gray-400">{row.section}</div>}
        </div>
      ),
    },
    date: { key: 'date', label: 'Date' },
    clockIn: {
      key: 'clock_in_time',
      label: 'Clock In',
      render: (value, row) =>
        value ? (
          <span className={row.is_late ? 'text-yellow-600 dark:text-yellow-400 font-medium' : ''}>{value}</span>
        ) : (
          <span className="text-gray-400 dark:text-gray-500">—</span>
        ),
    },
    clockOut: {
      key: 'clock_out_time',
      label: 'Clock Out',
      render: (value) => value || <span className="text-gray-400 dark:text-gray-500">—</span>,
    },
    status: {
      key: 'status',
      label: 'Status',
      render: (_, row) => (
        <Badge variant={STATUS_META[row.status]?.variant || 'default'}>{statusLabel(row.status)}</Badge>
      ),
    },
    workHours: {
      key: 'work_hours',
      label: 'Work Hours',
      render: (value) => value || <span className="text-gray-400 dark:text-gray-500">—</span>,
    },
    contact: {
      key: 'contact',
      label: 'Contact',
      render: (_, row) =>
        row.phone || row.email ? (
          <span className="text-sm text-gray-900 dark:text-gray-100">{row.phone || row.email}</span>
        ) : (
          <span className="text-gray-400 dark:text-gray-500">—</span>
        ),
    },
    leaveType: {
      key: 'leave_type',
      label: 'Leave Type',
      render: (value) => value || 'Approved Leave',
    },
    actions: {
      key: 'actions',
      label: 'Actions',
      render: (_, row) => (
        <Button variant="outline" size="sm" onClick={() => openDetail(row)} aria-label={`View details for ${row.name}`}>
          <Eye className="h-4 w-4 mr-1" />
          View
        </Button>
      ),
    },
  }

  const tableColumns =
    activeStatusTab === 'ABSENT'
      ? [
          tableColumnsBase.employee,
          tableColumnsBase.department,
          tableColumnsBase.contact,
          tableColumnsBase.status,
          tableColumnsBase.actions,
        ]
      : activeStatusTab === 'ON_LEAVE'
        ? [
            tableColumnsBase.employee,
            tableColumnsBase.department,
            tableColumnsBase.leaveType,
            tableColumnsBase.actions,
          ]
        : activeStatusTab === 'PRESENT'
          ? [
              tableColumnsBase.employee,
              tableColumnsBase.department,
              tableColumnsBase.clockIn,
              tableColumnsBase.clockOut,
              tableColumnsBase.workHours,
              tableColumnsBase.actions,
            ]
          : [
              // ALL / other statuses: complete daily view
              tableColumnsBase.employee,
              tableColumnsBase.department,
              tableColumnsBase.date,
              tableColumnsBase.clockIn,
              tableColumnsBase.clockOut,
              tableColumnsBase.status,
              tableColumnsBase.workHours,
              tableColumnsBase.actions,
            ]

  const tableMeta =
    activeStatusTab === 'PRESENT'
      ? {
          title: `Present Employees (${summary?.present ?? 0})`,
          subtitle: 'Only employees who have a valid clock-in on this date are shown here.',
        }
      : activeStatusTab === 'ABSENT'
        ? {
            title: `Employees Who Have Not Clocked In (${absentCount})`,
            subtitle:
              context && !context.working_day
                ? 'No attendance is required on this date.'
                : 'Only employees expected to work without a clock-in record are shown here. Policy marks them absent immediately.',
          }
        : activeStatusTab === 'ON_LEAVE'
          ? {
              title: `Employees On Approved Leave (${summary?.on_leave ?? 0})`,
              subtitle: 'Only employees with approved leave covering this date are shown here.',
            }
          : {
              title:
                activeStatusTab === 'ALL'
                  ? 'Daily Employee Attendance'
                  : `${statusLabel(statusFilter)} Employees`,
              subtitle: data ? `${pagination?.total ?? 0} record(s) matching the current filters` : undefined,
            }

  const cards = [
    {
      key: 'total',
      icon: Users,
      label: 'Total Employees',
      value: summary?.total_employees ?? 0,
      sub: 'Active employees in scope',
      accent: 'text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-slate-700',
      active: false,
      onClick: () => setStatusFilter(''),
    },
    {
      key: 'present',
      icon: UserCheck,
      label: 'Present',
      value: summary?.present ?? 0,
      sub: 'Clocked in on this date',
      accent: 'text-green-600 bg-green-50 dark:text-green-400 dark:bg-green-900/30',
      active: statusFilter === 'PRESENT',
      onClick: () => toggleStatusFilter('PRESENT'),
    },
    {
      key: 'absent',
      icon: UserX,
      label: context?.not_clocked_in_enabled ? 'Not Clocked In / Absent' : 'Absent',
      value: absentCount,
      sub: context && !context.working_day ? 'No attendance required' : 'Expected but no clock-in',
      accent: 'text-red-600 bg-red-50 dark:text-red-400 dark:bg-red-900/30',
      active: statusFilter === 'ABSENT' || statusFilter === 'NOT_CLOCKED_IN',
      onClick: () => toggleStatusFilter('ABSENT'),
    },
    {
      key: 'leave',
      icon: CalendarX,
      label: 'On Leave',
      value: summary?.on_leave ?? 0,
      sub: 'Approved leave',
      accent: 'text-blue-600 bg-blue-50 dark:text-blue-400 dark:bg-blue-900/30',
      active: statusFilter === 'ON_LEAVE',
      onClick: () => toggleStatusFilter('ON_LEAVE'),
    },
    {
      key: 'rate',
      icon: Percent,
      label: 'Attendance Rate',
      value: summary == null ? '-' : summary.attendance_rate === null ? '—' : `${summary.attendance_rate}%`,
      sub: `Of ${summary?.expected_to_work ?? 0} expected to work`,
      accent: 'text-amber-600 bg-amber-50 dark:text-amber-400 dark:bg-amber-900/30',
      active: false,
    },
  ]

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Attendance Dashboard</h1>
          <p className="text-gray-500 dark:text-gray-400">
            {formatDisplayDate(date)}
            {lastUpdated && !loading && (
              <span className="ml-2 text-xs text-gray-400">
                · Updated {lastUpdated.toLocaleTimeString('en-KE', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                {refreshing && ' · refreshing…'}
              </span>
            )}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {isTodayView && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setAutoRefresh((v) => !v)}
              title={autoRefresh ? 'Pause auto refresh' : 'Resume auto refresh'}
            >
              {autoRefresh ? <Pause className="h-4 w-4 mr-1" /> : <Play className="h-4 w-4 mr-1" />}
              Auto refresh {autoRefresh ? 'on' : 'off'}
            </Button>
          )}
          <Button variant="outline" size="sm" onClick={() => fetchDashboard({ silent: true })} disabled={refreshing} title="Refresh now">
            <RefreshCw className={`h-4 w-4 mr-1 ${refreshing ? 'animate-spin' : ''}`} />
            Refresh
          </Button>
          <Button size="sm" onClick={exportCsv} loading={exporting} title="Export filtered results to CSV">
            <Download className="h-4 w-4 mr-1" />
            Export CSV
          </Button>
        </div>
      </div>

      {/* Holiday / non-working-day banners */}
      {context?.is_holiday && (
        <div className="flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/20 p-4" role="status">
          <CalendarOff className="h-5 w-5 text-blue-600 dark:text-blue-400 mt-0.5" />
          <div>
            <p className="font-medium text-blue-900 dark:text-blue-200">Public Holiday — {context.holiday_name}</p>
            <p className="text-sm text-blue-700 dark:text-blue-300">{formatDisplayDate(date)}. Attendance tracking is not required.</p>
          </div>
        </div>
      )}
      {!context?.is_holiday && context?.is_non_working_day && (
        <div className="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 dark:border-slate-600 dark:bg-slate-700/40 p-4" role="status">
          <CalendarOff className="h-5 w-5 text-gray-500 dark:text-gray-400 mt-0.5" />
          <div>
            <p className="font-medium text-gray-900 dark:text-gray-200">Non-Working Day</p>
            <p className="text-sm text-gray-600 dark:text-gray-400">This is a weekend. Employees are not expected to clock in and are not marked absent.</p>
          </div>
        </div>
      )}

      {/* Error state */}
      {error && (
        <Card>
          <div className="flex flex-col items-center py-8 text-center">
            <AlertTriangle className="h-10 w-10 text-red-500 mb-3" />
            <p className="font-medium text-gray-900 dark:text-gray-100">Something went wrong</p>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{error}</p>
            <Button className="mt-4" size="sm" onClick={() => fetchDashboard()}>Try again</Button>
          </div>
        </Card>
      )}

      {/* Stat cards */}
      {!error && (
        <div className={`grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 transition-opacity ${refreshing ? 'opacity-70' : ''}`}>
          {cards.map((card) => {
            const Wrapper = card.onClick ? 'button' : 'div'
            return (
              <Wrapper
                key={card.key}
                {...(card.onClick
                  ? {
                      type: 'button',
                      onClick: card.onClick,
                      'aria-pressed': card.active,
                      title: `Filter by ${card.label.toLowerCase()}`,
                    }
                  : {})}
                className={`text-left rounded-xl border shadow-md p-4 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 ${
                  card.active
                    ? 'border-primary-500 ring-2 ring-primary-500'
                    : 'border-primary-600/40 dark:border-slate-700'
                } bg-white dark:bg-slate-800 ${card.onClick ? 'hover:shadow-lg cursor-pointer' : 'cursor-default'}`}
              >
                <span className={`inline-flex h-9 w-9 items-center justify-center rounded-lg ${card.accent}`}>
                  <card.icon className="h-5 w-5" />
                </span>
                {loading ? (
                  <div className="mt-3 space-y-2 animate-pulse">
                    <div className="h-6 w-14 rounded bg-gray-200 dark:bg-slate-700" />
                    <div className="h-3 w-24 rounded bg-gray-100 dark:bg-slate-700/60" />
                  </div>
                ) : (
                  <>
                    <p className="mt-3 text-2xl font-bold text-gray-900 dark:text-gray-100">{card.value}</p>
                    <p className="text-sm font-medium text-gray-700 dark:text-gray-300">{card.label}</p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">{card.sub}</p>
                  </>
                )}
              </Wrapper>
            )
          })}
        </div>
      )}

      {/* Filters */}
      {!error && (
        <Card className="!p-0">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date</label>
              <div className="flex flex-wrap gap-2" role="group" aria-label="Date presets">
                {[
                  { key: 'today', label: 'Today' },
                  { key: 'yesterday', label: 'Yesterday' },
                  { key: 'custom', label: 'Custom' },
                ].map((p) => (
                  <button
                    key={p.key}
                    type="button"
                    aria-pressed={preset === p.key}
                    onClick={() => setPreset(p.key)}
                    className={`px-3 py-1.5 text-sm rounded-md border transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 ${
                      preset === p.key
                        ? 'bg-primary-600 text-white border-primary-600'
                        : 'bg-white dark:bg-slate-900 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700'
                    }`}
                  >
                    {p.label}
                  </button>
                ))}
              </div>
              {preset === 'custom' && (
                <input
                  type="date"
                  value={customDate}
                  max={todayStr()}
                  onChange={(e) => e.target.value && setCustomDate(e.target.value)}
                  className="mt-2 w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md shadow-sm bg-white dark:bg-slate-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                  aria-label="Custom date"
                />
              )}
            </div>

            <Select
              label="Department"
              value={departmentId}
              onChange={(e) => setDepartmentId(e.target.value)}
              options={[
                { value: '', label: 'All Departments' },
                ...departments.map((d) => ({
                  value: String(d.department_id ?? ''),
                  label: d.department,
                })).filter((o) => o.value !== ''),
              ]}
            />

            <Select
              label="Attendance Status"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              options={statusOptions}
            />

            <div>
              <Input
                label="Search Employee"
                type="search"
                placeholder="Name or employee ID…"
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                aria-label="Search employee by name or ID"
              />
              {(searchInput || statusFilter || departmentId) && (
                <button
                  type="button"
                  onClick={() => {
                    setSearchInput('')
                    setStatusFilter('')
                    setDepartmentId('')
                  }}
                  className="mt-1 text-xs text-primary-600 dark:text-primary-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 rounded"
                >
                  Clear all filters
                </button>
              )}
            </div>
          </div>
        </Card>
      )}

      {/* Status tabs (Present / Absent / On Leave) */}
      {!error && (
        <Tabs
          tabs={statusTabs}
          activeTab={activeStatusTab}
          onChange={(tabId) => setStatusFilter(tabId === 'ALL' ? '' : tabId)}
          variant="underline"
          ariaLabel="Filter employees by attendance status"
        />
      )}

      {/* Daily employee attendance (content depends on the active tab) */}
      {!error && (
        <Card title={tableMeta.title} subtitle={tableMeta.subtitle}>
          {loading ? (
            <div className="space-y-3 animate-pulse">
              {[...Array(6)].map((_, i) => (
                <div key={i} className="h-10 rounded bg-gray-100 dark:bg-slate-700/60" />
              ))}
            </div>
          ) : employees.length === 0 ? (
            <div className="py-12 text-center">
              <Users className="mx-auto h-10 w-10 text-gray-300 dark:text-slate-600 mb-3" />
              {(summary?.total_employees ?? 0) === 0 ? (
                <>
                  <p className="font-medium text-gray-900 dark:text-gray-100">No attendance records found for this date.</p>
                  <p className="text-sm text-gray-500 dark:text-gray-400">There are no active employees in the selected scope.</p>
                </>
              ) : activeStatusTab === 'ABSENT' && context && !context.working_day ? (
                <>
                  <p className="font-medium text-gray-900 dark:text-gray-100">No attendance is required on this date.</p>
                  <p className="text-sm text-gray-500 dark:text-gray-400">This is a holiday or non-working day, so nobody is marked absent.</p>
                </>
              ) : (
                <>
                  <p className="font-medium text-gray-900 dark:text-gray-100">
                    No employees match the selected filters.
                  </p>
                  <p className="text-sm text-gray-500 dark:text-gray-400">Adjust or clear the filters to see more results.</p>
                </>
              )}
            </div>
          ) : (
            <>
              <Table
                columns={tableColumns}
                data={employees}
              />

              {/* Pagination */}
              {pagination && pagination.total_pages > 1 && (
                <div className="flex items-center justify-between mt-4 px-2 py-3">
                  <p className="text-sm text-gray-500 dark:text-gray-400">
                    Showing {(pagination.page - 1) * pagination.limit + 1} to{' '}
                    {Math.min(pagination.page * pagination.limit, pagination.total)} of {pagination.total} employees
                  </p>
                  <div className="flex items-center space-x-2">
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                      <ChevronLeft className="h-4 w-4 mr-1" />
                      Previous
                    </Button>
                    <span className="text-sm text-gray-700 dark:text-gray-300">
                      Page {pagination.page} of {pagination.total_pages}
                    </span>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={page >= pagination.total_pages}
                      onClick={() => setPage((p) => p + 1)}
                    >
                      Next
                      <ChevronRight className="h-4 w-4 ml-1" />
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </Card>
      )}

      {/* Department summary + trend */}
      {!error && data && (
        <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <Card title="Department Attendance" subtitle="Click a department to filter the table above">
            {departments.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No departments to display.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                  <thead className="bg-gray-50 dark:bg-slate-900">
                    <tr>
                      {['Department', 'Present', 'Absent', 'On Leave', 'Rate'].map((h) => (
                        <th key={h} className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 dark:divide-slate-700">
                    {departments.map((d) => (
                      <tr
                        key={d.department}
                        onClick={() => d.department_id && setDepartmentId(String(d.department_id))}
                        className={`${
                          d.department_id ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700/50' : ''
                        } ${departmentId && String(departmentId) === String(d.department_id ?? '') ? 'bg-primary-50 dark:bg-slate-700' : ''}`}
                      >
                        <td className="px-4 py-2 text-sm font-medium text-gray-900 dark:text-gray-100">{d.department}</td>
                        <td className="px-4 py-2 text-sm text-green-600 dark:text-green-400">{d.present}</td>
                        <td className="px-4 py-2 text-sm text-red-600 dark:text-red-400">{d.absent}</td>
                        <td className="px-4 py-2 text-sm text-blue-600 dark:text-blue-400">{d.on_leave}</td>
                        <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
                          {d.attendance_rate === null ? '—' : `${d.attendance_rate}%`}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          <Card title="Attendance Trend — Last 7 Days" subtitle="Stacked per-day counts ending at the selected date">
            {trend.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No trend data available.</p>
            ) : (
              <>
                <div className="flex items-end gap-2 h-44 px-1 overflow-x-auto">
                  {trend.map((day) => {
                    const total = day.present + day.absent + day.on_leave
                    const pct = (v) => (total > 0 ? (v / total) * 100 : 0)
                    return (
                      <div key={day.date} className="flex flex-col items-center flex-1 min-w-[44px]">
                        <span className="text-xs font-medium text-gray-600 dark:text-gray-300">{total}</span>
                        <div
                          className="mt-1 w-full flex flex-col-reverse rounded-t-sm overflow-hidden"
                          style={{ height: '120px' }}
                          role="img"
                          aria-label={`${day.label}: ${day.present} present, ${day.absent} absent, ${day.on_leave} on leave${day.is_holiday ? `, holiday (${day.holiday_name})` : ''}${day.is_non_working_day ? ', non-working day' : ''}`}
                          title={`${day.label}\nPresent: ${day.present}\nAbsent: ${day.absent}\nOn Leave: ${day.on_leave}${
                            day.is_holiday ? `\nHoliday: ${day.holiday_name}` : day.is_non_working_day ? '\nNon-working day' : ''
                          }`}
                        >
                          {total === 0 ? (
                            <div className="w-full bg-gray-200 dark:bg-slate-600" style={{ height: '6px' }} />
                          ) : (
                            <>
                              {day.present > 0 && <div className={`w-full ${TREND_COLORS.present}`} style={{ height: `${pct(day.present)}%` }} />}
                              {day.absent > 0 && <div className={`w-full ${TREND_COLORS.absent}`} style={{ height: `${pct(day.absent)}%` }} />}
                              {day.on_leave > 0 && <div className={`w-full ${TREND_COLORS.on_leave}`} style={{ height: `${pct(day.on_leave)}%` }} />}
                            </>
                          )}
                        </div>
                        <span className={`mt-1 text-xs whitespace-nowrap ${day.is_today ? 'font-bold text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'}`}>
                          {day.label}
                        </span>
                        {(day.is_holiday || day.is_non_working_day) && (
                          <span className="text-[10px] text-gray-400 dark:text-gray-500">{day.is_holiday ? 'Holiday' : 'Weekend'}</span>
                        )}
                      </div>
                    )
                  })}
                </div>
                <div className="flex flex-wrap items-center gap-4 mt-3 pt-3 border-t dark:border-slate-700">
                  <span className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><i className="inline-block h-2.5 w-2.5 rounded-sm bg-green-500" /> Present</span>
                  <span className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><i className="inline-block h-2.5 w-2.5 rounded-sm bg-red-500" /> Absent</span>
                  <span className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><i className="inline-block h-2.5 w-2.5 rounded-sm bg-blue-500" /> On Leave</span>
                </div>
              </>
            )}
          </Card>
        </div>
      )}

      {/* Employee detail modal */}
      <Modal isOpen={!!detailEmployee} onClose={closeDetail} title="Employee Attendance Details" size="xl">
        {detailEmployee && (
          <div className="space-y-6">
            {/* Profile */}
            <section>
              <h4 className="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">Employee Profile</h4>
              <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <div><dt className="inline font-medium text-gray-700 dark:text-gray-300">Name: </dt><dd className="inline text-gray-900 dark:text-gray-100">{detailData?.employee?.name || detailEmployee.name}</dd></div>
                <div><dt className="inline font-medium text-gray-700 dark:text-gray-300">Employee ID: </dt><dd className="inline text-gray-900 dark:text-gray-100">{detailData?.employee?.employee_id || detailEmployee.employee_no}</dd></div>
                <div><dt className="inline font-medium text-gray-700 dark:text-gray-300">Department: </dt><dd className="inline text-gray-900 dark:text-gray-100">{detailData?.employee?.department || detailEmployee.department || 'Unassigned'}</dd></div>
                <div><dt className="inline font-medium text-gray-700 dark:text-gray-300">Section: </dt><dd className="inline text-gray-900 dark:text-gray-100">{detailData?.employee?.section || detailEmployee.section || '—'}</dd></div>
                <div><dt className="inline font-medium text-gray-700 dark:text-gray-300">Position: </dt><dd className="inline text-gray-900 dark:text-gray-100">{detailData?.employee?.position || detailEmployee.position || '—'}</dd></div>
                <div><dt className="inline font-medium text-gray-700 dark:text-gray-300">Contact: </dt><dd className="inline text-gray-900 dark:text-gray-100">{detailEmployee.phone || detailEmployee.email || '—'}</dd></div>
              </dl>
            </section>

            {/* Selected date */}
            <section>
              <h4 className="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">
                Attendance for {formatDisplayDate(detailEmployee.date)}
              </h4>
              <div className="rounded-lg border border-gray-200 dark:border-slate-600 p-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Status</p>
                  <Badge variant={STATUS_META[detailEmployee.status]?.variant || 'default'}>{statusLabel(detailEmployee.status)}</Badge>
                </div>
                <div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Clock In</p>
                  <p className="text-gray-900 dark:text-gray-100">{detailEmployee.clock_in_time || '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Clock Out</p>
                  <p className="text-gray-900 dark:text-gray-100">{detailEmployee.clock_out_time || '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Total Hours</p>
                  <p className="text-gray-900 dark:text-gray-100">{detailEmployee.work_hours || '—'}</p>
                </div>
              </div>
            </section>

            {/* History */}
            <section>
              <h4 className="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">
                Attendance History — Last 30 Days
              </h4>
              {detailLoading ? (
                <div className="space-y-2 animate-pulse">
                  {[...Array(5)].map((_, i) => (
                    <div key={i} className="h-8 rounded bg-gray-100 dark:bg-slate-700/60" />
                  ))}
                </div>
              ) : detailError ? (
                <p className="text-sm text-red-600 dark:text-red-400">{detailError}</p>
              ) : (detailData?.history?.length ?? 0) === 0 ? (
                <p className="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No attendance history for the last 30 days.</p>
              ) : (
                <div className="overflow-x-auto max-h-64 rounded-lg border border-gray-200 dark:border-slate-600">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                    <thead className="bg-gray-50 dark:bg-slate-900 sticky top-0">
                      <tr>
                        {['Date', 'Clock In', 'Clock Out', 'Hours', 'Status'].map((h) => (
                          <th key={h} className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            {h}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 dark:divide-slate-700 bg-white dark:bg-slate-800">
                      {detailData.history.map((rec) => (
                        <tr key={rec.id}>
                          <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{rec.date}</td>
                          <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{rec.clock_in_time || '—'}</td>
                          <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{rec.clock_out_time || '—'}</td>
                          <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{rec.work_hours || '—'}</td>
                          <td className="px-4 py-2"><Badge variant={STATUS_META[rec.status]?.variant || 'default'}>{rec.status_label}</Badge></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
          </div>
        )}
      </Modal>
    </div>
  )
}

export default AttendanceDashboard











