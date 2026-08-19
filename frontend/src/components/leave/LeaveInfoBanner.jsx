import { Info } from 'lucide-react'

/**
 * Subtle information banner that constantly reinforces the distinction
 * between the planning roster and actual leave applications.
 *
 * Spec point #22: "Use the UI to Constantly Reinforce the Difference"
 */
const LeaveInfoBanner = ({ financialYearName = '' }) => {
  return (
    <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg px-4 py-3 flex items-start space-x-3">
      <Info className="h-5 w-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" />
      <div className="text-sm">
        <p className="font-medium text-blue-900 dark:text-blue-200">
          Planning Roster
        </p>
        <p className="text-blue-800 dark:text-blue-300 mt-0.5">
          This roster records when employees are <strong>planned</strong> to take annual leave
          {financialYearName && ` for ${financialYearName}`}.
          It does not represent an approved leave application or actual leave taken.
        </p>
      </div>
    </div>
  )
}

export default LeaveInfoBanner
