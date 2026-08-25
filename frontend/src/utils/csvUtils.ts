/**
 * CSV generation and download utilities.
 * Provides proper escaping for commas, quotes, newlines, and null values.
 */

/**
 * Escape a single CSV field value.
 * Wraps in quotes if the value contains commas, quotes, or newlines.
 * Doubles up any embedded double quotes.
 */
export const escapeCsvField = (value: unknown): string => {
  if (value === null || value === undefined) {
    return ''
  }

  const str = String(value)
  if (str.includes(',') || str.includes('"') || str.includes('\n') || str.includes('\r')) {
    return `"${str.replace(/"/g, '""')}"`
  }
  return str
}

/**
 * Convert an array of objects to a CSV string.
 * @param headers - Array of column header names
 * @param rows - Array of row objects
 * @param getValue - Optional function to extract a value from a row for a given header
 */
export const toCsv = (
  headers: string[],
  rows: Record<string, unknown>[],
  getValue?: (row: Record<string, unknown>, header: string) => unknown
): string => {
  const headerLine = headers.map(escapeCsvField).join(',')

  const bodyLines = rows.map((row) => {
    return headers
      .map((header) => {
        const value = getValue ? getValue(row, header) : row[header]
        return escapeCsvField(value)
      })
      .join(',')
  })

  return [headerLine, ...bodyLines].join('\n')
}

/**
 * Trigger a browser download of a CSV string.
 * @param csv - The CSV content
 * @param filename - The download filename (e.g. 'meetings_2026-08-21.csv')
 */
export const downloadCsv = (csv: string, filename: string): void => {
  // Add BOM for proper Excel UTF-8 handling
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(url)
}

/**
 * Generate a filename with today's date.
 * @param prefix - e.g. 'meetings' or 'meetings_report'
 * @returns e.g. 'meetings_2026-08-21.csv'
 */
export const csvFilenameWithDate = (prefix: string): string => {
  const today = new Date()
  const year = today.getFullYear()
  const month = String(today.getMonth() + 1).padStart(2, '0')
  const day = String(today.getDate()).padStart(2, '0')
  return `${prefix}_${year}-${month}-${day}.csv`
}