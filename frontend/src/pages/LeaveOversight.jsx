import { useState, useEffect, useCallback } from 'react'
import { Download, RefreshCw, LayoutGrid, List, Users, Check, BarChart3, Calendar, Info } from 'lucide-react'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Button from '../components/ui/Button'
import Badge from '../components/ui/Badge'
import LeaveInfoBanner from '../components/leave/LeaveInfoBanner'
import CoverageBar from '../components/leave/CoverageBar'
import PlanningMatrix from '../components/leave/PlanningMatrix'
import DistributionChart from '../components/leave/DistributionChart'
import UpcomingLeave from '../components/leave/UpcomingLeave'
import DepartmentTable from '../components/leave/DepartmentTable'
import EmployeeRosterTable from '../components/leave/EmployeeRosterTable'
import FilterBar from '../components/leave/FilterBar'
import { FY_MONTHS } from '../constants/leaveConstants'

/**
 * LeaveOversight — Executive Dashboard / Analytics
 *
 * Spec points covered:
 *  #8  Oversight page is the "Executive" screen
 *  #9  Recommended Oversight Layout
 *  #10 Proper Coverage Progress Bar
 *  #11 Monthly Distribution as main chart
 *  #12 Planning Attention (not fake rules)
 *  #13 Upcoming Leave card
 *  #14 Not Scheduled is an action
 *  #15 Scheduled card is interactive
 *  #16 Department/Section Analytics
 *  #17 Department Planning Status table
 *  #18 Employee table is clear
 *  #19 Planning Matrix view
 *  #22 Info banner
 *  #23 Color strategy
 *  #25 Unified filter bar
 *  #26 Active filter chips
 *  #27 CSV export reflects current view
 *  #28 Mobile design
 *  #29 Final visual hierarchy
 *  #30 Backend logic unchanged
 */
