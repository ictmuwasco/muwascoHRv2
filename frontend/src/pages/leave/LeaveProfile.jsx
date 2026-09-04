import React, { useState, useEffect, useCallback, useMemo } from 'react'
import { useAuth } from '../../context/AuthContext'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Badge from '../../components/ui/Badge'
import {
  Search,
  Download,
  RefreshCw,
  Building2,
  Briefcase,
  ChevronDown,
  ChevronRight,
  Filter,
  X,
  Clock,
  CheckCircle,
  XCircle,
  FileText,
  AlertTriangle,
  Info,
  Layers,
  ArrowUp,
  TrendingDown,
  Wallet,
  CalendarDays,
} from 'lucide-react'

// ─── Constants ───────────────────────────────────────────────────────



const STATUS_MAP = {
  approved: { label: 'Approved', variant: 'success' },
  rejected: { label: 'Rejected', variant: 'danger' },
  pending: { label: 'Pending', variant: 'warning' },
  pending_subsection_head: { label: 'Pending Sub-Section Head', variant: 'warning' },
  pending_section_head: { label: 'Pending Section Head', variant: 'warning' },
  pending_dept_head: { label: 'Pending Dept Head', variant: 'warning' },
  pending_managing_director: { label: 'Pending MD', variant: 'warning' },
  pending_hr: { label: 'Pending HR', variant: 'warning' },
  pending_bod_chair: { label: 'Pending BOD Chair', variant: 'warning' },
  pending_manager: { label: 'Pending Manager', variant: 'warning' },
  cancelled: { label: 'Cancelled', variant: 'default' },
  invalidated: { label: 'Invalidated', variant: 'default' },
}

const MOVEMENT_TYPE_MAP = {
  DEDUCT: { label: 'Deduct', variant: 'danger' },
  CREDIT: { label: 'Credit', variant: 'success' },
  UNPAID: { label: 'Unpaid', variant: 'warning' },
  PENDING: { label: 'Pending', variant: 'warning' },
  INFO: { label: 'Info', variant: 'default' },
}

// ─── Helper functions ────────────────────────────────────────────────

const fmtDate = (d) => {
  if (!d) return '—'
  try {
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
  } catch {
    return d
  }
}

const fmtDays = (d) => {
  const num = Number(d) || 0
  return Number.isInteger(num) ? String(num) : num.toFixed(1)
}

const getStatusInfo = (status) => STATUS_MAP[status] || { label: status || 'Unknown', variant: 'default' }

const getEmployeeName = (emp) => {
  if (!emp) return ''
  return [emp.first_name, emp.last_name, emp.surname].filter(Boolean).join(' ')
}

const getInitials = (emp) => {
  if (!emp) return '?'
  const f = (emp.first_name || '')[0] || ''
  const l = (emp.last_name || '')[0] || ''
  return (f + l).toUpperCase() || '?'
}

// ─── Main Component ──────────────────────────────────────────────────

