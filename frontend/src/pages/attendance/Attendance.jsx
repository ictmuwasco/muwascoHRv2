import { useState, useEffect, useRef } from 'react'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Badge from '../../components/ui/Badge'
import { ChevronLeft, ChevronRight } from 'lucide-react'

const PER_PAGE = 50

const Attendance = () => {
  const [attendance, setAttendance] = useState([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const [total, setTotal] = useState(0)
  const requestIdRef = useRef(0)

  useEffect(() => {
    const requestId = ++requestIdRef.current
    fetchAttendance(requestId)
  }, [page])

  const fetchAttendance = async (requestId) => {
    try {
      const params = {
        page,
        limit: PER_PAGE
      }
      const response = await api.get('/attendance/my-records', { params })
      
      // Ignore stale responses from previous page requests
      if (requestId !== requestIdRef.current) return
      
      const data = response.data?.data
      // Handle both paginated {data: [...], total, page} and plain array formats
      const attendanceList = Array.isArray(data) ? data : (data?.data || [])
      const totalCount = Array.isArray(data) ? data.length : (data?.total || attendanceList.length)
      
      setAttendance(attendanceList)
      setTotal(totalCount)
      setTotalPages(Math.ceil(totalCount / PER_PAGE))
    } catch (error) {
      if (requestId === requestIdRef.current) {
        console.error('Failed to fetch attendance:', error)
      }
    } finally {
      if (requestId === requestIdRef.current) {
        setLoading(false)
      }
    }
  }

  const formatDateTime = (value) => {
    if (!value) return '-'
    return new Date(value).toLocaleString('en-KE', {
      year: 'numeric',
      month: 'short',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  const columns = [
    {
      key: 'employee_name',
      label: 'Employee',
      render: (value, row) => (
        <div>
          <div className="font-medium text-gray-900 dark:text-gray-100">{value}</div>
          <div className="text-xs text-gray-500 dark:text-gray-400">ID: {row.employee_id}</div>
        </div>
      ),
    },
    { key: 'department', label: 'Department' },
    {
      key: 'office_name',
      label: 'Office',
      render: (value, row) => (
        <div>
          <div>{value || '-'}</div>
          <div className="text-xs text-gray-500 dark:text-gray-400">
            In: {row.clock_in_office_id} | Out: {row.clock_out_office_id}
          </div>
        </div>
      ),
    },
    {
      key: 'clock_in',
      label: 'Clock In',
      render: (value) => <div className="text-sm">{formatDateTime(value)}</div>,
    },
    {
      key: 'clock_out',
      label: 'Clock Out',
      render: (value) => <div className="text-sm">{formatDateTime(value)}</div>,
    },
    {
      key: 'status',
      label: 'Status',
      render: (value, row) => {
        const variant = value === 'Clocked In' ? 'info' : 
                       value === 'Clocked Out' ? 'success' : 
                       value === 'Late' ? 'warning' : 
                       value === 'Auto Clocked Out' ? 'danger' : 'secondary'
        return (
          <div>
            <Badge variant={variant}>{value}</Badge>
            {row.is_late && <div className="text-xs text-yellow-600 dark:text-yellow-400 mt-1">Late</div>}
            {row.auto_clocked_out && <div className="text-xs text-red-600 dark:text-red-400 mt-1">Auto</div>}
          </div>
        )
      },
    },
    {
      key: 'location',
      label: 'GPS Location',
      render: (_, row) => (
        <div className="text-xs text-gray-600 dark:text-gray-400">
          {row.lat && row.lng ? (
            <>
              <div>Lat: {row.lat}</div>
              <div>Lng: {row.lng}</div>
              <div>Acc: {row.accuracy}m</div>
            </>
          ) : (
            '-'
          )}
        </div>
      ),
    },
    {
      key: 'timestamps',
      label: 'Created / Updated',
      render: (_, row) => (
        <div className="text-xs text-gray-500 dark:text-gray-400">
          <div>Created: {formatDateTime(row.created_at)}</div>
          <div>Updated: {formatDateTime(row.updated_at)}</div>
        </div>
      ),
    },
  ]

  if (loading) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Attendance Records</h1>
          <p className="text-gray-500 dark:text-gray-400">Individual clock-in and clock-out records</p>
        </div>
        <div className="flex items-center justify-center h-64">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Attendance Records</h1>
        <p className="text-gray-500 dark:text-gray-400">Individual clock-in and clock-out records</p>
      </div>

      <Card>
        <Table columns={columns} data={attendance} />

        {/* Pagination */}
        <div className="flex items-center justify-between mt-4 px-2 py-3">
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Showing {attendance.length > 0 ? ((page - 1) * PER_PAGE) + 1 : 0} to{' '}
            {Math.min(page * PER_PAGE, total)} of {total} records
          </p>
          <div className="flex items-center space-x-2">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage(page - 1)}
              className="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-slate-600 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <ChevronLeft className="h-4 w-4 mr-1" />
              Previous
            </button>
            <span className="text-sm text-gray-700 dark:text-gray-200">
              Page {page} of {Math.max(totalPages, 1)}
            </span>
            <button
              type="button"
              disabled={page >= totalPages}
              onClick={() => setPage(page + 1)}
              className="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-slate-600 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Next
              <ChevronRight className="h-4 w-4 ml-1" />
            </button>
          </div>
        </div>
      </Card>
    </div>
  )
}

export default Attendance