const LeaveOversight = () => {
  // Data state
  const [stats, setStats] = useState(null)
  const [distribution, setDistribution] = useState({ distribution: [], highest: null, lowest: null })
  const [upcoming, setUpcoming] = useState(null)
  const [departments, setDepartments] = useState([])
  const [matrixData, setMatrixData] = useState(null)
  const [rosterEntries, setRosterEntries] = useState([])
  const [financialYears, setFinancialYears] = useState([])
  const [sections, setSections] = useState([])

  // UI state
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [selectedFinancialYear, setSelectedFinancialYear] = useState('')
  const [selectedDepartment, setSelectedDepartment] = useState('')
  const [selectedSection, setSelectedSection] = useState('')
  const [selectedMonth, setSelectedMonth] = useState('')
  const [selectedStatus, setSelectedStatus] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [viewMode, setViewMode] = useState('list')
  const [pagination, setPagination] = useState({ total: 0, per_page: 20, current_page: 1, last_page: 1 })

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

  const loadDepartments = useCallback(async () => {
    try {
      const params = {}
      if (selectedFinancialYear) params.financial_year_id = selectedFinancialYear
      const response = await api.get('/leave/roster/departments', { params })
      setDepartments(response.data?.data || [])
    } catch (err) {
      console.error('Failed to load departments:', err)
    }
  }, [selectedFinancialYear])

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
    loadSections()
  }, [loadFinancialYears, loadSections])

  // Load dependent data when filters change
  useEffect(() => {
    if (selectedFinancialYear) {
      loadStats()
      loadDistribution()
      loadUpcoming()
      loadDepartments()
      loadMatrix()
      loadRoster(1)
    }
  }, [selectedFinancialYear, selectedDepartment, selectedSection, selectedMonth, selectedStatus, searchTerm, loadStats, loadDistribution, loadUpcoming, loadDepartments, loadMatrix, loadRoster])

  // ─── Actions ────────────────────────────────────────────────────

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
      link.setAttribute('download', 'leave-oversight-export.csv')
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

  const handleNotScheduledClick = () => {
    setSelectedStatus('not_scheduled')
    setSelectedDepartment('')
    setSelectedSection('')
    setSelectedMonth('')
    setSearchTerm('')
  }

  const handleScheduledClick = () => {
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

  const handleRefresh = () => {
    if (selectedFinancialYear) {
      loadStats()
      loadDistribution()
      loadUpcoming()
      loadDepartments()
      loadMatrix()
      loadRoster(1)
    }
  }

  // Get current financial year name
  const currentFy = financialYears.find((y) => y.id == selectedFinancialYear)
  const fyName = currentFy?.year_name || currentFy?.name || ''

  // Get matrix employees (filtered by status if selected)
  const matrixEmployees = matrixData?.employees || []
  const filteredMatrixEmployees = matrixEmployees.filter((e) => {
    const hasScheduled = !!e.scheduled_month
    if (selectedStatus === 'scheduled' && !hasScheduled) return false
    if (selectedStatus === 'not_scheduled' && hasScheduled) return false
    return true
  })

  // Get scheduled months for month pills
  const scheduledMonths = Array.from(
    new Set(
      matrixEmployees
        .filter((e) => e.scheduled_month)
        .map((e) => e.scheduled_month)
    )
  )

  if (loading && !stats && !matrixData) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header (Spec #9) */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-101">
            Annual Leave Oversight
          </h1>
          <p className="text-gray-500 dark:text-gray-400">
            {fyName ? `${fyName} Financial Year` : 'Financial Year'} · Monitor annual leave planning coverage, distribution and upcoming schedules.
          </p>
        </div>
        <div className="flex items-center space-x-3">
          <Button variant="outline" size="sm" onClick={handleExport}>
            <Download className="h-4 w-4 mr-1" />
            Export CSV
          </Button>
          <Button variant="outline" size="sm" onClick={handleRefresh}>
            <RefreshCw className="h-4 w-4 mr-1" />
            Refresh
          </Button>
        </div>
      </div>

      {/* Info Banner (Spec #22) */}
      <LeaveInfoBanner financialYearName={fyName} />

      {/* Error */}
      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
          {error}
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

      {/* KPI Cards (Spec #9, #14, #15) */}
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

          {/* Scheduled */}
          <Card
            className="cursor-pointer transition-transform hover:scale-[1.02] border-green-200 dark:border-green-800"
            onClick={handleScheduledClick}
          >
            <div className="flex items-center space-x-3">
              <div className="h-10 w-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <Check className="h-5 w-5 text-green-600 dark:text-green-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-green-600 dark:text-green-400">
                  {stats.total_scheduled}
                </div>
                <p className="text-sm text-gray-500 dark:text-gray-400">Scheduled</p>
              </div>
            </div>
          </Card>

          {/* Not Scheduled / Pending */}
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

          {/* Coverage */}
          <Card className="cursor-pointer transition-transform hover:scale-[1.02] border-blue-200 dark:border-blue-800">
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

      {/* Coverage Progress Bar (Spec #10) */}
      {stats && (
        <Card title="Planning Coverage">
          <CoverageBar stats={stats} label="ANNUAL LEAVE PLANNING COVERAGE" />
        </Card>
      )}

      {/* Monthly Distribution + Planning Attention (Spec #11, #12) */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card title="Planned Leave Distribution" subtitle="July → June">
          <DistributionChart
            distribution={distribution.distribution}
            highest={distribution.highest}
            lowest={distribution.lowest}
          />
        </Card>

        {/* Planning Attention (Spec #12) */}
        <Card title="Planning Attention">
          <div className="space-y-4">
            {distribution.highest && (
              <div className="flex items-start space-x-3">
                <div className="h-8 w-8 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                  <span className="text-amber-600 dark:text-amber-400">📊</span>
                </div>
                <div>
                  <p className="text-sm font-medium text-gray-900 dark:text-gray-101">
                    {distribution.highest.month}
                  </p>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    {distribution.highest.count} employees scheduled
                  </p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    Highest concentration
                  </p>
                </div>
              </div>
            )}
            {distribution.lowest && (
              <div className="flex items-start space-x-3">
                <div className="h-8 w-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                  <span className="text-blue-600 dark:text-blue-400">📉</span>
                </div>
                <div>
                  <p className="text-sm font-medium text-gray-900 dark:text-gray-101">
                    {distribution.lowest.month}
                  </p>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    {distribution.lowest.count} employees scheduled
                  </p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    Lowest concentration
                  </p>
                </div>
              </div>
            )}
            {stats && stats.not_scheduled > 0 && (
              <div className="flex items-start space-x-3">
                <div className="h-8 w-8 rounded-full bg-gray-100 dark:bg-slate-700 flex items-center justify-center flex-shrink-0">
                  <span className="text-gray-600 dark:text-gray-300">!</span>
                </div>
                <div>
                  <p className="text-sm font-medium text-gray-900 dark:text-gray-101">
                    {stats.not_scheduled} employees
                  </p>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    No leave month assigned
                  </p>
                </div>
              </div>
            )}
          </div>
        </Card>

        {/* Upcoming Leave (Spec #13) */}
        <Card title="Upcoming Planned Leave">
          <UpcomingLeave upcoming={upcoming} />
        </Card>
      </div>

      {/* Department Planning Status (Spec #16, #17) */}
      <Card title="Department Planning Status">
        <DepartmentTable departments={departments} />
      </Card>

      {/* Employee Roster (Spec #18, #19, #20, #21) */}
      <Card>
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center space-x-4">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-101">
              Employee Roster
            </h3>
            {stats && (
              <span className="text-sm text-gray-500 dark:text-gray-400">
                {viewMode === 'matrix'
                  ? `${filteredMatrixEmployees.length} employees`
                  : `${stats.total_scheduled} scheduled · ${stats.not_scheduled} not scheduled`}
              </span>
            )}
          </div>
          <div className="flex items-center space-x-2">
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
          </div>
        </div>

        {viewMode === 'matrix' ? (
          <PlanningMatrix
            employees={filteredMatrixEmployees}
            onEdit={(emp) => {}}
            onDelete={() => {}}
            onSchedule={() => {}}
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
                  employees={rosterEntries}
                  onEdit={() => {}}
                  onDelete={() => {}}
                  onSchedule={() => {}}
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
    </div>
  )
}

export default LeaveOversight
