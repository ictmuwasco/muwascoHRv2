import { FY_MONTHS } from '../../constants/leaveConstants'

/**
 * Coverage progress bar with explanatory text.
 *
 * Spec point #10: "Add a Proper Coverage Progress Bar"
 * Shows: "X of Y employees scheduled" + bar + percentage + "Z employees still require scheduling"
 */
const CoverageBar = ({ stats, label = 'PLANNING COVERAGE', showDetails = true }) => {
  const scheduled = stats?.total_scheduled || 0
  const active = stats?.total_active || 0
  const notScheduled = stats?.not_scheduled || 0
  const coverage = stats?.coverage_percent || 0

  const percentage = active > 0 ? Math.round((scheduled / active) * 1000) / 10 : 0

  return (
    <div className="space-y-2">
      {label && (
        <div className="flex items-center justify-between">
          <span className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
            {label}
          </span>
          <span className="text-xs font-medium text-gray-500 dark:text-gray-400">
            {percentage.toFixed(1)}%
          </span>
        </div>
      )}
      <div className="w-full bg-gray-200 dark:bg-slate-700 rounded-full h-3 overflow-hidden">
        <div
          className="h-full bg-primary-600 dark:bg-primary-500 rounded-full transition-all duration-500 ease-out"
          style={{ width: `${percentage}%` }}
        />
      </div>
      {showDetails && (
        <div className="flex items-center justify-between text-sm">
          <span className="text-gray-600 dark:text-gray-300">
            <span className="font-medium">{scheduled}</span> of <span className="font-medium">{active}</span> employees scheduled
          </span>
          {notScheduled > 0 && (
            <span className="text-gray-500 dark:text-gray-400">
              <span className="font-medium">{notScheduled}</span> employees still require scheduling
            </span>
          )}
        </div>
      )}
    </div>
  )
}

export default CoverageBar
