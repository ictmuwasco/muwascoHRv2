import { useState, useEffect, useCallback } from 'react'
import { Users, Download, Plus, RefreshCw, CalendarRange, LayoutGrid, List } from 'lucide-react'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Badge from '../../components/ui/Badge'
import LeaveInfoBanner from '../../components/leave/LeaveInfoBanner'
import CoverageBar from '../../components/leave/CoverageBar'
import MonthPills from '../../components/leave/MonthPills'
import PlanningMatrix from '../../components/leave/PlanningMatrix'
import ScheduleSlideOver from '../../components/leave/ScheduleSlideOver'
import EmployeeRosterTable from '../../components/leave/EmployeeRosterTable'
import FilterBar from '../../components/leave/FilterBar'
import { FY_MONTHS } from '../../constants/leaveConstants'

const LeaveRoster = () => {

  // Data state
  const [rosterEntries, setRosterEntries] = useState([])
  const [financialYears, setFinancialYears] = useState([])
  const [departments, setDepartments] = useState([])
  const [sections, setSections] = useState([])
  const [stats, setStats] = useState(null)
  const [distribution, setDistribution] = useState({ distribution: [], highest: null, lowest: null })
  const [upcoming, setUpcoming] = useState(null)
  const [matrixData, setMatrixData] = useState(null)

  // UI state
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [selectedFinancialYear, setSelectedFinancialYear] = useState('')
  const [selectedDepartment, setSelectedDepartment] = useState('')
  const [selectedSection, setSelectedSection] = useState('')
  const [selectedMonth, setSelectedMonth] = useState('')
  const [selectedStatus, setSelectedStatus] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [viewMode, setViewMode] = useState('matrix')
  const [showScheduleModal, setShowScheduleModal] = useState(false)
  const [selectedEmployeeForSchedule, setSelectedEmployeeForSchedule] = useState(null)
  const [pagination, setPagination] = useState({ total: 0, per_page: 20, current_page: 1, last_page: 1 })
  const [actionLoading, setActionLoading] = useState(null)

  // ─── Data Loading ───────────────────────────────────────────────

  const loadFinancialYears = useCallback(async () => {
    try {
      const response = await api.get('/leave/roster/financial-years')
      const years = response.data?.data || []
      setFinancialYears(years)
      if (years.length > 0 && !selectedFinancialYear) {
        setSelectedFinancialYear(years[0]?.id || years[0])
      }
    } catch (err) {
      console.error('Failed to load financial years:', err)
    }
  }, [selectedFinancialYear])

  const loadDepartments = useCallback(async () => {
    try {
      const response = await api.get('/leave/roster/departments')
      setDepartments(response.data?.data || [])
    } catch (err) {
      console.error('Failed to load departments:', err)
    }
  }, [])

  const loadSections = useCallback(async () => {
    try {
      const response = await api.get('/sections')
      setSections(response.data?.data || [])
    } catch (err) {
      console.error('Failed to load sections:', err)
    }
  }, [])

  const loadStats = useCallback(async () => {
    try {
      const params = {}
      if (selectedFinancialYear) params.financial_year_id = selectedFinancialYear
      if (selectedDepartment) params.department_id = selectedDepartment
      if (selectedSection) params.section_id = selectedSection
      const response = await api.get('/leave/roster/stats', { params })
      setStats(response.data?.data || null)
    } catch (err) {
      console.error('Failed to load stats:', err)
    }
  }, [selectedFinancialYear, selectedDepartment, selectedSection])

  const loadDistribution = useCallback(async () => {
    try {
      const params = {}
      if (selectedFinancialYear) params.financial_year_id = selectedFinancialYear
      if (selectedDepartment) params.department_id = selectedDepartment
      if (selectedSection) params.section_id = selectedSection
      const response = await api.get('/leave/roster/distribution', { params })
      setDistribution({
        distribution: response.data?.data?.distribution || [],
        highest: response.data?.data?.highest || null,
        lowest: response.data?.data?.lowest || null,
      })
    } catch (err) {
      console.error('Failed to load distribution:', err)
    }
  }, [selectedFinancialYear, selectedDepartment, selectedSection])

  const loadUpcoming = useCallback(async () => {
    try {
      const params = {}
      if (selectedFinancialYear) params.financial_year_id = selectedFinancialYear
      if (selectedDepartment) params.department_id = selectedDepartment
      if (selectedSection) params.section_id = selectedSection
      const response = await api.get('/leave/roster/upcoming', { params })
      setUpcoming(response.data?.data || null)
    } catch (err) {
      console.error('Failed to load upcoming:', err)
    }
  }, [selectedFinancialYear, selectedDepartment, selectedSection])

  const loadMatrix = useCallback(async () => {
    try {
      const params = {}
      if (selectedFinancialYear) params.financial_year_id = selectedFinancialYear
      if (selectedDepartment) params.department_id = selectedDepartment
      if (selectedSection) params.section_id = selectedSection
      const response = await api.get('/leave/roster/matrix', { params })
      setMatrixData(response.data?.data || null)
    } catch (err) {
      console.error('Failed to load matrix:', err)
    }
  }, [selectedFinancialYear, selectedDepartment, selectedSection])

  const loadRoster = useCallback(async (page = 1) => {
    if (!selectedFinancialYear) return
    setLoading(true)
    setError('')
    try {
      const params = { page, per_page: pagination.per_page }
      if (selectedDepartment) params.department_id = selectedDepartment
      if (selectedSection) params.section_id = selectedSection
      if (selectedMonth) params.month = selectedMonth
      if (selectedStatus) params.status = selectedStatus
      if (searchTerm) params.search = searchTerm
      const response = await api.get('/leave/roster', { params })
      setRosterEntries(response.data?.data || [])
      setPagination({
        total: response.data?.total || 0,
        per_page: response.data?.per_page || 20,
        current_page: response.data?.current_page || 1,
        last_page: response.data?.last_page || 1,
      })
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to load roster'
      setError(msg)
    } finally {
      setLoading(false)
    }
  }, [selectedFinancialYear, selectedDepartment, selectedSection, selectedMonth, selectedStatus, searchTerm, pagination.per_page])

  // Initial load
  useEffect(() => {
    loadFinancialYears()
    loadDepartments()
    loadSections()
  }, [loadFinancialYears, loadDepartments, loadSections])

  // Load dependent data when filters change
  useEffect(() => {
    if (selectedFinancialYear) {
      loadStats()
      loadDistribution()
      loadUpcoming()
      loadMatrix()
    }
  }, [selectedFinancialYear, selectedDepartment, selectedSection, loadStats, loadDistribution, loadUpcoming, loadMatrix])

  // Load roster list when FY or filters change
  useEffect(() => {
    if (selectedFinancialYear) {
      loadRoster(1)
    }
  }, [selectedFinancialYear, selectedDepartment, selectedSection, selectedMonth, selectedStatus, searchTerm, loadRoster])

  // ─── Actions ────────────────────────────────────────────────────

  const handleDelete = async (emp) => {
    const confirmed = window.confirm(
      `Remove Leave Roster Entry?\n\n${emp.employee_name}\n${emp.emp_code}\nPlanned month: ${emp.scheduled_month || '—'}\n\nThis will remove the planned leave allocation from the roster.\nIt will NOT delete or affect any actual leave application.`
    )
    if (!confirmed) return

    setActionLoading(emp.roster_id)
    try {
      await api.delete(`/leave/roster/${emp.roster_id}`)
      setRosterEntries((prev) => prev.filter((e) => e.roster_id !== emp.roster_id))
      loadStats()
      loadMatrix()
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to delete entry'
      setError(msg)
    } finally {
      setActionLoading(null)
    }
  }

  const handleScheduleSuccess = () => {
    loadStats()
    loadMatrix()
    loadRoster(1)
  }

  const handleExport = async () => {
    try {
      const params = {}
      if (selectedFinancialYear) params.financial_year_id = selectedFinancialYear
      if (selectedDepartment) params.department_id = selectedDepartment
      if (selectedSection) params.section_id = selectedSection
      if (selectedMonth) params.month = selectedMonth
      if (selectedStatus) params.status = selectedStatus
      if (searchTerm) params.search = searchTerm
      const response = await api.get('/leave/roster/export', { params, responseType: 'blob' })
      const url = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', 'leave-roster-export.csv')
      document.body.appendChild(link)
      link.click()
      link.remove()
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to export'
      setError(msg)
    }
  }

  const handleFilterChange = (field, value) => {
    switch (field) {
      case 'selectedFinancialYear': setSelectedFinancialYear(value); break
      case 'selectedDepartment': setSelectedDepartment(value); break
      case 'selectedSection': setSelectedSection(value); break
      case 'selectedMonth': setSelectedMonth(value); break
      case 'selectedStatus': setSelectedStatus(value); break
      case 'searchTerm': setSearchTerm(value); break
    }
  }

  const handleResetFilters = () => {
    setSelectedDepartment('')
    setSelectedSection('')
    setSelectedMonth('')
    setSelectedStatus('')
    setSearchTerm('')
  }

  const handleMonthPillClick = (month) => {
    setSelectedMonth(month === selectedMonth ? '' : month)
  }

  const handleScheduleClick = (emp) => {
    setSelectedEmployeeForSchedule(emp)
    setShowScheduleModal(true)
  }

  const handleNotScheduledClick = () => {
    // Apply "Not Scheduled" filter — show only unscheduled employees
    setSelectedStatus('not_scheduled')
    setSelectedDepartment('')
    setSelectedSection('')
    setSelectedMonth('')
    setSearchTerm('')
  }

  const handleScheduledClick = () => {
    // Show only scheduled employees
    setSelectedStatus('scheduled')
    setSelectedDepartment('')
    setSelectedSection('')
    setSelectedMonth('')
    setSearchTerm('')
  }

  const handleAllClick = () => {
    // Show all employees (clear status filter)
    setSelectedStatus('')
    setSelectedDepartment('')
    setSelectedSection('')
    setSelectedMonth('')
    setSearchTerm('')
  }

  // Get scheduled months for month pills
  const scheduledMonths = Array.from(
    new Set(
      (matrixData?.employees || [])
        .filter((e) => e.scheduled_month)
        .map((e) => e.scheduled_month)
    )
  )

  // Get current financial year name
  const currentFy = financialYears.find((y) => y.id == selectedFinancialYear)
  const fyName = currentFy?.year_name || currentFy?.name || ''

  // Get matrix employees (filtered by month and status if selected)
  const matrixEmployees = matrixData?.employees || []
  const filteredMatrixEmployees = matrixEmployees.filter((e) => {
    const hasScheduled = !!e.scheduled_month
    // Status filter
    if (selectedStatus === 'scheduled' && !hasScheduled) return false
    if (selectedStatus === 'not_scheduled' && hasScheduled) return false
    // Month filter - when a month is selected, show employees scheduled for that month
    // plus unscheduled employees (so you can see who still needs scheduling)
    if (selectedMonth && hasScheduled && e.scheduled_month !== selectedMonth) return false
    return true
  })

  // Get list view employees (from roster entries)
  const listViewEmployees = rosterEntries

  if (loading && rosterEntries.length === 0 && !stats) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-101">
            Annual Leave Roster
          </h1>
          <p className="text-gray-500 dark:text-gray-400">
            Plan employee annual leave across the {fyName || 'financial year'}
          </p>
        </div>
        <div className="flex items-center space-x-3">
          <Button variant="outline" size="sm" onClick={handleExport}>
            <Download className="h-4 w-4 mr-1" />
            Export CSV
          </Button>
          <Button onClick={() => setShowScheduleModal(true)}>
            <Plus className="h-4 w-4 mr-2" />
            Schedule Leave
          </Button>
        </div>
      </div>

      {/* Info Banner */}
      <LeaveInfoBanner financialYearName={fyName} />

      {/* Error */}
      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
          {error}
        </div>
      )}

      {/* Planning Progress (Spec #32) */}
      {stats && (
        <div className="bg-gray-50 dark:bg-slate-800/50 rounded-xl p-6 space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-101">
                {fyName} Annual Leave Planning
              </h2>
              <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {stats.total_scheduled} of {stats.total_active} employees planned
              </p>
            </div>
            <div className="text-right">
              <div className="text-3xl font-bold text-primary-600 dark:text-primary-400">
                {stats.coverage_percent}%
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {stats.not_scheduled} employees still require scheduling
              </p>
            </div>
          </div>

          <CoverageBar stats={stats} label="PLANNING COVERAGE" />

          {stats.not_scheduled > 0 && (
            <div className="flex justify-end">
              <Button
                size="sm"
                variant="outline"
                onClick={handleNotScheduledClick}
              >
                View {stats.not_scheduled} Unscheduled Employees
              </Button>
            </div>
          )}
        </div>
      )}

      {/* KPI Cards (Spec #2, #9, #14, #15) */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          {/* Active Employees - clickable (shows all) */}
          <Card className="cursor-pointer transition-transform hover:scale-[1.02]" onClick={handleAllClick}>
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                <Users className="h-5 w-5 text-gray-600 dark:text-gray-300" />
              </div>
              <div>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-101">
                  {stats.total_active}
                </div>
                <p className="text-sm text-gray-500 dark:text-gray-400">Active Employees</p>
              </div>
            </div>
          </Card>

          {/* Scheduled - clickable */}
          <Card className="cursor-pointer transition-transform hover:scale-[1.02] border-green-200 dark:border-green-800" onClick={handleScheduledClick}>
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <span className="text-green-600 dark:text-green-400 text-lg">✓</span>
              </div>
              <div>
                <div className="text-2xl font-bold text-green-600 dark:text-green-400">
                  {stats.total_scheduled}
                </div>
                <p className="text-sm text-gray-500 dark:text-gray-400">Scheduled</p>
              </div>
            </div>
          </Card>

          {/* Not Scheduled - clickable */}
          <Card
            className="cursor-pointer transition-transform hover:scale-[1.02] border-amber-200 dark:border-amber-800"
            onClick={handleNotScheduledClick}
          >
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                <span className="text-amber-600 dark:text-amber-400 text-lg">!</span>
              </div>
              <div>
                <div className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                  {stats.not_scheduled}
                </div>
                <p className="text-sm text-gray-500 dark:text-gray-400">Not Scheduled</p>
              </div>
            </div>
          </Card>

          {/* Coverage - clickable (shows all) */}
          <Card
            className="cursor-pointer transition-transform hover:scale-[1.02] border-blue-200 dark:border-blue-800"
            onClick={handleAllClick}
          >
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                <span className="text-blue-600 dark:text-blue-400 text-lg">◉</span>
              </div>
              <div>
                <div className="text-2xl font-bold text-blue-600 dark:text-blue-400">
                  {stats.coverage_percent}%
                </div>
                <p className="text-sm text-gray-500 dark:text-gray-400">Planning Coverage</p>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* Filter Bar (Spec #25, #26) */}
      <Card>
        <FilterBar
          financialYears={financialYears}
          departments={departments}
          sections={sections}
          selectedFinancialYear={selectedFinancialYear}
          selectedDepartment={selectedDepartment}
          selectedSection={selectedSection}
          selectedMonth={selectedMonth}
          selectedStatus={selectedStatus}
          searchTerm={searchTerm}
          onChange={handleFilterChange}
          onReset={handleResetFilters}
          showStatus={true}
        />
      </Card>

      {/* Month Pills (Spec #3) */}
      {matrixData && (
        <MonthPills
          selectedMonth={selectedMonth}
          scheduledMonths={scheduledMonths}
          onChange={handleMonthPillClick}
          financialYear={fyName}
        />
      )}

      {/* View Toggle + Planning Matrix / List (Spec #18, #19) */}
      <Card>
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center space-x-4">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-101">
              Planning Matrix
            </h3>
            <div className="flex items-center space-x-2">
              <button
                onClick={() => setViewMode('matrix')}
                className={`flex items-center space-x-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                  viewMode === 'matrix'
                    ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
                    : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-700'
                }`}
              >
                <LayoutGrid className="h-4 w-4" />
                <span>Matrix</span>
              </button>
              <button
                onClick={() => setViewMode('list')}
                className={`flex items-center space-x-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                  viewMode === 'list'
                    ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
                    : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-700'
                }`}
              >
                <List className="h-4 w-4" />
                <span>List</span>
              </button>
            </div>
          </div>
          <div className="text-sm text-gray-500 dark:text-gray-400">
            {viewMode === 'matrix'
              ? `${filteredMatrixEmployees.length} employees`
              : `${pagination.total} entries`}
          </div>
        </div>

        {viewMode === 'matrix' ? (
          <PlanningMatrix
            employees={filteredMatrixEmployees}
            onEdit={handleScheduleClick}
            onDelete={handleDelete}
            onSchedule={handleScheduleClick}
          />
        ) : (
          <>
            {loading ? (
              <div className="flex items-center justify-center h-64">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
              </div>
            ) : (
              <>
                <EmployeeRosterTable
                  employees={listViewEmployees}
                  onEdit={handleScheduleClick}
                  onDelete={handleDelete}
                  onSchedule={handleScheduleClick}
                  showLastUpdated={true}
                />
                {/* Pagination */}
                {pagination.last_page > 1 && (
                  <div className="flex items-center justify-between mt-4 px-2">
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                      Showing {((pagination.current_page - 1) * pagination.per_page) + 1} -{' '}
                      {Math.min(pagination.current_page * pagination.per_page, pagination.total)} of {pagination.total}
                    </div>
                    <div className="flex items-center space-x-2">
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={pagination.current_page <= 1 || loading}
                        onClick={() => loadRoster(pagination.current_page - 1)}
                      >
                        Previous
                      </Button>
                      <span className="text-sm text-gray-700 dark:text-gray-300">
                        Page {pagination.current_page} of {pagination.last_page}
                      </span>
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={pagination.current_page >= pagination.last_page || loading}
                        onClick={() => loadRoster(pagination.current_page + 1)}
                      >
                        Next
                      </Button>
                    </div>
                  </div>
                )}
              </>
            )}
          </>
        )}
      </Card>

      {/* Schedule Slide-over (Spec #6) */}
      <ScheduleSlideOver
        isOpen={showScheduleModal}
        onClose={() => { setShowScheduleModal(false); setSelectedEmployeeForSchedule(null) }}
        financialYearId={selectedFinancialYear}
        financialYears={financialYears}
        onSuccess={handleScheduleSuccess}
      />
    </div>
  )
}

export default LeaveRoster

