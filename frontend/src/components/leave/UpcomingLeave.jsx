import { Calendar, User } from 'lucide-react'
import Badge from '../ui/Badge'

/**
 * Upcoming planned leave section.
 *
 * Spec point #13: "Upcoming Leave Needs Its Own Card"
 *
 * Shows employees scheduled for the current month and next month.
 * The transition works correctly for financial years (June → July).
 *
 * Props:
 *   - upcoming: { current_month, next_month, current_month_employees, next_month_employees, next_month_count }
 */
const UpcomingLeave = ({ upcoming = null }) => {
  if (!upcoming) {
    return (
      <div className="text-center py-8">
        <p className="text-gray-500 dark:text-gray-400">No upcoming leave data available.</p>
      </div>
    )
  }

  const {
    current_month = 'Current Month',
    next_month = 'Next Month',
    current_month_employees = [],
    next_month_employees = [],
    next_month_count = 0,
  } = upcoming

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
      {/* Current Month */}
      <div>
        <h4 className="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-3">
          Current Month · {current_month}
        </h4>
        {current_month_employees.length === 0 ? (
          <div className="text-center py-8 bg-gray-50 dark:bg-slate-900/40 dark:bg-slate-700/30 rounded-lg">
            <Calendar className="h-8 w-8 mx-auto text-gray-300 dark:text-gray-500 mb-2" />
            <p className="text-sm text-gray-500 dark:text-gray-400">
              No employees scheduled for {current_month}
            </p>
          </div>
        ) : (
          <div className="space-y-2">
            {current_month_employees.map((emp) => (
              <div
                key={emp.employee_id}
                className="border border-gray-200 dark:border-slate-700 rounded-lg p-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
              >
                <div className="flex items-center space-x-3">
                  <div className="h-8 w-8 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                    <User className="h-4 w-4 text-primary-600 dark:text-primary-400" />
                  </div>
                  <div className="flex-1">
                    <div className="font-medium text-gray-900 dark:text-gray-100 dark:text-gray-101">
                      {emp.employee_name}
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                      {emp.emp_code} • {emp.department_name}
                    </div>
                  </div>
                  <Badge variant="success">Planned</Badge>
                </div>
                {emp.notes && (
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 ml-11">
                    {emp.notes}
                  </p>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Next Month */}
      <div>
        <h4 className="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-3">
          Next Month · {next_month}
        </h4>
        {next_month_employees.length === 0 ? (
          <div className="text-center py-8 bg-gray-50 dark:bg-slate-900/40 dark:bg-slate-700/30 rounded-lg">
            <Calendar className="h-8 w-8 mx-auto text-gray-300 dark:text-gray-500 mb-2" />
            <p className="text-sm text-gray-500 dark:text-gray-400">
              No employees scheduled for {next_month}
            </p>
          </div>
        ) : (
          <div className="space-y-2">
            {next_month_employees.map((emp) => (
              <div
                key={emp.employee_id}
                className="border border-gray-200 dark:border-slate-700 rounded-lg p-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
              >
                <div className="flex items-center space-x-3">
                  <div className="h-8 w-8 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                    <User className="h-4 w-4 text-primary-600 dark:text-primary-400" />
                  </div>
                  <div className="flex-1">
                    <div className="font-medium text-gray-900 dark:text-gray-100 dark:text-gray-101">
                      {emp.employee_name}
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                      {emp.emp_code} • {emp.department_name}
                    </div>
                  </div>
                  <Badge variant="success">Planned</Badge>
                </div>
              </div>
            ))}
            {next_month_count > 0 && (
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">
                {next_month_count} employee{next_month_count !== 1 ? 's' : ''} scheduled
              </p>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

export default UpcomingLeave
