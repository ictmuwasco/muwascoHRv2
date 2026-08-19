import { useState, useEffect } from 'react'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Button from '../components/ui/Button'
import { CheckCircle, XCircle, FileX, Inbox } from 'lucide-react'
import { badgeClass, formatDate, formatStatus, ROWS_PER_PAGE, Pagination } from './leaveManageShared.jsx'
import { useManageContext } from './ManageLeaveLayout.jsx'

const PendingTab = () => {
  const { refreshCounts } = useManageContext()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [offset, setOffset] = useState(0)
  const [count, setCount] = useState(0)
  const [modal, setModal] = useState({ open: false, action: null, row: null, reason: '' })
  const [banner, setBanner] = useState({ kind: '', message: '' })

  useEffect(() => {
    fetchRows()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offset])

  const fetchRows = async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/leave/manage', {
        params: { limit: ROWS_PER_PAGE, pending_offset: offset },
      })
      const data = response.data?.data || {}
      setRows(data.pending || [])
      setCount(data.counts?.pending ?? 0)
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load pending leaves.')
    } finally {
      setLoading(false)
    }
  }

  const openModal = (action, row) => {
    setModal({ open: true, action, row, reason: '' })
    setBanner({ kind: '', message: '' })
  }

  const closeModal = () => {
    setModal({ open: false, action: null, row: null, reason: '' })
  }

  const submitModal = async () => {
    if (!modal.row) return
    if ((modal.action === 'reject' || modal.action === 'invalidate') && !modal.reason.trim()) {
      setBanner({ kind: 'error', message: 'A reason is required.' })
      return
    }
    setLoading(true)
    try {
      const url = `/leave/applications/${modal.row.id}/${modal.action}`
      const payload = (modal.action === 'reject' || modal.action === 'invalidate')
        ? { reason: modal.reason.trim() }
        : undefined
      const response = modal.action === 'approve'
        ? await api.put(url)
        : await api.put(url, payload)
      const data = response.data
      setBanner({
        kind: data?.success ? 'success' : 'error',
        message: data?.message || (data?.success ? 'Action completed.' : 'Action failed.'),
      })
      closeModal()
      setOffset(0)
      await fetchRows()
      if (typeof refreshCounts === 'function') refreshCounts()
    } catch (err) {
      setBanner({ kind: 'error', message: err.response?.data?.message || `Failed to ${modal.action} leave.` })
    } finally {
      setLoading(false)
    }
  }

  const renderRows = () => {
    if (!rows.length) {
      return (
        <tr>
          <td colSpan={7} className="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
            <Inbox className="h-6 w-6 mx-auto mb-2 text-gray-400 dark:text-gray-500" />
            No pending leave applications.
          </td>
        </tr>
      )
    }
    return rows.map((row) => {
      const stageLabel = row.pending_approver_label || 'Approver'
      const stageName = row.pending_approver_name || 'Not Assigned'
      return (
        <tr key={row.id} className="border-t">
          <td className="px-4 py-2">
            <div className="font-medium text-gray-900 dark:text-gray-100">{row.first_name} {row.last_name}</div>
            <div className="text-xs text-gray-500 dark:text-gray-400">{row.emp_no || row.employee_id}</div>
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
            <div className="text-gray-900 dark:text-gray-100">{stageLabel}</div>
            <div className="text-xs text-gray-500 dark:text-gray-400">{stageName}</div>
          </td>
          <td className="px-4 py-2">
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="success" onClick={() => openModal('approve', row)}>
                <CheckCircle className="h-3 w-3 mr-1" /> Approve
              </Button>
              <Button size="sm" variant="danger" onClick={() => openModal('reject', row)}>
                <XCircle className="h-3 w-3 mr-1" /> Reject
              </Button>
              <Button size="sm" variant="outline" onClick={() => openModal('invalidate', row)}>
                <FileX className="h-3 w-3 mr-1" /> Invalidate
              </Button>
            </div>
          </td>
        </tr>
      )
    })
  }

  const pages = Math.max(1, Math.ceil(count / ROWS_PER_PAGE))

  return (
    <>
      <Card>
        {error && (
          <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-md mb-4">
            {error}
          </div>
        )}
        {banner.message && (
          <div
            className={`px-4 py-3 rounded-md border mb-4 ${
              banner.kind === 'success'
                ? 'bg-green-50 border-green-200 text-green-700'
                : 'bg-red-50 border-red-200 text-red-700'
            }`}
          >
            {banner.message}
          </div>
        )}
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-gray-600 dark:text-gray-400">
                <th className="px-4 py-2">Employee</th>
                <th className="px-4 py-2">Leave Type</th>
                <th className="px-4 py-2">Dates</th>
                <th className="px-4 py-2">Days</th>
                <th className="px-4 py-2">Status</th>
                <th className="px-4 py-2">Stage</th>
                <th className="px-4 py-2">Actions</th>
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

      {modal.open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white dark:bg-slate-800 rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">
              {modal.action === 'approve' && 'Approve Leave'}
              {modal.action === 'reject' && 'Reject Leave'}
              {modal.action === 'invalidate' && 'Invalidate Leave'}
            </h3>
            {modal.row && (
              <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
                <strong>{modal.row.first_name} {modal.row.last_name}</strong> — {modal.row.leave_type_name}
                <br />
                {formatDate(modal.row.start_date)} → {formatDate(modal.row.end_date)}
              </p>
            )}
            {(modal.action === 'reject' || modal.action === 'invalidate') && (
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Reason *
                </label>
                <textarea
                  className="w-full border rounded-md px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                  rows={3}
                  value={modal.reason}
                  onChange={(e) => setModal({ ...modal, reason: e.target.value })}
                  placeholder="Briefly explain why…"
                  required
                />
              </div>
            )}
            {modal.action === 'approve' && (
              <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Approving this application will advance it to the next stage in the chain
                (or mark it fully approved if this is the final stage).
              </p>
            )}
            <div className="flex justify-end space-x-2">
              <Button variant="outline" onClick={closeModal}>Cancel</Button>
              <Button
                variant={
                  modal.action === 'approve' ? 'success'
                    : modal.action === 'reject' ? 'danger'
                    : 'secondary'
                }
                onClick={submitModal}
                disabled={loading}
              >
                {modal.action === 'approve' && 'Approve'}
                {modal.action === 'reject' && 'Reject'}
                {modal.action === 'invalidate' && 'Invalidate'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}

export default PendingTab
