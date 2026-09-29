import { useState } from 'react'
import { Calendar, Edit3, Trash2, User } from 'lucide-react'
import Badge from '../ui/Badge'
import Button from '../ui/Button'
import { FY_MONTHS } from '../../constants/leaveConstants'

/**
 * Employee roster table with list view.
 *
 * Spec point #18: "Employee Table Should Be Extremely Clear"
 * Spec point #20: "Add 'Last Updated' Information"
 * Spec point #21: "Keep Edit/Delete Very Simple"
 *
 * Props:
 *   - employees: array of roster entries
 *   - onEdit: (employee) => void
 *   - onDelete: (employee) => void
 *   - onSchedule: (employee) => void  (for unscheduled employees)
 *   - showLastUpdated: boolean
 */
const EmployeeRosterTable = ({
  employees = [],
  onEdit,
  onDelete,
  onSchedule,
  showLastUpdated = false,
}) => {
  const [hoveredId, setHoveredId] = useState(null)

  if (!employees || employees.length === 0) {
    return (
      <div className="text-center py-12">
        <Calendar className="h-12 w-12 mx-auto text-gray-300 dark:text-gray-600 mb-4" />
        <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 dark:text-gray-101">
          No roster entries found
        </h3>
        <p className="text-gray-500 dark:text-gray-400 mt-2">
          No leave has been scheduled for the selected criteria.
        </p>
      </div>
    )
  }

  const formatDate = (dateStr) => {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    return d.toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: 'numeric' })
  }

  const getMonthBadge = (month) => {
    if (!month) return <span className="text-gray-400 dark:text-gray-500">—</span>
    const isCurrent = month === new Date().toLocaleString('default', { month: 'long' })
    const variant = isCurrent ? 'warning' : 'success'
    return (
      <Badge variant={variant} className="text-xs font-medium">
        {month.toUpperCase()}
      </Badge>
    )
  }

  const getStatusBadge = (status) => {
    const variants = {
      scheduled: 'success',
      not_scheduled: 'default',
      pending: 'warning',
    }
    const labels = {
      scheduled: 'Scheduled',
      not_scheduled: 'Not Scheduled',
      pending: 'Pending',
    }
    return (
      <Badge variant={variants[status] || 'default'}>
        {labels[status] || status}
      </Badge>
    )
  }

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
        <thead className="bg-gray-50 dark:bg-slate-900/40 dark:bg-slate-900">
          <tr>
            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Employee
            </th>
            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Department
            </th>
            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Planned Leave
            </th>
            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Status
            </th>
            {showLastUpdated && (
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                Last Updated
              </th>
            )}
            <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Actions
            </th>
          </tr>
        </thead>
        <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
          {employees.map((emp) => {
            const hasScheduled = !!emp.scheduled_month
            return (
              <tr
                key={emp.roster_id || emp.employee_id}
                className="hover:bg-gray-50 dark:hover:bg-slate-700/50"
                onMouseEnter={() => setHoveredId(emp.roster_id || emp.employee_id)}
                onMouseLeave={() => setHoveredId(null)}
              >
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="flex items-center space-x-3">
                    <div className="h-8 w-8 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                      <User className="h-4 w-4 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div>
                      <div className="font-medium text-gray-900 dark:text-gray-100 dark:text-gray-101">
                        {emp.employee_name}
                      </div>
                      <div className="text-sm text-gray-500 dark:text-gray-400">
                        {emp.emp_code}
                      </div>
                    </div>
                  </div>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400 dark:text-gray-300">
                  {emp.department_name || '—'}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  {getMonthBadge(emp.scheduled_month)}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  {getStatusBadge(emp.status || (hasScheduled ? 'scheduled' : 'not_scheduled'))}
                </td>
                {showLastUpdated && (
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {emp.updated_at ? formatDate(emp.updated_at) : '—'}
                  </td>
                )}
                <td className="px-6 py-4 whitespace-nowrap text-center">
                  <div className="flex items-center justify-center space-x-1">
                    {hoveredId === (emp.roster_id || emp.employee_id) && (
                      <>
                        {hasScheduled ? (
                          <>
                            <button
                              onClick={() => onEdit && onEdit(emp)}
                              className="p-1 text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 rounded"
                              title="Edit"
                            >
                              <Edit3 className="h-4 w-4" />
                            </button>
                            <button
                              onClick={() => onDelete && onDelete(emp)}
                              className="p-1 text-gray-600 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 rounded"
                              title="Remove"
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
                          </>
                        ) : (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onSchedule && onSchedule(emp)}
                            className="text-xs"
                          >
                            + Schedule
                          </Button>
                        )}
                      </>
                    )}
                  </div>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

export default EmployeeRosterTable
