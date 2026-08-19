import { FY_MONTHS, FY_MONTH_SHORT } from '../../constants/leaveConstants'

/**
 * Compact month pills in July → June financial-year order.
 *
 * Spec point #3: "Make July → June the Visual Identity"
 * Spec point #4: "The Roster Table Should Become a Planning Matrix"
 *
 * When a month is selected, it gets a filled background.
 * Unscheduled months are grey dots; scheduled months are green dots.
 */
const MonthPills = ({ selectedMonth, scheduledMonths = [], onChange, financialYear = '' }) => {
  const handleSelect = (month) => {
    onChange(month === selectedMonth ? '' : month)
  }

  return (
    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
      <div className="flex items-center gap-2">
        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
          {financialYear || 'Financial Year'}
        </span>
      </div>

      <div className="flex flex-wrap gap-1">
        {FY_MONTHS.map((month, idx) => {
          const short = FY_MONTH_SHORT[idx]
          const isScheduled = scheduledMonths.includes(month)
          const isSelected = selectedMonth === month
          const isCurrent = month === new Date().toLocaleString('default', { month: 'long' })

          let bgColor = 'bg-gray-100 dark:bg-slate-700'
          let textColor = 'text-gray-600 dark:text-gray-400'
          let dotColor = 'bg-gray-400 dark:bg-gray-500'

          if (isScheduled) {
            dotColor = 'bg-green-500 dark:bg-green-400'
          }
          if (isSelected) {
            bgColor = 'bg-primary-600 dark:bg-primary-500'
            textColor = 'text-white'
            dotColor = 'bg-white dark:bg-gray-100'
          }

          return (
            <button
              key={month}
              type="button"
              onClick={() => handleSelect(month)}
              className={`
                relative flex flex-col items-center justify-center w-10 h-12 rounded-lg
                text-xs font-medium transition-all duration-200
                ${bgColor} ${textColor}
                hover:bg-primary-100 dark:hover:bg-slate-600 hover:text-primary-700 dark:hover:text-primary-300
                ${isSelected ? 'ring-2 ring-primary-400 dark:ring-primary-300 shadow-md' : ''}
              `}
              title={month}
            >
              <span className="leading-tight">{short}</span>
              <span
                className={`
                  absolute -bottom-0.5 h-1.5 w-1.5 rounded-full
                  ${dotColor}
                `}
              />
            </button>
          )
        })}
      </div>
    </div>
  )
}

export default MonthPills
