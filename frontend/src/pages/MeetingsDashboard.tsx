import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Table from '../components/ui/Table'
import Badge from '../components/ui/Badge'
import Button from '../components/ui/Button'
import Input from '../components/ui/Input'
import Select from '../components/ui/Select'
import {
  CalendarDays,
  Plus,
  Search,
  Filter,
  Calendar,
  Clock,
  MapPin,
  Users,
  Edit,
  Trash2,
  Eye,
  X,
} from 'lucide-react'

interface Meeting {
  id: number
  title: string
  description: string
  agenda: string
  meeting_date: string
  start_time: string
  end_time: string
  location: string
  status: string
  created_by: number
  org_first_name: string
  org_last_name: string
}

interface Pagination {
  total: number
  per_page: number
  current_page: number
  last_page: number
}

const STATUS_OPTIONS = [
  { value: '', label: 'All Statuses' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'ongoing', label: 'Ongoing' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

const MeetingsDashboard = () => {
  const navigate = useNavigate()
  const [meetings, setMeetings] = useState<Meeting[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [pagination, setPagination] = useState<Pagination>({
    total: 0,
    per_page: 20,
    current_page: 1,
    last_page: 1,
  })
  const [actionLoading, setActionLoading] = useState<number | null>(null)

  const fetchMeetings = async (page = 1) => {
    setLoading(true)
    setError('')
    try {
      const params: Record<string, any> = { page, per_page: pagination.per_page }
      if (statusFilter) params.status = statusFilter
      if (dateFrom) params.date_from = dateFrom
      if (dateTo) params.date_to = dateTo
      if (searchTerm) params.search = searchTerm

      const response = await api.get('/meetings', { params })
      setMeetings(response.data?.data || [])
      setPagination({
        total: response.data?.total || 0,
        per_page: response.data?.per_page || 20,
        current_page: response.data?.current_page || 1,
        last_page: response.data?.last_page || 1,
      })
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.error || 'Failed to load meetings'
      setError(msg)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchMeetings(1)
  }, [])

  const handleSearch = () => {
    fetchMeetings(1)
  }

  const handleClearFilters = () => {
    setSearchTerm('')
    setStatusFilter('')
    setDateFrom('')
    setDateTo('')
    fetchMeetings(1)
  }

  const handleCancel = async (meetingId: number) => {
    if (!window.confirm('Are you sure you want to cancel this meeting?')) return
    setActionLoading(meetingId)
    try {
      await api.post(`/meetings/${meetingId}/cancel`)
      setMeetings((prev) =>
        prev.map((m) => (m.id === meetingId ? { ...m, status: 'cancelled' } : m))
      )
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to cancel meeting'
      setError(msg)
    } finally {
      setActionLoading(null)
    }
  }

  const formatDate = (dateStr: string) => {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    return d.toLocaleDateString('en-GB', {
      weekday: 'short',
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  }

  const formatTime = (timeStr: string) => {
    if (!timeStr) return ''
    const [hours, minutes] = timeStr.split(':')
    const h = parseInt(hours, 10)
    const ampm = h >= 12 ? 'PM' : 'AM'
    const h12 = h % 12 || 12
    return `${h12}:${minutes} ${ampm}`
  }

  const getStatusBadge = (status: string) => {
    const variants: Record<string, 'default' | 'primary' | 'success' | 'warning' | 'danger'> = {
      scheduled: 'primary',
      ongoing: 'warning',
      completed: 'success',
      cancelled: 'danger',
    }
    return (
      <Badge variant={variants[status] || 'default'}>
        {status.charAt(0).toUpperCase() + status.slice(1)}
      </Badge>
    )
  }

  const columns = [
    {
      key: 'title',
      label: 'Meeting',
      render: (value: string, row: Meeting) => (
        <div>
          <div className="font-medium text-gray-900 dark:text-gray-100">{value}</div>
          <div className="text-sm text-gray-500 dark:text-gray-400">
            {row.org_first_name && `${row.org_first_name} ${row.org_last_name}`}
          </div>
        </div>
      ),
    },
    {
      key: 'meeting_date',
      label: 'Date',
      render: (value: string) => (
        <div className="flex items-center text-sm">
          <Calendar className="h-4 w-4 mr-1 text-gray-400" />
          {formatDate(value)}
        </div>
      ),
    },
    {
      key: 'start_time',
      label: 'Time',
      render: (value: string, row: Meeting) => (
        <div className="flex items-center text-sm">
          <Clock className="h-4 w-4 mr-1 text-gray-400" />
          {formatTime(value)} - {formatTime(row.end_time)}
        </div>
      ),
    },
    {
      key: 'location',
      label: 'Location',
      render: (value: string) => (
        <div className="flex items-center text-sm">
          <MapPin className="h-4 w-4 mr-1 text-gray-400" />
          {value}
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (value: string) => getStatusBadge(value),
    },
    {
      key: 'id',
      label: 'Actions',
      render: (_: any, row: Meeting) => (
        <div className="flex items-center space-x-2">
          <button
            onClick={() => navigate(`/meetings/${row.id}/details`)}
            className="p-1 text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 rounded"
            title="View Details"
          >
            <Eye className="h-4 w-4" />
          </button>
          {row.status !== 'completed' && row.status !== 'cancelled' && (
            <button
              onClick={() => navigate(`/meetings/${row.id}/edit`)}
              className="p-1 text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 rounded"
              title="Edit Meeting"
            >
              <Edit className="h-4 w-4" />
            </button>
          )}
          {row.status === 'scheduled' && (
            <button
              onClick={() => handleCancel(row.id)}
              className="p-1 text-gray-600 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 rounded"
              title="Cancel Meeting"
              disabled={actionLoading === row.id}
            >
              <Trash2 className="h-4 w-4" />
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Meetings Dashboard</h1>
          <p className="text-gray-500">Manage all scheduled meetings</p>
        </div>
        <Button onClick={() => navigate('/meetings/create')}>
          <Plus className="h-4 w-4 mr-2" />
          Create Meeting
        </Button>
      </div>

      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
          {error}
        </div>
      )}

      <Card title="Filters">
        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
          <div className="md:col-span-2">
            <Input
              label="Search"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Search by title or location..."
            />
          </div>
          <Select
            label="Status"
            options={STATUS_OPTIONS}
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
          />
          <Input
            label="From Date"
            type="date"
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
          />
          <Input
            label="To Date"
            type="date"
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
          />
        </div>
        <div className="flex items-center justify-end space-x-3 mt-4">
          <Button variant="outline" size="sm" onClick={handleClearFilters}>
            <X className="h-4 w-4 mr-1" />
            Clear
          </Button>
          <Button size="sm" onClick={handleSearch} loading={loading}>
            <Search className="h-4 w-4 mr-1" />
            Apply
          </Button>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="flex items-center justify-center h-64">
            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
          </div>
        ) : meetings.length === 0 ? (
          <div className="text-center py-12">
            <CalendarDays className="h-12 w-12 mx-auto text-gray-300 dark:text-gray-600 mb-4" />
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">No meetings found</h3>
            <p className="text-gray-500 dark:text-gray-400 mt-2">
              {searchTerm || statusFilter || dateFrom || dateTo
                ? 'Try adjusting your filters.'
                : 'No meetings have been scheduled yet.'}
            </p>
          </div>
        ) : (
          <>
            <Table columns={columns} data={meetings} />
            <div className="flex items-center justify-between mt-4 px-2">
              <div className="text-sm text-gray-500 dark:text-gray-400">
                Showing {((pagination.current_page - 1) * pagination.per_page) + 1} -{' '}
                {Math.min(pagination.current_page * pagination.per_page, pagination.total)} of {pagination.total}
              </div>
              <div className="flex items-center space-x-2">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={pagination.current_page <= 1 || loading}
                  onClick={() => fetchMeetings(pagination.current_page - 1)}
                >
                  Previous
                </Button>
                <span className="text-sm text-gray-700 dark:text-gray-300">
                  Page {pagination.current_page} of {pagination.last_page}
                </span>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={pagination.current_page >= pagination.last_page || loading}
                  onClick={() => fetchMeetings(pagination.current_page + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  )
}

export default MeetingsDashboard