const LeaveProfile = () => {
  const { user, can } = useAuth()
  // Permission-driven visibility: only users with leave:manage can browse
  // other employees' leave profiles (data scope enforced server-side by
  // OrgScope). Officers/employees see their own record only.
  const canViewAll = can('leave', 'manage')

  // ── State ──────────────────────────────────────────────────────────
  const [employees, setEmployees] = useState([])
  const [selectedEmployeeId, setSelectedEmployeeId] = useState('')
  const [selectedEmployee, setSelectedEmployee] = useState(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [showEmployeeDropdown, setShowEmployeeDropdown] = useState(false)

  const [financialYears, setFinancialYears] = useState([])
  const [selectedFyId, setSelectedFyId] = useState('')

  const [profile, setProfile] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  // Filters
  const [filterStatus, setFilterStatus] = useState('')
  const [filterLeaveType, setFilterLeaveType] = useState('')
  const [filterDateFrom, setFilterDateFrom] = useState('')
  const [filterDateTo, setFilterDateTo] = useState('')

  // Expanded application rows
  const [expandedApps, setExpandedApps] = useState(new Set())

  // ── Load employees on mount ────────────────────────────────────────
  useEffect(() => {
    loadEmployees()
  }, [])

  const loadEmployees = async () => {
    try {
      const response = await api.get('/leave/profile/employees')
      const list = response.data?.data || []
      setEmployees(list)

      // Auto-select the current user's employee record.
      // For ordinary employees, the backend already restricts the list
      // to only their own record, so we can safely select the first one.
      // For HR/Admin/MD, try to match by employee_id, then fall back to first.
      if (list.length > 0) {
        let currentUserEmp = null

        // Try to match by employee_id (string like "EMP00125" or numeric)
        if (user?.employee_id) {
          currentUserEmp = list.find((e) => String(e.employee_id) === String(user.employee_id))
        }

        // If no match found, select the first employee (for non-HR users
        // this is always their own record since the backend restricts it)
        if (!currentUserEmp) {
          currentUserEmp = list[0]
        }

        if (currentUserEmp) {
          setSelectedEmployeeId(String(currentUserEmp.id))
          setSelectedEmployee(currentUserEmp)
        }
      }
    } catch (err) {
      console.error('Failed to load employees:', err)
      setError('Failed to load employee list')
    }
  }

  // ── Load financial years ───────────────────────────────────────────
  useEffect(() => {
    loadFinancialYears()
  }, [])

  const loadFinancialYears = async () => {
    try {
      const fyResponse = await api.get('/leave/roster/financial-years')
      const years = fyResponse.data?.data || []
      setFinancialYears(years)
      if (years.length > 0 && !selectedFyId) {
        setSelectedFyId(String(years[0]?.id || years[0]))
      }
    } catch (err) {
      console.error('Failed to load financial years:', err)
    }
  }

  // ── Load profile when employee or FY changes ───────────────────────
  useEffect(() => {
    if (selectedEmployeeId && selectedFyId) {
      loadProfile()
    }
  }, [selectedEmployeeId, selectedFyId])

  const loadProfile = useCallback(async () => {
    if (!selectedEmployeeId) return
    setLoading(true)
    setError('')
    try {
      const params = { financial_year_id: selectedFyId }
      if (filterStatus) params.status = filterStatus
      if (filterLeaveType) params.leave_type_id = filterLeaveType
      if (filterDateFrom) params.date_from = filterDateFrom
      if (filterDateTo) params.date_to = filterDateTo

      const response = await api.get(`/leave/profile/${selectedEmployeeId}`, { params })
      const data = response.data?.data
      if (data?.success === false) {
        setError(data.message || 'Failed to load profile')
        setProfile(null)
      } else {
        setProfile(data)
        if (data?.employee) setSelectedEmployee(data.employee)
        if (data?.financial_years?.length > 0) setFinancialYears(data.financial_years)
      }
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to load leave profile'
      setError(msg)
      setProfile(null)
    } finally {
      setLoading(false)
    }
  }, [selectedEmployeeId, selectedFyId, filterStatus, filterLeaveType, filterDateFrom, filterDateTo])

  // ── Employee search filtering ──────────────────────────────────────
  const filteredEmployees = useMemo(() => {
    if (!searchTerm.trim()) return employees
    const q = searchTerm.toLowerCase()
    return employees.filter((e) => {
      return (
        (e.employee_id || '').toLowerCase().includes(q) ||
        (e.first_name || '').toLowerCase().includes(q) ||
        (e.last_name || '').toLowerCase().includes(q) ||
        (e.surname || '').toLowerCase().includes(q) ||
        (e.department_name || '').toLowerCase().includes(q) ||
        (e.section_name || '').toLowerCase().includes(q) ||
        (e.subsection_name || '').toLowerCase().includes(q) ||
        (e.designation || '').toLowerCase().includes(q)
      )
    })
  }, [employees, searchTerm])

  // ── Handlers ───────────────────────────────────────────────────────

  const handleEmployeeSelect = (emp) => {
    setSelectedEmployeeId(String(emp.id))
    setSelectedEmployee(emp)
    setSearchTerm('')
    setShowEmployeeDropdown(false)
    setExpandedApps(new Set())
  }

  const handleResetFilters = () => {
    setFilterStatus('')
    setFilterLeaveType('')
    setFilterDateFrom('')
    setFilterDateTo('')
  }

  const handleExport = async () => {
    if (!selectedEmployeeId) return
    try {
      const params = { financial_year_id: selectedFyId }
      if (filterStatus) params.status = filterStatus
      if (filterLeaveType) params.leave_type_id = filterLeaveType
      if (filterDateFrom) params.date_from = filterDateFrom
      if (filterDateTo) params.date_to = filterDateTo

      const response = await api.get(`/leave/profile/${selectedEmployeeId}/export`, {
        params: { ...params, format: 'csv' },
        responseType: 'blob',
      })
      const url = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      const empId = selectedEmployee?.employee_id || selectedEmployeeId
      const fyName = financialYears.find((y) => String(y.id) === String(selectedFyId))?.year_name || 'FY'
      link.href = url
      link.setAttribute('download', `leave_account_${empId}_${fyName}.csv`)
      document.body.appendChild(link)
      link.click()
      link.remove()
    } catch (err) {
      console.error('Export failed:', err)
      setError('Failed to export leave account')
    }
  }

  const toggleAppExpand = (appId) => {
    setExpandedApps((prev) => {
      const next = new Set(prev)
      if (next.has(appId)) next.delete(appId)
      else next.add(appId)
      return next
    })
  }

  // ── Derived data ───────────────────────────────────────────────────

  const summary = profile?.summary || null
  const balances = profile?.balances || []
  const applications = profile?.applications || []
  const timeline = profile?.timeline || null
  const movements = timeline?.movements || []
  const closingBalances = timeline?.closing_balances || {}
  const leaveTypes = profile?.leave_types || []

  // Build a lookup of movements by application ID
  const movementsByApp = useMemo(() => {
    const map = {}
    movements.forEach((m) => {
      const appId = m.application_id
      if (!map[appId]) map[appId] = []
      map[appId].push(m)
    })
    return map
  }, [movements])

  // Build balance cards from balances + timeline closing balances
  const balanceCards = useMemo(() => {
    return balances.map((b) => {
      const typeId = b.leave_type_id
      const closing = closingBalances[typeId] || null
      const allocated = Number(b.allocated_days) || 0
      const broughtForward = Number(b.brought_forward_days) || 0
      const totalAvailable = allocated + broughtForward
      const used = closing ? Number(closing.used) || 0 : Number(b.used_days) || 0
      const remaining = closing ? Number(closing.remaining) || 0 : Number(b.remaining_days) || 0
      const pct = totalAvailable > 0 ? Math.min(100, (used / totalAvailable) * 100) : 0
      return { ...b, allocated, broughtForward, totalAvailable, used, remaining, pct }
    })
  }, [balances, closingBalances])

  // ── Render helpers ─────────────────────────────────────────────────

  const renderStatusBadge = (status) => {
    const info = getStatusInfo(status)
    return <Badge variant={info.variant}>{info.label}</Badge>
  }

  const renderMovementBadge = (type) => {
    const info = MOVEMENT_TYPE_MAP[type] || { label: type, variant: 'default' }
    return <Badge variant={info.variant}>{info.label}</Badge>
  }

  const renderBalanceMovement = (movement) => {
    const isCredit = movement.movement_type === 'CREDIT'
    const isDeduct = movement.movement_type === 'DEDUCT'
    const isUnpaid = movement.movement_type === 'UNPAID'
    const sign = isCredit ? '+' : isDeduct ? '−' : isUnpaid ? '±' : ''
    const color = isCredit
      ? 'text-green-600 dark:text-green-400'
      : isDeduct
        ? 'text-red-600 dark:text-red-400'
        : isUnpaid
          ? 'text-amber-600 dark:text-amber-400'
          : 'text-gray-500 dark:text-gray-400'

    return (
      <div className="flex items-center space-x-3 py-2 border-b border-gray-100 dark:border-slate-700 last:border-0">
        <div className="flex-1 min-w-0">
          <div className="flex items-center space-x-2">
            <span className="text-sm font-medium text-gray-900 dark:text-gray-100">
              {movement.leave_type_name || 'Leave'}
            </span>
            {renderMovementBadge(movement.movement_type)}
            {movement.is_legacy && (
              <Badge variant="default" className="text-[10px]">Legacy</Badge>
            )}
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            {movement.explanation || ''}
          </p>
        </div>
        <div className="text-right flex-shrink-0">
          <div className={`font-mono font-semibold ${color}`}>
            {sign}{fmtDays(movement.days)}
          </div>
          <div className="text-xs text-gray-500 dark:text-gray-400">
            {fmtDays(movement.balance_before)} → {fmtDays(movement.balance_after)}
          </div>
        </div>
      </div>
    )
  }

  // ── Loading state ──────────────────────────────────────────────────

  if (loading && !profile) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* ── Header ─────────────────────────────────────────────────── */}
      <div className="flex items-center justify-between flex-wrap gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">
            Employee Leave Profile
          </h1>
          <p className="text-gray-500 dark:text-gray-400">
            Leave account, balances and application history
          </p>
        </div>
        <div className="flex items-center space-x-3">
          <Button variant="outline" size="sm" onClick={handleExport} disabled={!selectedEmployeeId}>
            <Download className="h-4 w-4 mr-1" />
            Export CSV
          </Button>
          <Button variant="outline" size="sm" onClick={loadProfile} disabled={!selectedEmployeeId}>
            <RefreshCw className="h-4 w-4 mr-1" />
            Refresh
          </Button>
        </div>
      </div>

      {/* ── Error ──────────────────────────────────────────────────── */}
      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg flex items-start space-x-2">
          <AlertTriangle className="h-5 w-5 flex-shrink-0 mt-0.5" />
          <span>{error}</span>
        </div>
      )}

      {/* ── Employee Selector + FY Selector ────────────────────────── */}
      <Card>
        <div className={`grid grid-cols-1 gap-4 ${canViewAll ? 'md:grid-cols-3' : 'md:grid-cols-1'}`}>
          {/* Employee search - only for HR/Admin/MD */}
          {canViewAll && (
          <div className="md:col-span-2 relative">
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Select Employee
            </label>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
              <input
                type="text"
                value={searchTerm}
                onChange={(e) => {
                  setSearchTerm(e.target.value)
                  setShowEmployeeDropdown(true)
                }}
                onFocus={() => setShowEmployeeDropdown(true)}
                onBlur={() => setTimeout(() => setShowEmployeeDropdown(false), 200)}
                placeholder="Search by ID, name, department, section, designation..."
                className="w-full pl-10 pr-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-slate-900 dark:text-gray-100 border-gray-300 dark:border-slate-600"
              />
              {showEmployeeDropdown && filteredEmployees.length > 0 && (
                <div className="absolute z-20 mt-1 w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-md shadow-lg max-h-64 overflow-y-auto">
                  {filteredEmployees.map((emp) => (
                    <button
                      key={emp.id}
                      type="button"
                      onClick={() => handleEmployeeSelect(emp)}
                      className={`w-full text-left px-4 py-2 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors ${
                        String(emp.id) === String(selectedEmployeeId)
                          ? 'bg-primary-50 dark:bg-primary-900/20'
                          : ''
                      }`}
                    >
                      <div className="flex items-center space-x-3">
                        <div className="h-8 w-8 rounded-full bg-primary-100 dark:bg-primary-900/40 flex items-center justify-center flex-shrink-0">
                          <span className="text-xs font-semibold text-primary-700 dark:text-primary-300">
                            {getInitials(emp)}
                          </span>
                        </div>
                        <div className="min-w-0">
                          <div className="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                            {getEmployeeName(emp)}
                          </div>
                          <div className="text-xs text-gray-500 dark:text-gray-400 truncate">
                            {emp.employee_id} · {emp.department_name || 'No Dept'}
                            {emp.designation ? ` · ${emp.designation}` : ''}
                          </div>
                        </div>
                      </div>
                    </button>
                  ))}
                </div>
              )}
              {showEmployeeDropdown && filteredEmployees.length === 0 && (
                <div className="absolute z-20 mt-1 w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-md shadow-lg p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                  No employees found
                </div>
              )}
            </div>
          </div>
          )}

          {/* Financial Year - always visible */}
          <div className={canViewAll ? '' : 'md:max-w-md'}>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Financial Year
            </label>
            <select
              value={selectedFyId}
              onChange={(e) => setSelectedFyId(e.target.value)}
              className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-slate-900 dark:text-gray-100 border-gray-300 dark:border-slate-600"
            >
              {financialYears.map((fy) => (
                <option key={fy.id} value={String(fy.id)}>
                  {fy.year_name}
                </option>
              ))}
            </select>
          </div>
        </div>
      </Card>

      {/* ── Employee Identity ──────────────────────────────────────── */}
      {selectedEmployee && (
        <Card>
          <div className="flex items-start space-x-4 flex-wrap">
            <div className="h-16 w-16 rounded-full bg-primary-600 flex items-center justify-center flex-shrink-0">
              <span className="text-2xl font-bold text-white">
                {getInitials(selectedEmployee)}
              </span>
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center space-x-2 flex-wrap">
                <h2 className="text-xl font-bold text-gray-900 dark:text-gray-100">
                  {getEmployeeName(selectedEmployee)}
                </h2>
                <Badge variant="primary">
                  {selectedEmployee.employee_id}
                </Badge>
                {selectedEmployee.employment_type && (
                  <Badge variant="default">
                    {selectedEmployee.employment_type}
                  </Badge>
                )}
              </div>
              <div className="flex items-center space-x-4 mt-2 flex-wrap gap-y-1">
                {selectedEmployee.department_name && (
                  <span className="flex items-center text-sm text-gray-600 dark:text-gray-300">
                    <Building2 className="h-4 w-4 mr-1 text-gray-400" />
                    {selectedEmployee.department_name}
                  </span>
                )}
                {selectedEmployee.section_name && (
                  <span className="flex items-center text-sm text-gray-600 dark:text-gray-300">
                    <Layers className="h-4 w-4 mr-1 text-gray-400" />
                    {selectedEmployee.section_name}
                  </span>
                )}
                {selectedEmployee.subsection_name && (
                  <span className="flex items-center text-sm text-gray-600 dark:text-gray-300">
                    <Layers className="h-4 w-4 mr-1 text-gray-400" />
                    {selectedEmployee.subsection_name}
                  </span>
                )}
                {selectedEmployee.designation && selectedEmployee.designation !== '0' && (
                  <span className="flex items-center text-sm text-gray-600 dark:text-gray-300">
                    <Briefcase className="h-4 w-4 mr-1 text-gray-400" />
                    {selectedEmployee.designation}
                  </span>
                )}
              </div>
            </div>
            {profile?.selected_fy && (
              <Badge variant="primary" className="flex-shrink-0">
                FY {profile.selected_fy.year_name}
              </Badge>
            )}
          </div>
        </Card>
      )}

      {/* ── Summary Statistics ─────────────────────────────────────── */}
      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
          <Card className="p-4">
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                <Layers className="h-5 w-5 text-blue-600 dark:text-blue-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                  {summary.total_leave_types}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">Leave Types</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <CalendarDays className="h-5 w-5 text-green-600 dark:text-green-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                  {fmtDays(summary.total_allocated_days)}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">Allocated</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center">
                <ArrowUp className="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                  {fmtDays(summary.total_brought_forward)}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">Brought Forward</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                <TrendingDown className="h-5 w-5 text-red-600 dark:text-red-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                  {fmtDays(summary.total_used_days)}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">Used</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                <Wallet className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                  {fmtDays(summary.total_remaining_days)}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">Remaining</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                <Clock className="h-5 w-5 text-amber-600 dark:text-amber-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                  {summary.pending_applications}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">Pending</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <CheckCircle className="h-5 w-5 text-green-600 dark:text-green-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-green-600 dark:text-green-400">
                  {summary.approved_applications}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">Approved</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                <XCircle className="h-5 w-5 text-red-600 dark:text-red-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-red-600 dark:text-red-400">
                  {summary.rejected_applications}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">Rejected</p>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* ── Leave Balances ─────────────────────────────────────────── */}
      {balanceCards.length > 0 && (
        <Card title="Leave Balances" subtitle="Allocated, brought forward, used and remaining days per leave type">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {balanceCards.map((b) => (
              <div key={b.leave_type_id} className="border border-gray-200 dark:border-slate-700 rounded-lg p-4">
                <div className="flex items-center justify-between mb-2">
                  <h4 className="font-semibold text-gray-900 dark:text-gray-100">{b.leave_type_name}</h4>
                  <Badge variant={b.remaining > 0 ? 'success' : b.remaining < 0 ? 'danger' : 'default'}>
                    {fmtDays(b.remaining)} days
                  </Badge>
                </div>
                <div className="grid grid-cols-2 gap-2 text-sm mb-3">
                  <div>
                    <span className="text-gray-500 dark:text-gray-400">Allocated:</span>
                    <span className="font-medium text-gray-900 dark:text-gray-100 ml-1">{fmtDays(b.allocated)}</span>
                  </div>
                  <div>
                    <span className="text-gray-500 dark:text-gray-400">Brought Fwd:</span>
                    <span className="font-medium text-gray-900 dark:text-gray-100 ml-1">{fmtDays(b.broughtForward)}</span>
                  </div>
                  <div>
                    <span className="text-gray-500 dark:text-gray-400">Total Avail:</span>
                    <span className="font-medium text-gray-900 dark:text-gray-100 ml-1">{fmtDays(b.totalAvailable)}</span>
                  </div>
                  <div>
                    <span className="text-gray-500 dark:text-gray-400">Used:</span>
                    <span className="font-medium text-gray-900 dark:text-gray-100 ml-1">{fmtDays(b.used)}</span>
                  </div>
                </div>
                <div className="flex items-center space-x-2">
                  <div className="flex-1 h-2 bg-gray-200 dark:bg-slate-700 rounded-full overflow-hidden">
                    <div
                      className={`h-full rounded-full ${
                        b.pct > 80 ? 'bg-red-500' : b.pct > 50 ? 'bg-amber-500' : 'bg-green-500'
                      }`}
                      style={{ width: `${b.pct}%` }}
                    />
                  </div>
                  <span className="text-xs text-gray-500 dark:text-gray-400 font-medium">
                    {Math.round(b.pct)}%
                  </span>
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}

      {/* ── Leave Application Ledger ───────────────────────────────── */}
      <Card>
        <div className="flex items-center justify-between mb-4 flex-wrap gap-3">
          <div>
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Leave Application Ledger</h3>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {applications.length} application{applications.length !== 1 ? 's' : ''} found
            </p>
          </div>
          <div className="flex items-center space-x-2">
            <Button variant="outline" size="sm" onClick={handleResetFilters}>
              <X className="h-4 w-4 mr-1" />
              Reset Filters
            </Button>
          </div>
        </div>

        {/* Filters - data scoped server-side; only leave:manage users get the
            full filter set. Officers/employees see their own record only. */}
        {canViewAll && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
          <div>
            <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-slate-900 dark:text-gray-100 border-gray-300 dark:border-slate-600 text-sm"
            >
              <option value="">All Statuses</option>
              {Object.entries(STATUS_MAP).map(([key, val]) => (
                <option key={key} value={key}>{val.label}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Leave Type</label>
            <select
              value={filterLeaveType}
              onChange={(e) => setFilterLeaveType(e.target.value)}
              className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-slate-900 dark:text-gray-100 border-gray-300 dark:border-slate-600 text-sm"
            >
              <option value="">All Leave Types</option>
              {leaveTypes.map((lt) => (
                <option key={lt.id} value={String(lt.id)}>{lt.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Date From</label>
            <input
              type="date"
              value={filterDateFrom}
              onChange={(e) => setFilterDateFrom(e.target.value)}
              className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-slate-900 dark:text-gray-100 border-gray-300 dark:border-slate-600 text-sm"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Date To</label>
            <input
              type="date"
              value={filterDateTo}
              onChange={(e) => setFilterDateTo(e.target.value)}
              className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-slate-900 dark:text-gray-100 border-gray-300 dark:border-slate-600 text-sm"
            />
          </div>
          <div className="flex items-end">
            <Button size="sm" onClick={loadProfile} className="w-full">
              <Filter className="h-4 w-4 mr-1" />
              Apply Filters
            </Button>
          </div>
        </div>
        )}

        {/* Applications table */}
        {applications.length === 0 ? (
          <div className="text-center py-12">
            <FileText className="h-12 w-12 text-gray-300 dark:text-gray-600 mx-auto mb-3" />
            <p className="text-gray-500 dark:text-gray-400">
              No leave applications found for this employee in the selected financial year.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
              <thead className="bg-gray-50 dark:bg-slate-900">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">ID</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Leave Type</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Dates</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Days</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Balance Impact</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Applied</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Applied By</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"></th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                {applications.map((app) => {
                  const isExpanded = expandedApps.has(app.id)
                  const appMovements = movementsByApp[app.id] || []
                  const hasMovements = appMovements.length > 0
                  const primaryDays = Number(app.primary_days) || 0
                  const annualDays = Number(app.annual_days) || 0
                  const unpaidDays = Number(app.unpaid_days) || 0
                  const isApproved = app.status === 'approved'
                  const isPending = app.status?.startsWith('pending') || app.status === 'pending'
                  const isRejected = app.status === 'rejected'

                  const impactParts = []
                  if (isApproved) {
                    if (Number(app.leave_type_id) === 9) {
                      impactParts.push(`+${fmtDays(app.days_requested)} Annual`)
                    } else if (Number(app.leave_type_id) === 8) {
                      impactParts.push('No deduction')
                    } else {
                      if (primaryDays > 0) impactParts.push(`−${fmtDays(primaryDays)} ${app.leave_type_name || 'primary'}`)
                      if (annualDays > 0) impactParts.push(`−${fmtDays(annualDays)} Annual`)
                      if (unpaidDays > 0) impactParts.push(`${fmtDays(unpaidDays)} unpaid`)
                    }
                  } else if (isPending) {
                    impactParts.push('Pending — no deduction')
                  } else if (isRejected) {
                    impactParts.push('Rejected — no deduction')
                  } else {
                    impactParts.push('No impact')
                  }

                  return (
                    <React.Fragment key={app.id}>
                      <tr className="hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer" onClick={() => toggleAppExpand(app.id)}>
                        <td className="px-4 py-3 whitespace-nowrap text-sm font-mono text-gray-500 dark:text-gray-400">#{app.id}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{app.leave_type_name || 'Unknown'}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                          {fmtDate(app.start_date)} → {fmtDate(app.end_date)}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm font-mono text-gray-900 dark:text-gray-100">{fmtDays(app.days_requested)}</td>
                        <td className="px-4 py-3 whitespace-nowrap">{renderStatusBadge(app.status)}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">{impactParts.join(', ') || '—'}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{fmtDate(app.applied_at)}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                          {[app.user_first_name, app.user_last_name].filter(Boolean).join(' ') || '—'}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap text-right">
                          {hasMovements && (
                            <button
                              type="button"
                              className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                              onClick={(e) => {
                                e.stopPropagation()
                                toggleAppExpand(app.id)
                              }}
                            >
                              {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                            </button>
                          )}
                        </td>
                      </tr>
                      {isExpanded && (
                        <tr className="bg-gray-50 dark:bg-slate-900/50">
                          <td colSpan={9} className="px-4 py-4">
                            <div className="space-y-4">
                              <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                <div>
                                  <span className="text-gray-500 dark:text-gray-400 block text-xs">Application ID</span>
                                  <span className="font-medium text-gray-900 dark:text-gray-100">#{app.id}</span>
                                </div>
                                <div>
                                  <span className="text-gray-500 dark:text-gray-400 block text-xs">Leave Type</span>
                                  <span className="font-medium text-gray-900 dark:text-gray-100">{app.leave_type_name}</span>
                                </div>
                                <div>
                                  <span className="text-gray-500 dark:text-gray-400 block text-xs">Days Requested</span>
                                  <span className="font-medium text-gray-900 dark:text-gray-100">{fmtDays(app.days_requested)} days</span>
                                </div>
                                <div>
                                  <span className="text-gray-500 dark:text-gray-400 block text-xs">Status</span>
                                  <span>{renderStatusBadge(app.status)}</span>
                                </div>
                                <div>
                                  <span className="text-gray-500 dark:text-gray-400 block text-xs">Start Date</span>
                                  <span className="font-medium text-gray-900 dark:text-gray-100">{fmtDate(app.start_date)}</span>
                                </div>
                                <div>
                                  <span className="text-gray-500 dark:text-gray-400 block text-xs">End Date</span>
                                  <span className="font-medium text-gray-900 dark:text-gray-100">{fmtDate(app.end_date)}</span>
                                </div>
                                <div>
                                  <span className="text-gray-500 dark:text-gray-400 block text-xs">Applied At</span>
                                  <span className="font-medium text-gray-900 dark:text-gray-100">{fmtDate(app.applied_at)}</span>
                                </div>
                                <div>
                                  <span className="text-gray-500 dark:text-gray-400 block text-xs">Approved At</span>
                                  <span className="font-medium text-gray-900 dark:text-gray-100">{fmtDate(app.approved_at)}</span>
                                </div>
                              </div>

                              {app.reason && app.reason !== '0' && (
                                <div>
                                  <span className="text-gray-500 dark:text-gray-400 block text-xs mb-1">Reason</span>
                                  <p className="text-sm text-gray-700 dark:text-gray-300 italic">"{app.reason}"</p>
                                </div>
                              )}

                              {app.status === 'rejected' && app.rejection_reason && (
                                <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-3">
                                  <span className="text-red-600 dark:text-red-400 block text-xs mb-1">Rejection Reason</span>
                                  <p className="text-sm text-red-700 dark:text-red-300 italic">"{app.rejection_reason}"</p>
                                </div>
                              )}

                              {hasMovements && (
                                <div>
                                  <h5 className="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Balance Movements</h5>
                                  <div className="border border-gray-200 dark:border-slate-700 rounded-md divide-y divide-gray-200 dark:divide-slate-700">
                                    {appMovements.map((m, idx) => (
                                      <div key={idx} className="p-3">{renderBalanceMovement(m)}</div>
                                    ))}
                                  </div>
                                </div>
                              )}

                              {isPending && (
                                <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-md p-3 text-sm text-amber-700 dark:text-amber-300">
                                  <Clock className="h-4 w-4 inline mr-1" />
                                  Pending approval — balances will be deducted once fully approved.
                                </div>
                              )}
                              {isRejected && (
                                <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-3 text-sm text-red-700 dark:text-red-300">
                                  <XCircle className="h-4 w-4 inline mr-1" />
                                  Rejected — no balance was deducted.
                                </div>
                              )}
                            </div>
                          </td>
                        </tr>
                      )}
                    </React.Fragment>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

    </div>
  )
}

export default LeaveProfile
