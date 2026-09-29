import { FY_MONTHS, FY_MONTH_SHORT } from '../../constants/leaveConstants'

/**
 * Employee × Month planning matrix.
 *
 * Spec point #4: "The Roster Table Should Become a Planning Matrix"
 * Spec point #19: "Planning Matrix"
 *
 * Each employee has exactly one highlighted month (their scheduled leave month).
 * Unscheduled employees show "—" across all months.
 *
 * Props:
 *   - employees: array of { employee_id, employee_name, emp_code, department_name, scheduled_month, roster_id }
 *   - onEdit: (employee) => void
 *   - onDelete: (employee) => void
 *   - onSchedule: (employee) => void  (for unscheduled employees)
 */
const PlanningMatrix = ({ employees = [], onEdit, onDelete, onSchedule }) => {
  if (!employees || employees.length === 0) {
    return (
      <div className="text-center py-12">
        <p className="text-gray-500 dark:text-gray-400">No employees found for the selected criteria.</p>
      </div>
    )
  }

  const scheduledMonths = new Set(
    employees
      .filter((e) => e.scheduled_month)
      .map((e) => e.scheduled_month)
  )

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
        <thead className="bg-gray-50 dark:bg-slate-900/40 dark:bg-slate-900">
          <tr>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Employee
            </th>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Department
            </th>
            {FY_MONTHS.map((month, idx) => (
              <th
                key={month}
                className="px-2 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"
            >
              {FY_MONTH_SHORT[idx]}
            </th>
          ))}
          <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
            Actions
          </th>
        </tr>
      </thead>
      <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
        {employees.map((emp) => {
          const hasScheduled = !!emp.scheduled_month
          return (
            <tr key={emp.employee_id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
              <td className="px-4 py-3 whitespace-nowrap">
                <div className="font-medium text-gray-900 dark:text-gray-100">{emp.employee_name}</div>
                <div className="text-sm text-gray-500 dark:text-gray-400">{emp.emp_code}</div>
              </td>
              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400 dark:text-gray-300">
                {emp.department_name || '—'}
              </td>
              {FY_MONTHS.map((month) => {
                const isScheduled = emp.scheduled_month === month
                const isMonthScheduled = scheduledMonths.has(month)
                return (
                  <td key={month} className="px-2 py-3 text-center">
                    {isScheduled ? (
                      <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-green-500 text-white text-xs font-bold">
                        ●
                      </span>
                    ) : isMonthScheduled ? (
                      <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-300 dark:bg-slate-600 text-gray-600 dark:text-gray-400 text-xs">
                        ○
                      </span>
                    ) : (
                      <span className="text-gray-400 dark:text-gray-500">—</span>
                    )}
                  </td>
                )
              })}
              <td className="px-4 py-3 whitespace-nowrap text-center">
                <div className="flex items-center justify-center space-x-1">
                  {hasScheduled ? (
                    <>
                      <button
                        onClick={() => onEdit && onEdit(emp)}
                        className="p-1 text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 rounded"
                        title="Edit"
                      >
                        ✏️
                      </button>
                      <button
                        onClick={() => onDelete && onDelete(emp)}
                        className="p-1 text-gray-600 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 rounded"
                        title="Remove"
                      >
                        🗑
                      </button>
                    </>
                  ) : (
                    <button
                      onClick={() => onSchedule && onSchedule(emp)}
                      className="text-xs px-2 py-1 text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-slate-700 rounded"
                      title="Schedule Leave"
                    >
                      + Schedule
                    </button>
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

export default PlanningMatrix
