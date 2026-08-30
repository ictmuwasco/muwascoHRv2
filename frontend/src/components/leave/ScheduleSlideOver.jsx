import { useState, useEffect, useRef } from 'react'
import { X, Search, User } from 'lucide-react'
import api from '../../utils/api'
import Button from '../ui/Button'
import Input from '../ui/Input'
import Select from '../ui/Select'
import Badge from '../ui/Badge'
import { FY_MONTHS } from '../../constants/leaveConstants'

/**
 * Slide-over form for scheduling annual leave.
 *
 * Spec point #6: "The 'Add Roster' Form Should Be a Slide-over"
 * Spec point #7: "Employee Search Should Be Smart"
 *
 * Features:
 * - Searchable employee dropdown (debounced)
 * - Shows current roster status for selected employee
 * - Financial Year selector
 * - Planned Leave Month selector (July → June order)
 * - Notes field
 * - Cancel / Schedule Leave buttons
 */
const ScheduleSlideOver = ({
  isOpen,
  onClose,
  financialYearId,
  financialYears = [],
  onSuccess,
  /** Row (roster list / matrix / search result) used to prefill the form.
   *  Carrying roster_id switches the slide-over into EDIT mode. */
  initialEmployee = null,
}) => {
  const [searchTerm, setSearchTerm] = useState('')
  const [searchResults, setSearchResults] = useState([])
  const [searchLoading, setSearchLoading] = useState(false)
  const [selectedEmployee, setSelectedEmployee] = useState(null)
  const [showDropdown, setShowDropdown] = useState(false)
  const [selectedMonth, setSelectedMonth] = useState('')
  const [notes, setNotes] = useState('')
  const [submitLoading, setSubmitLoading] = useState(false)
  const [error, setError] = useState('')
  const dropdownRef = useRef(null)

  // Edit mode when prefilled from an existing roster entry.
  const editId = initialEmployee?.roster_id || null

  /** Adapt roster-list rows / search rows to one shape for display. */
  const normalizeEmployee = (emp) =>
    emp
      ? {
          id: emp.id ?? emp.employee_id,
          employee_name:
            emp.employee_name ??
            [emp.first_name, emp.last_name].filter(Boolean).join(' ').trim() ??
            '',
          emp_code: emp.emp_code ?? '',
          department_name: emp.department_name ?? '',
          section_name: emp.section_name ?? '',
          scheduled_month: emp.scheduled_month ?? null,
        }
      : null

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setShowDropdown(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  // Debounced employee search
  useEffect(() => {
    if (!searchTerm.trim()) {
      setSearchResults([])
      return
    }
    const timer = setTimeout(() => {
      searchEmployees(searchTerm)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchTerm])

  const searchEmployees = async (term) => {
    setSearchLoading(true)
    try {
      const response = await api.get('/leave/roster/employees', {
        params: { search: term, financial_year_id: financialYearId },
      })
      setSearchResults(response.data?.data || [])
    } catch (err) {
      console.error('Failed to search employees:', err)
      setSearchResults([])
    } finally {
      setSearchLoading(false)
    }
  }

  const handleSelectEmployee = (emp) => {
    setSelectedEmployee(emp)
    setShowDropdown(false)
    setSearchTerm('')
    setError('')
  }

  const handleSubmit = async () => {
    if (!selectedEmployee) {
      setError('Please select an employee.')
      return
    }
    if (!selectedMonth) {
      setError('Please select a planned leave month.')
      return
    }

    setSubmitLoading(true)
    setError('')
    try {
      // Edit mode targets the existing roster row (PUT); otherwise create.
      if (editId) {
        await api.put(`/leave/roster/${editId}`, {
          scheduled_month: selectedMonth,
          notes: notes.trim(),
        })
      } else {
        await api.post('/leave/roster', {
          employee_id: selectedEmployee.id,
          financial_year_id: financialYearId,
          scheduled_month: selectedMonth,
          notes: notes.trim(),
        })
      }
      onSuccess && onSuccess()
      handleClose()
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to schedule leave.'
      setError(msg)
    } finally {
      setSubmitLoading(false)
    }
  }

  const handleClose = () => {
    setSearchTerm('')
    setSearchResults([])
    setSelectedEmployee(null)
    setSelectedMonth('')
    setNotes('')
    setError('')
    setShowDropdown(false)
    onClose()
  }

  // Prefill whenever the slide-over opens. A preselected employee skips the
  // search step entirely; an existing roster entry also restores month/notes.
  useEffect(() => {
    if (!isOpen) return
    if (initialEmployee) {
      setSelectedEmployee(normalizeEmployee(initialEmployee))
      setSelectedMonth(initialEmployee.scheduled_month ?? '')
      setNotes(initialEmployee.notes ?? '')
      setSearchTerm('')
      setSearchResults([])
      setShowDropdown(false)
      setError('')
    } else {
      setSelectedEmployee(null)
      setSelectedMonth('')
      setNotes('')
      setError('')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen])

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/50" onClick={handleClose} />

      {/* Slide-over panel */}
      <div className="absolute inset-y-0 right-0 w-full max-w-lg bg-white dark:bg-slate-800 shadow-xl transform transition-transform duration-300 ease-in-out flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between h-14 border-b dark:border-slate-700 px-6 flex-shrink-0">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
            {editId ? 'Edit Leave Schedule' : 'Schedule Annual Leave'}
          </h2>
          <button
            onClick={handleClose}
            className="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 rounded-lg"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto p-6 space-y-5">
          {/* Employee Search */}
          <div className="relative" ref={dropdownRef}>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Employee
            </label>
            <div className="relative">
              <input
                type="text"
                placeholder={editId ? 'Employee locked while editing' : 'Search employee...'}
                value={searchTerm}
                disabled={!!editId}
                onChange={(e) => {
                  if (editId) return
                  setSearchTerm(e.target.value)
                  setShowDropdown(true)
                }}
                onFocus={() => { if (!editId) setShowDropdown(true) }}
                className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent disabled:bg-gray-50 dark:disabled:bg-slate-900/60 disabled:text-gray-500"
              />
              <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400 dark:text-gray-500" />
            </div>

            {/* Dropdown */}
            {showDropdown && (
              <div className="absolute z-10 mt-1 w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-md shadow-lg max-h-60 overflow-y-auto">
                {searchLoading ? (
                  <div className="p-3 text-sm text-gray-500 dark:text-gray-400">
                    Searching...
                  </div>
                ) : searchResults.length === 0 ? (
                  <div className="p-3 text-sm text-gray-500 dark:text-gray-400">
                    {searchTerm ? 'No employees found.' : 'Start typing to search...'}
                  </div>
                ) : (
                  searchResults.map((emp) => (
                    <button
                      key={emp.id}
                      onClick={() => handleSelectEmployee(emp)}
                      className="w-full text-left p-3 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                    >
                      <div className="font-medium text-gray-900 dark:text-gray-100">
                        {emp.employee_name}
                      </div>
                      <div className="text-sm text-gray-500 dark:text-gray-400">
                        {emp.emp_code} • {emp.department_name}
                        {emp.section_name && ` → ${emp.section_name}`}
                      </div>
                      {emp.scheduled_month && (
                        <Badge variant="warning" className="mt-1">
                          Already scheduled: {emp.scheduled_month}
                        </Badge>
                      )}
                    </button>
                  ))
                )}
              </div>
            )}
          </div>

          {/* Selected Employee Info */}
          {selectedEmployee && (
            <div className="bg-gray-50 dark:bg-slate-700/50 rounded-lg p-4">
              <div className="flex items-center space-x-3">
                <div className="h-10 w-10 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                  <User className="h-5 w-5 text-primary-600 dark:text-primary-400" />
                </div>
                <div>
                  <div className="font-medium text-gray-900 dark:text-gray-100">
                    {selectedEmployee.employee_name}
                  </div>
                  <div className="text-sm text-gray-500 dark:text-gray-400">
                    {selectedEmployee.emp_code} • {selectedEmployee.department_name}
                    {selectedEmployee.section_name && ` → ${selectedEmployee.section_name}`}
                  </div>
                </div>
              </div>

              {/* Current Roster Status */}
              <div className="mt-3 pt-3 border-t dark:border-slate-600">
                <p className="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
                  Current roster status
                </p>
                {selectedEmployee.scheduled_month ? (
                  <Badge variant="success">
                    ✓ Already scheduled: {selectedEmployee.scheduled_month}
                  </Badge>
                ) : (
                  <Badge variant="default">
                    Not yet scheduled
                  </Badge>
                )}
              </div>
            </div>
          )}

          {/* Financial Year */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Financial Year
            </label>
            <Select
              value={financialYearId}
              onChange={() => {}}
              options={financialYears.map((y) => ({
                value: y.id,
                label: y.year_name || y.name,
              }))}
              disabled
            />
          </div>

          {/* Planned Leave Month */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Planned Leave Month
            </label>
            <Select
              value={selectedMonth}
              onChange={(e) => setSelectedMonth(e.target.value)}
              options={FY_MONTHS.map((m) => ({ value: m, label: m }))}
              placeholder="Select a month..."
            />
          </div>

          {/* Notes */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Notes
            </label>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Optional planning notes..."
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent resize-none"
            />
          </div>

          {/* Error */}
          {error && (
            <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
              {error}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end space-x-3 h-14 border-t dark:border-slate-700 px-6 flex-shrink-0">
          <Button variant="outline" size="sm" onClick={handleClose}>
            Cancel
          </Button>
          <Button
            size="sm"
            onClick={handleSubmit}
            loading={submitLoading}
            disabled={!selectedEmployee || !selectedMonth}
          >
            {editId ? 'Save Changes' : 'Schedule Leave'}
          </Button>
        </div>
      </div>
    </div>
  )
}

export default ScheduleSlideOver
