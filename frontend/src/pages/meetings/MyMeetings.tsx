import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import { CalendarCheck, Calendar, Clock, MapPin, Users, Check, X } from 'lucide-react'

interface MeetingInvitation {
  id: number
  title: string
  description: string
  agenda: string
  meeting_date: string
  start_time: string
  end_time: string
  location: string
  status: string
  organizer_name: string | null
  invitation: {
    response_status: string
    attendance_status: string
    responded_at: string | null
  }
  attendee_count: number
  total_invited: number
}

const MyMeetings = () => {
  const navigate = useNavigate()
  const [meetings, setMeetings] = useState<MeetingInvitation[]>([])
  const [loading, setLoading] = useState(true)
  const [actionLoading, setActionLoading] = useState<number | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    fetchMyMeetings()
  }, [])

  const fetchMyMeetings = async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/my-meetings')
      setMeetings(response.data?.data || [])
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.error || 'Failed to load meetings'
      setError(msg)
    } finally {
      setLoading(false)
    }
  }

  const handleConfirm = async (meetingId: number) => {
    setActionLoading(meetingId)
    try {
      await api.post(`/meetings/${meetingId}/confirm`)
      setMeetings((prev) =>
        prev.map((m) =>
          m.id === meetingId
            ? {
                ...m,
                invitation: {
                  ...m.invitation,
                  response_status: 'accepted',
                  responded_at: new Date().toISOString(),
                },
              }
            : m
        )
      )
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to confirm attendance'
      setError(msg)
    } finally {
      setActionLoading(null)
    }
  }

  const handleDecline = async (meetingId: number) => {
    setActionLoading(meetingId)
    try {
      await api.post(`/meetings/${meetingId}/decline`)
      setMeetings((prev) =>
        prev.map((m) =>
          m.id === meetingId
            ? {
                ...m,
                invitation: {
                  ...m.invitation,
                  response_status: 'declined',
                  responded_at: new Date().toISOString(),
                },
              }
            : m
        )
      )
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to decline invitation'
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

  const getResponseBadge = (response: string) => {
    const variants: Record<string, 'default' | 'primary' | 'success' | 'warning' | 'danger'> = {
      pending: 'warning',
      accepted: 'success',
      declined: 'danger',
      tentative: 'default',
    }
    return (
      <Badge variant={variants[response] || 'default'}>
        {response.charAt(0).toUpperCase() + response.slice(1)}
      </Badge>
    )
  }

  const columns = [
    {
      key: 'title',
      label: 'Meeting',
      render: (value: string, row: MeetingInvitation) => (
        <div>
          <div className="font-medium text-gray-900 dark:text-gray-100">{value}</div>
          <div className="text-sm text-gray-500 dark:text-gray-400">
            {row.organizer_name && `Organized by ${row.organizer_name}`}
          </div>
        </div>
      ),
    },
    {
      key: 'meeting_date',
      label: 'Date',
      render: (value: string, row: MeetingInvitation) => (
        <div className="flex items-center text-sm">
          <Calendar className="h-4 w-4 mr-1 text-gray-400" />
          {formatDate(value)}
        </div>
      ),
    },
    {
      key: 'start_time',
      label: 'Time',
      render: (value: string, row: MeetingInvitation) => (
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
      key: 'invitation',
      label: 'Your Response',
      render: (_: any, row: MeetingInvitation) => getResponseBadge(row.invitation?.response_status || 'pending'),
    },
    {
      key: 'id',
      label: 'Actions',
      render: (_: any, row: MeetingInvitation) => {
        const response = row.invitation?.response_status
        const isCancelled = row.status === 'cancelled'
        const isCompleted = row.status === 'completed'
        const isOngoing = row.status === 'ongoing'

        if (isCancelled || isCompleted || isOngoing) {
          return (
            <Button
              size="sm"
              variant="outline"
              onClick={() => navigate(`/meetings/${row.id}/details`)}
            >
              View Details
            </Button>
          )
        }

        if (response === 'accepted') {
          return (
            <div className="flex items-center space-x-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => navigate(`/meetings/${row.id}/details`)}
              >
                View Details
              </Button>
              <Button
                size="sm"
                variant="secondary"
                onClick={() => handleDecline(row.id)}
                loading={actionLoading === row.id}
              >
                Decline
              </Button>
            </div>
          )
        }

        if (response === 'declined') {
          return (
            <div className="flex items-center space-x-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => navigate(`/meetings/${row.id}/details`)}
              >
                View Details
              </Button>
              <Button
                size="sm"
                variant="success"
                onClick={() => handleConfirm(row.id)}
                loading={actionLoading === row.id}
              >
                Accept
              </Button>
            </div>
          )
        }

        return (
          <div className="flex items-center space-x-2">
            <Button
              size="sm"
              variant="success"
              onClick={() => handleConfirm(row.id)}
              loading={actionLoading === row.id}
            >
              <Check className="h-4 w-4 mr-1" />
              Confirm
            </Button>
            <Button
              size="sm"
              variant="danger"
              onClick={() => handleDecline(row.id)}
              loading={actionLoading === row.id}
            >
              <X className="h-4 w-4 mr-1" />
              Decline
            </Button>
          </div>
        )
      },
    },
  ]

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">My Meetings</h1>
          <p className="text-gray-500">Meetings you have been invited to</p>
        </div>
        <Button onClick={() => navigate('/meetings')}>
          <CalendarCheck className="h-4 w-4 mr-2" />
          All Meetings
        </Button>
      </div>

      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
          {error}
        </div>
      )}

      {meetings.length === 0 ? (
        <Card>
          <div className="text-center py-12">
            <Calendar className="h-12 w-12 mx-auto text-gray-300 dark:text-gray-600 mb-4" />
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">No meetings found</h3>
            <p className="text-gray-500 dark:text-gray-400 mt-2">You have no meeting invitations at this time.</p>
          </div>
        </Card>
      ) : (
        <Card>
          <Table columns={columns} data={meetings} />
        </Card>
      )}
    </div>
  )
}

export default MyMeetings

