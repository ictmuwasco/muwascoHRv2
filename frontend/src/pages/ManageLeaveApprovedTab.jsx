import { useState, useEffect } from 'react'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Button from '../components/ui/Button'
import { badgeClass, formatDate, formatStatus, ROWS_PER_PAGE, Pagination } from './leaveManageShared.jsx'

const ApprovedTab = () => {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [offset, setOffset] = useState(0)
  const [count, setCount] = useState(0)

  useEffect(() => {
    fetchRows()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offset])

  const fetchRows = async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/leave/manage', {
        params: { limit: ROWS_PER_PAGE, approved_offset: offset },
      })
      const data = response.data?.data || {}
      setRows(data.approved || [])
      setCount(data.counts?.approved ?? 0)
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load approved leaves.')
    } finally {
      setLoading(false)
    }
  }

  const renderRows = () => {
    if (!rows.length) {
      return (
        <tr>
          <td colSpan={6} className="px-4 py-8 text-center text-gray-500">
            No approved leaves on record.
          </td>
        </tr>
      )
    }
    return rows.map((row) => (
      <tr key={row.id} className="border-t">
        <td className="px-4 py-2">
          <div className="font-medium text-gray-900">{row.first_name} {row.last_name}</div>
          <div className="text-xs text-gray-500">{row.emp_no || row.employee_id}</div>
        </td>
        <td className="px-4 py-2">{row.leave_type_name}</td>
        <td className="px-4 py-2 text-sm">
          {formatDate(row.start_date)} → {formatDate(row.end_date)}
        </td>
        <td className="px-4 py-2 text-sm">{row.days_requested || '—'}</td>
        <td className="px-4 py-2">
          <span className={`px-2 py-1 rounded-full text-xs font-medium ${badgeClass(row.status)}`}>
            {formatStatus(row.status)}
          </span>
        </td>
        <td className="px-4 py-2 text-sm">
          <div className="text-gray-900">{row.approver_name || 'System'}</div>
          <div className="text-xs text-gray-500">{formatDate(row.action_date)}</div>
        </td>
      </tr>
    ))
  }

  const pages = Math.max(1, Math.ceil(count / ROWS_PER_PAGE))

  return (
    <Card>
      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-4">
          {error}
        </div>
      )}
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead>
            <tr className="text-left text-gray-600">
              <th className="px-4 py-2">Employee</th>
              <th className="px-4 py-2">Leave Type</th>
              <th className="px-4 py-2">Dates</th>
              <th className="px-4 py-2">Days</th>
              <th className="px-4 py-2">Status</th>
              <th className="px-4 py-2">Final Approver</th>
            </tr>
          </thead>
          <tbody>{renderRows()}</tbody>
        </table>
      </div>
      <Pagination
        pages={pages}
        offset={offset}
        onChange={(newOffset) => setOffset(newOffset)}
      />
    </Card>
  )
}

export default ApprovedTab
