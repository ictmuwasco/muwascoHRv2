import { X, Filter } from 'lucide-react'
import Button from '../ui/Button'
import { FY_MONTHS } from '../../constants/leaveConstants'


const FilterBar = ({
  financialYears = [],
  departments = [],
  sections = [],
  selectedFinancialYear = '',
  selectedDepartment = '',
  selectedSection = '',
  selectedMonth = '',
  selectedStatus = '',
  searchTerm = '',
  onChange,
  onReset,
  showStatus = false,
}) => {
  const activeFilters = []
  if (selectedFinancialYear) {
    const fy = financialYears.find((y) => y.id == selectedFinancialYear)
    activeFilters.push({ key: 'financialYear', label: fy?.year_name || `FY: ${selectedFinancialYear}` })
  }
  if (selectedDepartment) {
    const dept = departments.find((d) => (d.id || d.department_id) == selectedDepartment)
    activeFilters.push({ key: 'department', label: dept?.name || dept?.department_name || `Dept: ${selectedDepartment}` })
  }
  if (selectedSection) {
    const sec = sections.find((s) => s.id == selectedSection)
    activeFilters.push({ key: 'section', label: sec?.name || `Section: ${selectedSection}` })
  }
  if (selectedMonth) {
    activeFilters.push({ key: 'month', label: `Month: ${selectedMonth}` })
  }
  if (showStatus && selectedStatus) {
    const statusLabels = { scheduled: 'Scheduled', not_scheduled: 'Not Scheduled' }
    activeFilters.push({ key: 'status', label: `Status: ${statusLabels[selectedStatus] || selectedStatus}` })
  }
  if (searchTerm) {
    activeFilters.push({ key: 'search', label: `Search: ${searchTerm}` })
  }

  const removeFilter = (key) => {
    const fieldMap = {
      financialYear: 'selectedFinancialYear',
      department: 'selectedDepartment',
      section: 'selectedSection',
      month: 'selectedMonth',
      status: 'selectedStatus',
      search: 'searchTerm',
    }
    onChange(fieldMap[key], '')
  }

  return (
    <div className="space-y-3">
      {/* Filter controls */}
      <div className="flex flex-wrap gap-3 items-end">
        {/* Financial Year */}
        <div className="min-w-[140px]">
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
            Financial Year
          </label>
          <select
            value={selectedFinancialYear}
            onChange={(e) => onChange('selectedFinancialYear', e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-primary-500 focus:border-transparent"
          >
            <option value="">All Years</option>
            {financialYears.map((y) => (
              <option key={y.id} value={y.id}>
                {y.year_name || y.name}
              </option>
            ))}
          </select>
        </div>

        {/* Department */}
        <div className="min-w-[140px]">
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
            Department
          </label>
          <select
            value={selectedDepartment}
            onChange={(e) => onChange('selectedDepartment', e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-primary-500 focus:border-transparent"
          >
            <option value="">All Departments</option>
            {departments.map((d) => (
              <option key={d.id || d.department_id} value={d.id || d.department_id}>
                {d.name || d.department_name}
              </option>
            ))}
          </select>
        </div>

        {/* Section */}
        <div className="min-w-[140px]">
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
            Section
          </label>
          <select
            value={selectedSection}
            onChange={(e) => onChange('selectedSection', e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-primary-500 focus:border-transparent"
          >
            <option value="">All Sections</option>
            {sections.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        </div>

        {/* Month */}
        <div className="min-w-[140px]">
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
            Month
          </label>
          <select
            value={selectedMonth}
            onChange={(e) => onChange('selectedMonth', e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-primary-500 focus:border-transparent"
          >
            <option value="">All Months</option>
            {FY_MONTHS.map((m) => (
              <option key={m} value={m}>
                {m}
              </option>
            ))}
          </select>
        </div>

        {/* Status (only for oversight) */}
        {showStatus && (
          <div className="min-w-[140px]">
            <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
              Status
            </label>
            <select
              value={selectedStatus}
              onChange={(e) => onChange('selectedStatus', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-primary-500 focus:border-transparent"
            >
              <option value="">All Statuses</option>
              <option value="scheduled">Scheduled</option>
              <option value="not_scheduled">Not Scheduled</option>
            </select>
          </div>
        )}

        {/* Search */}
        <div className="flex-1 min-w-[200px]">
          <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
            Search
          </label>
          <div className="relative">
            <input
              type="text"
              placeholder="Employee name or ID..."
              value={searchTerm}
              onChange={(e) => onChange('searchTerm', e.target.value)}
              className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-primary-500 focus:border-transparent"
            />
            <Filter className="absolute left-3 top-2.5 h-4 w-4 text-gray-400 dark:text-gray-500" />
          </div>
        </div>

        {/* Reset */}
        <div className="flex items-end">
          <Button
            size="sm"
            variant="outline"
            onClick={onReset}
            disabled={activeFilters.length === 0}
          >
            Reset
          </Button>
        </div>
      </div>

      {/* Active filter chips */}
      {activeFilters.length > 0 && (
        <div className="flex flex-wrap gap-2">
          <span className="text-xs font-medium text-gray-500 dark:text-gray-400">Filters:</span>
          {activeFilters.map((f) => (
            <span
              key={f.key}
              className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary-100 dark:bg-primary-900/30 text-primary-800 dark:text-primary-200"
            >
              {f.label}
              <button
                onClick={() => removeFilter(f.key)}
                className="ml-2 text-primary-600 dark:text-primary-300 hover:text-primary-800 dark:hover:text-primary-100"
              >
                <X className="h-3 w-3" />
              </button>
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

export default FilterBar
