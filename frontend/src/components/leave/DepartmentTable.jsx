import Badge from '../ui/Badge'

/**
 * Department planning status table.
 *
 * Spec point #17: "Add a 'Department Planning Status' Table"
 *
 * Shows per-department: Employees, Scheduled, Unscheduled, Coverage
 * with subtle coverage badges.
 *
 * Props:
 *   - departments: array of { department_id, department_name, total_employees, scheduled_count, unscheduled_count, coverage_percent }
 */
const DepartmentTable = ({ departments = [] }) => {
  if (!departments || departments.length === 0) {
    return (
      <div className="text-center py-8">
        <p className="text-gray-500 dark:text-gray-400">No department data available.</p>
      </div>
    )
  }

  const getCoverageBadge = (coverage) => {
    if (coverage >= 90) return 'success'
    if (coverage >= 75) return 'default'
    if (coverage >= 50) return 'warning'
    return 'danger'
  }

  const getCoverageLabel = (coverage) => {
    if (coverage >= 90) return '✓ Well planned'
    if (coverage >= 75) return '• On track'
    if (coverage >= 50) return '! Needs attention'
    return '! Critical'
  }

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
        <thead className="bg-gray-50 dark:bg-slate-900/40 dark:bg-slate-900">
          <tr>
            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Department
            </th>
            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Employees
            </th>
            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Scheduled
            </th>
            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Unscheduled
            </th>
            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              Coverage
            </th>
          </tr>
        </thead>
        <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
          {departments.map((dept) => (
            <tr key={dept.department_id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
              <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                {dept.department_name}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400 dark:text-gray-300 text-right">
                {dept.total_employees}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400 dark:text-gray-300 text-right">
                {dept.scheduled_count}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400 dark:text-gray-300 text-right">
                {dept.unscheduled_count}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-right">
                <Badge variant={getCoverageBadge(dept.coverage_percent)}>
                  {dept.coverage_percent}% {getCoverageLabel(dept.coverage_percent)}
                </Badge>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default DepartmentTable
