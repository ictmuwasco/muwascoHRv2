import { FY_MONTH_SHORT } from '../../constants/leaveConstants'

/**
 * Monthly distribution bar chart (July → June).
 *
 * Spec point #11: "Monthly Distribution Should Be the Main Chart"
 * Spec point #12: "Add 'Planning Attention' Rather Than Fake Rules"
 *
 * Props:
 *   - distribution: array of { month, count }
 *   - highest: { month, count } | null
 *   - lowest: { month, count } | null
 *   - maxHeight: number (max bar height in px)
 */
const DistributionChart = ({ distribution = [], highest = null, lowest = null, maxHeight = 120 }) => {
  const maxCount = Math.max(...distribution.map((d) => d.count), 1)

  return (
    <div className="space-y-4">
      {/* Chart */}
      <div className="flex items-end gap-2 h-40 px-2">
        {distribution.map((item, idx) => {
          const height = maxCount > 0 ? (item.count / maxCount) * maxHeight : 0
          const isHighest = highest && item.month === highest.month
          const isLowest = lowest && item.month === lowest.month

          let barColor = 'bg-gray-300 dark:bg-slate-600'
          if (item.count > 0) {
            barColor = 'bg-primary-500 dark:bg-primary-400'
          }
          if (isHighest) {
            barColor = 'bg-amber-500 dark:bg-amber-400'
          }
          if (isLowest && item.count > 0) {
            barColor = 'bg-blue-400 dark:bg-blue-300'
          }

          return (
            <div key={item.month} className="flex flex-col items-center flex-1 min-w-[32px]">
              <div
                className={`w-full rounded-t-sm transition-all duration-300 ${barColor}`}
                style={{ height: `${height}px` }}
                title={`${item.month}: ${item.count} employees`}
              />
              <span className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {FY_MONTH_SHORT[idx]}
              </span>
              <span className="text-xs font-medium text-gray-600 dark:text-gray-300">
                {item.count}
              </span>
            </div>
          )
        })}
      </div>

      {/* Planning Attention */}
      {(highest || lowest) && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t dark:border-slate-700">
          {highest && (
            <div className="flex items-center space-x-3">
              <div className="h-8 w-8 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                <span className="text-amber-600 dark:text-amber-400 text-sm">📊</span>
              </div>
              <div>
                <p className="text-sm font-medium text-gray-900 dark:text-gray-101">
                  Highest concentration
                </p>
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  {highest.month} · {highest.count} employees scheduled
                </p>
              </div>
            </div>
          )}
          {lowest && (
            <div className="flex items-center space-x-3">
              <div className="h-8 w-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                <span className="text-blue-600 dark:text-blue-400 text-sm">📉</span>
              </div>
              <div>
                <p className="text-sm font-medium text-gray-900 dark:text-gray-101">
                  Lowest concentration
                </p>
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  {lowest.month} · {lowest.count} employees scheduled
                </p>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export default DistributionChart
