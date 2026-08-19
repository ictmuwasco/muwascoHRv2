// Status colors used across the Manage Leave tabs.
import Button from '../components/ui/Button'

export const STATUS_COLORS = {
  approved: 'bg-green-100 text-green-800',
  rejected: 'bg-red-100 text-red-800',
  invalidated: 'bg-gray-200 text-gray-800',
  pending: 'bg-yellow-100 text-yellow-800',
  pending_subsection_head: 'bg-yellow-100 text-yellow-800',
  pending_section_head: 'bg-yellow-100 text-yellow-800',
  pending_dept_head: 'bg-yellow-100 text-yellow-800',
  pending_managing_director: 'bg-yellow-100 text-yellow-800',
  pending_bod_chair: 'bg-yellow-100 text-yellow-800',
  pending_hr: 'bg-yellow-100 text-yellow-800',
  cancelled: 'bg-gray-100 text-gray-700',
}

export const STATUS_LABEL = {
  approved: 'Approved',
  rejected: 'Rejected',
  invalidated: 'Invalidated',
  pending: 'Pending',
  pending_subsection_head: 'Pending Subsection Head',
  pending_section_head: 'Pending Section Head',
  pending_dept_head: 'Pending Department Head',
  pending_managing_director: 'Pending Managing Director',
  pending_bod_chair: 'Pending BOD Chair',
  pending_hr: 'Pending HR',
  cancelled: 'Cancelled',
}

export const formatStatus = (status) =>
  STATUS_LABEL[status] ||
  (status ? status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '—')

export const badgeClass = (status) =>
  STATUS_COLORS[status] || 'bg-gray-100 text-gray-700'

export const formatDate = (date) => {
  if (!date) return '—'
  const d = new Date(date)
  if (Number.isNaN(d.getTime())) return date
  return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' })
}

export const ROWS_PER_PAGE = 15

export const Pagination = ({ pages, offset, onChange }) => {
  if (pages <= 1) return null
  const currentPage = Math.floor(offset / ROWS_PER_PAGE) + 1
  return (
    <div className="flex items-center justify-between mt-4 text-sm">
      <span className="text-gray-500 dark:text-gray-400">Page {currentPage} of {pages}</span>
      <div className="flex space-x-2">
        <Button
          size="sm"
          variant="outline"
          disabled={currentPage <= 1}
          onClick={() => onChange(Math.max(0, offset - ROWS_PER_PAGE))}
        >
          Previous
        </Button>
        <Button
          size="sm"
          variant="outline"
          disabled={currentPage >= pages}
          onClick={() => onChange(offset + ROWS_PER_PAGE)}
        >
          Next
        </Button>
      </div>
    </div>
  )
}
