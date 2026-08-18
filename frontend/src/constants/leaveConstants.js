/**
 * Leave Roster shared constants.
 *
 * IMPORTANT: Financial year months run July → June, NOT January → December.
 * Every component that displays months must use this ordering.
 */
export const FY_MONTHS = [
  'July', 'August', 'September', 'October', 'November', 'December',
  'January', 'February', 'March', 'April', 'May', 'June',
]

export const FY_MONTH_SHORT = [
  'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC',
  'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN',
]

/**
 * Returns the FY month list starting from a given month.
 * Used for the month pills / matrix where the selected month is highlighted.
 */
export const getFyMonthsFrom = (startMonth) => {
  const idx = FY_MONTHS.indexOf(startMonth)
  if (idx === -1) return FY_MONTHS
  return [...FY_MONTHS.slice(idx), ...FY_MONTHS.slice(0, idx)]
}
