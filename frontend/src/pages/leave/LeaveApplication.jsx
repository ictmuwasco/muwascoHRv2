import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import { Plus, FileText, Users as UsersIcon, Shield, Loader2 } from 'lucide-react'

// Leave type IDs that allow backdating / past-date applications.
// Study (5), Sick (2), and Claim-a-Day (9) can be applied for past dates
// because they relate to work already performed or events that already occurred.
// All other leave types must start on or after today.
const ALLOW_BACKDATE_LEAVE_TYPES = [2, 5, 9]

// Leave type IDs exempt from the "cannot apply while on leave / pending" rule.
// Sick leave (2) is exempt because illness can occur regardless of existing
// leave or pending applications — the employee must always be able to
// report an illness.
const EXEMPT_FROM_OVERLAP_CHECK = [2]

const LeaveApplication = () => {
  const navigate = useNavigate()
  const { user } = useAuth()
  const [employeeId, setEmployeeId] = useState('')
  const [leaveTypeId, setLeaveTypeId] = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [reason, setReason] = useState('')
  const [document, setDocument] = useState(null)
  const [loading, setLoading] = useState(false)
  const [calculating, setCalculating] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [eligibleDays, setEligibleDays] = useState(0)
  const [primaryDeduction, setPrimaryDeduction] = useState(0)
  const [annualDeduction, setAnnualDeduction] = useState(0)
  const [unpaidDays, setUnpaidDays] = useState(0)
  const [calendarDays, setCalendarDays] = useState(0)
  const [leaveTypes, setLeaveTypes] = useState([])
  const [balances, setBalances] = useState({})
  const [delegates, setDelegates] = useState([])
  const [delegateEmpId, setDelegateEmpId] = useState('')
  const [employees, setEmployees] = useState([])
  const [existingApplications, setExistingApplications] = useState([])
  const [checkingConflict, setCheckingConflict] = useState(false)

  // Load employees and delegates on mount
  useEffect(() => {
    loadEmployees()
  }, [])

  useEffect(() => {
    if (employeeId) {
      loadDelegates()
    }
  }, [employeeId])

  useEffect(() => {
    if (employeeId) {
      loadLeaveTypes()
      loadExistingApplications()
    }
  }, [employeeId])

  useEffect(() => {
    if (employeeId && leaveTypeId && startDate && endDate) {
      calculatePreview()
    }
  }, [employeeId, leaveTypeId, startDate, endDate])

  // Today's date in YYYY-MM-DD format (local timezone, for minDate on date pickers)
  const today = useMemo(() => {
    const d = new Date()
    const yyyy = d.getFullYear()
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const dd = String(d.getDate()).padStart(2, '0')
    return `${yyyy}-${mm}-${dd}`
  }, [])

  // Leave types that allow backdating (past dates).
  // Sick (2), Study (5), Claim-a-Day (9) can be backdated.
  // All other types must start on or after today.
  const canBackdate = ALLOW_BACKDATE_LEAVE_TYPES.includes(Number(leaveTypeId))

  // The min date for the start date picker.
  // Allows backdating only for exempted leave types.
  const minStartDate = canBackdate ? '' : today

  // When the leave type is changed, if the start date is before the new
  // min date and backdating is not allowed, clear it.
  useEffect(() => {
    if (!canBackdate && startDate && startDate < today) {
      setStartDate('')
    }
    if (!canBackdate && endDate && endDate < startDate) {
      setEndDate('')
    }
  }, [leaveTypeId, startDate, endDate, canBackdate, today])

  /**
   * Fetch existing leave applications for the selected employee to check
   * for active leaves or pending applications.
   */
  const loadExistingApplications = async () => {
    if (!employeeId) {
      setExistingApplications([])
      return
    }
    try {
      const response = await api.get(`/leave?employee_id=${employeeId}`)
      setExistingApplications(response.data.data || [])
    } catch (err) {
      console.error('Failed to load existing leave applications:', err)
      setExistingApplications([])
    }
  }

  /**
   * Check if the employee is currently on approved leave or has a pending
   * application. For leave types NOT exempt from the overlap check, prevent
   * new applications.
   *
   * @returns {string|null} Conflict message if blocked, null otherwise.
   */
  const checkLeaveConflict = () => {
    const typeId = Number(leaveTypeId)
    if (EXEMPT_FROM_OVERLAP_CHECK.includes(typeId)) return null

    if (!employeeId) return null

    const hasActiveOrPending = existingApplications.some((app) => {
      const status = String(app.status || '').toLowerCase()
      const start = new Date(app.start_date + 'T00:00:00')
      const end = new Date(app.end_date + 'T00:00:00')
      const newStart = startDate ? new Date(startDate + 'T00:00:00') : null
      const newEnd = endDate ? new Date(endDate + 'T00:00:00') : null
      const todayDate = new Date(today + 'T00:00:00')

      // Any pending application blocks a new application regardless of dates.
      const isPending = status === 'pending' || status.startsWith('pending')

      // "On leave" = an approved application that overlaps the requested
      // dates, or that covers today (currently on leave).
      const isApproved = status === 'approved'
      const overlapsRequested =
        newStart && newEnd && newStart <= end && newEnd >= start
      const coversToday = isApproved
        ? start <= todayDate && todayDate <= end
        : false

      return isPending || (isApproved && (overlapsRequested || coversToday))
    })

    if (hasActiveOrPending) {
      return 'You are currently on leave or have a pending leave application. You cannot submit a new application for this leave type. Sick leave can still be applied.'
    }
    return null
  }

  const loadEmployees = async () => {
    try {
      // Get eligible employees based on logged-in user's role
      const response = await api.get('/leave/eligible-employees')
      const empList = response.data.data || []
      setEmployees(empList)
      
      // Pre-fill with current user if available
      // Try multiple ways to find the current user
      if (empList.length > 0) {
        // First try: match by user.employee_id
        if (user?.employee_id) {
          const currentUserEmp = empList.find(emp => emp.employee_id === user.employee_id)
          if (currentUserEmp) {
            setEmployeeId(currentUserEmp.id)
            return
          }
        }
        
        // Second try: if only one employee, select it (likely the user themselves)
        if (empList.length === 1) {
          setEmployeeId(empList[0].id)
        }
      }
    } catch (err) {
      console.error('Failed to load employees:', err)
    }
  }

  const loadDelegates = async () => {
    try {
      // Get eligible delegates based on logged-in user's role
      const response = await api.get('/leave/eligible-delegates')
      const delegateList = response.data.data || []
      setDelegates(delegateList)
      
      // Auto-select first delegate if only one available
      if (delegateList.length === 1 && !delegateEmpId) {
        setDelegateEmpId(delegateList[0].id)
      }
    } catch (err) {
      console.error('Failed to load delegates:', err)
      // Don't show error to user - empty delegate list is valid
      setDelegates([])
    }
  }

  const loadLeaveTypes = async () => {
    try {
      const response = await api.get(`/leave/types?employee_id=${employeeId}`)
      setLeaveTypes(response.data.data || [])
    } catch (err) {
      console.error('Failed to load leave types:', err)
    }
  }

  const calculatePreview = async () => {
    if (!employeeId) {
      console.error('Cannot calculate preview: No employee selected')
      return
    }
    setCalculating(true)
    try {
      const formData = new FormData()
      formData.append('employee_id', employeeId)
      formData.append('leave_type_id', leaveTypeId)
      formData.append('start_date', startDate)
      formData.append('end_date', endDate)

      const response = await api.post('/leave/calculate', formData)
      const data = response.data.data
      setEligibleDays(data.eligible_days || 0)
      setPrimaryDeduction(data.deduction_plan.primary_deduction || 0)
      setAnnualDeduction(data.deduction_plan.annual_deduction || 0)
      setUnpaidDays(data.deduction_plan.unpaid_days || 0)

      // Calendar days calculation
      const start = new Date(startDate)
      const end = new Date(endDate)
      const days = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1
      setCalendarDays(days)
    } catch (err) {
      console.error('Failed to calculate preview:', err)
    } finally {
      setCalculating(false)
    }
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setError('')
    setSuccess('')

    if (eligibleDays <= 0) {
      setError('No eligible leave days. Please select a valid date range.')
      setLoading(false)
      return
    }

    // Business rule: cannot apply for most leave types while on leave or
    // with a pending application. Sick leave is exempt.
    const conflictMessage = checkLeaveConflict()
    if (conflictMessage) {
      setError(conflictMessage)
      setLoading(false)
      return
    }

    const formData = new FormData()
    formData.append('employee_id', employeeId)
    formData.append('leave_type_id', leaveTypeId)
    formData.append('start_date', startDate)
    formData.append('end_date', endDate)
    formData.append('delegate_emp_id', delegateEmpId)
    formData.append('reason', reason)
    if (document) {
      formData.append('document', document)
    }

    try {
      const response = await api.post('/leave/apply', formData)
      setSuccess('Leave application submitted successfully!')
      setSubmitted(true)
      setTimeout(() => navigate('/leave'), 1500)
    } catch (err) {
      console.error('Submit error:', err)
      setError(err.response?.data?.message || 'Failed to submit application')
    } finally {
      setLoading(false)
    }
  }

  if (submitted) {
    return (
      <div className="space-y-6">
        <Card>
          <div className="text-center py-8">
            <div className="text-green-600 text-lg font-semibold">Leave application submitted successfully!</div>
            <p className="text-gray-500 mt-2">Redirecting...</p>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Apply for Leave</h1>
        <p className="text-gray-500">Submit a new leave application</p>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-400 text-red-700 px-4 py-3 rounded-md">
          {error}
        </div>
      )}

      {success && (
        <div className="bg-green-50 border border-green-400 text-green-700 px-4 py-3 rounded-md">
          {success}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-6">
        <Card>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Employee</label>
              <select
                value={employeeId}
                onChange={(e) => setEmployeeId(e.target.value)}
                className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                required
              >
                <option value="">Select Employee</option>
                {employees.map((employee, index) => (
                  <option key={`${employee.id}-${index}`} value={employee.id}>
                    {employee.first_name} {employee.last_name} ({employee.employee_id})
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Leave Type</label>
              <select
                value={leaveTypeId}
                onChange={(e) => setLeaveTypeId(e.target.value)}
                className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                required
              >
                <option value="">Select Leave Type</option>
                {leaveTypes.map((type, index) => (
                  <option key={`${type.leave_type_id}-${index}`} value={type.leave_type_id}>
                    {type.leave_type_name} (Remaining: {type.remaining_days} days)
                  </option>
                ))}
              </select>
            </div>

            {!EXEMPT_FROM_OVERLAP_CHECK.includes(Number(leaveTypeId)) && !canBackdate && startDate && startDate < today && (
              <div className="bg-amber-50 border border-amber-400 text-amber-700 px-4 py-3 rounded-md">
                Backdating is not allowed for this leave type. The start date must be today or later.
              </div>
            )}

            {!EXEMPT_FROM_OVERLAP_CHECK.includes(Number(leaveTypeId)) && checkLeaveConflict() && (
              <div className="bg-red-50 border border-red-400 text-red-700 px-4 py-3 rounded-md">
                You are currently on leave or have a pending leave application. You cannot submit a new application for this leave type. Sick leave can still be applied.
              </div>
            )}

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
              <input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                min={minStartDate || undefined}
                className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                required
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">End Date</label>
              <input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                min={startDate || undefined}
                className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                required
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Delegate *</label>
              <select
                value={delegateEmpId}
                onChange={(e) => setDelegateEmpId(e.target.value)}
                className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                required
              >
                <option value="">Select Delegate</option>
                {delegates.map((delegate, index) => (
                  <option key={`${delegate.id}-${index}`} value={delegate.id}>
                    {delegate.first_name} {delegate.last_name} ({delegate.employee_id}) - {delegate.role}
                  </option>
                ))}
              </select>
              <p className="text-xs text-gray-500 mt-1">
                This person will temporarily take over your duties and approvals while on leave.
              </p>
            </div>
          </div>

          {/* Supporting Document for Sick/Study Leave */}
          {(leaveTypeId === '2' || leaveTypeId === '5') && (
            <div className="mt-4">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {leaveTypeId === '2' ? 'Medical Document *' : 'Study Document *'}
              </label>
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                onChange={(e) => setDocument(e.target.files[0])}
                className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                required={leaveTypeId === '2' || leaveTypeId === '5'}
              />
              <p className="text-xs text-gray-500 mt-1">
                Allowed: PDF, JPG, PNG. Max size: 5MB.
              </p>
            </div>
          )}

          <div className="mt-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Reason for Leave</label>
            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
              className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
              required
            />
          </div>
        </Card>

        {/* Preview Card */}
        {(employeeId && startDate && endDate && leaveTypeId) && (
          <Card>
            <h3 className="text-lg font-semibold mb-4">Leave Preview</h3>
            {calculating ? (
              <div className="text-gray-500">Calculating...</div>
            ) : (
              <div className="space-y-2">
                <div className="flex justify-between">
                  <span>Calendar Days:</span>
                  <span className="font-medium">{calendarDays}</span>
                </div>
                <div className="flex justify-between">
                  <span>Eligible Leave Days:</span>
                  <span className="font-medium">{eligibleDays}</span>
                </div>
                {primaryDeduction > 0 && (
                  <div className="flex justify-between">
                    <span>Primary Deduction:</span>
                    <span className="font-medium">{primaryDeduction} days</span>
                  </div>
                )}
                {annualDeduction > 0 && (
                  <div className="flex justify-between">
                    <span>Annual Leave Deduction:</span>
                    <span className="font-medium">{annualDeduction} days</span>
                  </div>
                )}
                {unpaidDays > 0 && (
                  <div className="flex justify-between text-red-600">
                    <span>Unpaid Days:</span>
                    <span className="font-medium">{unpaidDays} days</span>
                  </div>
                )}
                {eligibleDays <= 0 && (
                  <div className="text-red-600 font-medium">
                    No Eligible Leave Days. The selected dates fall on excluded days for this leave type.
                  </div>
                )}
              </div>
            )}
          </Card>
        )}

        <div className="flex space-x-4">
          <Button
            type="submit"
            disabled={loading || eligibleDays <= 0}
            loading={loading}
            className={eligibleDays <= 0 ? 'opacity-50 cursor-not-allowed' : ''}
          >
            {loading ? 'Submitting...' : 'Submit Application'}
          </Button>
          <Button type="button" variant="secondary" onClick={() => navigate('/leave')}>
            Cancel
          </Button>
        </div>
      </form>
    </div>
  )
}

export default LeaveApplication
