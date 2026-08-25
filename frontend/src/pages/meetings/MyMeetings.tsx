import { useState, useEffect, useCallback } from 'react'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Tabs from '../../components/ui/Tabs'
import Modal from '../../components/ui/Modal'
import { CalendarCheck, Calendar, Clock, MapPin, Check, X, Hourglass, History, Users, FileText, User } from 'lucide-react'

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

interface MeetingDetails {
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
  invitations: Array<{
    id: number
    employee_id: number
    first_name: string
    last_name: string
    employee_id_no: string
    designation: string
    email: string
    response_status: string
    attendance_status: string
    responded_at: string | null
  }>
}

type TabId = 'scheduled' | 'confirmed' | 'past'

const MyMeetings = () => {
  const [meetings, setMeetings] = useState<MeetingInvitation[]>([])
  const [loading, setLoading] = useState(true)
  const [actionLoading, setActionLoading] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [activeTab, setActiveTab] = useState<TabId>('scheduled')

  // Meeting details modal state
  const [detailsModalOpen, setDetailsModalOpen] = useState(false)
  const [detailsLoading, setDetailsLoading] = useState(false)
  const [detailsError, setDetailsError] = useState('')
  const [selectedMeeting, setSelectedMeeting] = useState<MeetingDetails | null>(null)

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

  const fetchMeetingDetails = async (meetingId: number) => {
    setDetailsLoading(true)
    setDetailsError('')
    setDetailsModalOpen(true)
    try {
      const response = await api.get(`/meetings/${meetingId}`)
      setSelectedMeeting(response.data?.data || null)
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.error || 'Failed to load meeting details'
      setDetailsError(msg)
      setSelectedMeeting(null)
    } finally {
      setDetailsLoading(false)
    }
  }

  const closeDetailsModal = () => {
    setDetailsModalOpen(false)
    setSelectedMeeting(null)
    setDetailsError('')
  }

  const handleConfirm = async (meetingId: number) => {
    setActionLoading(meetingId)
    setError('')
    try {
      const response = await api.post(`/meetings/${meetingId}/confirm`)
      const invitation = response.data?.data
      if (invitation && invitation.response_status === 'accepted') {
        // Update local state with the persisted response from the backend
        setMeetings((prev) =>
          prev.map((m) =>
            m.id === meetingId
              ? {
                  ...m,
                  invitation: {
                    ...m.invitation,
                    response_status: invitation.response_status,
                    responded_at: invitation.responded_at,
                  },
                }
              : m
          )
        )
      } else {
        // The API response didn't contain the expected data — refetch to get the true state
        await fetchMyMeetings()
      }
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.error || 'Unable to accept meeting'
      setError(msg)
      // Refetch to ensure frontend state matches the database
      await fetchMyMeetings()
    } finally {
      setActionLoading(null)
    }
  }

  const handleDecline = async (meetingId: number) => {
    setActionLoading(meetingId)
    setError('')
    try {
      const response = await api.post(`/meetings/${meetingId}/decline`)
      const invitation = response.data?.data
      if (invitation && invitation.response_status === 'declined') {
        // Update local state with the persisted response from the backend
        setMeetings((prev) =>
          prev.map((m) =>
            m.id === meetingId
              ? {
                  ...m,
                  invitation: {
                    ...m.invitation,
                    response_status: invitation.response_status,
                    responded_at: invitation.responded_at,
                  },
                }
              : m
          )
        )
      } else {
        // The API response didn't contain the expected data — refetch to get the true state
        await fetchMyMeetings()
      }
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.error || 'Unable to decline meeting'
      setError(msg)
      // Refetch to ensure frontend state matches the database
      await fetchMyMeetings()
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

  const getAttendanceBadge = (status: string) => {
    const variants: Record<string, 'default' | 'primary' | 'success' | 'warning' | 'danger'> = {
      present: 'success',
      absent: 'danger',
      late: 'warning',
      not_marked: 'default',
    }
    return (
      <Badge variant={variants[status] || 'default'}>
        {(status || 'not_marked').replace('_', ' ').charAt(0).toUpperCase() + (status || 'not_marked').replace('_', ' ').slice(1)}
      </Badge>
    )
  }

  const isPastMeeting = (m: MeetingInvitation) => {
    if (m.status === 'completed' || m.status === 'cancelled') {
      return true
    }
    if (!m.meeting_date) return false
    // Check if the meeting's end date/time has already passed
    const endDateTime = new Date(`${m.meeting_date}T${m.end_time || '00:00'}`)
    return endDateTime < new Date()
  }

  const getFilteredMeetings = useCallback(() => {
    switch (activeTab) {
      case 'scheduled':
        // Meetings where the user's response is pending and the meeting has not occurred
        return meetings.filter(
          (m) => !isPastMeeting(m) && (m.invitation?.response_status || 'pending') === 'pending'
        )
      case 'confirmed':
        // Accepted invitations (confirmed attendance)
        return meetings.filter((m) => m.invitation?.response_status === 'accepted' && !isPastMeeting(m))
      case 'past':
        return meetings.filter(isPastMeeting)
      default:
        return meetings
    }
  }, [activeTab, meetings])

  const filteredMeetings = getFilteredMeetings()

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
        // A meeting whose end date/time has already passed can no longer be
        // accepted or declined, regardless of the current response status.
        const isPast = isPastMeeting(row)

        if (isCancelled || isCompleted || isOngoing || isPast) {
          return (
            <Button
              size="sm"
              variant="outline"
              onClick={() => fetchMeetingDetails(row.id)}
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
                onClick={() => fetchMeetingDetails(row.id)}
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
                onClick={() => fetchMeetingDetails(row.id)}
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
              variant="outline"
              onClick={() => fetchMeetingDetails(row.id)}
            >
              View Details
            </Button>
            <Button
              size="sm"
              variant="success"
              onClick={() => handleConfirm(row.id)}
              loading={actionLoading === row.id}
            >
              <Check className="h-4 w-4 mr-1" />
              Accept
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

  const tabs = [
    {
      id: 'scheduled',
      name: `Scheduled (${meetings.filter((m) => !isPastMeeting(m) && (m.invitation?.response_status || 'pending') === 'pending').length})`,
      icon: <CalendarCheck className="h-4 w-4" />,
    },
    {
      id: 'confirmed',
      name: `Confirmed (${meetings.filter((m) => m.invitation?.response_status === 'accepted' && !isPastMeeting(m)).length})`,
      icon: <Check className="h-4 w-4" />,
    },
    {
      id: 'past',
      name: `Past (${meetings.filter(isPastMeeting).length})`,
      icon: <History className="h-4 w-4" />,
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
      </div>

      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
          {error}
        </div>
      )}

      {/* Tabs */}
      <Tabs
        tabs={tabs}
        activeTab={activeTab}
        onChange={(tabId) => setActiveTab(tabId as TabId)}
        variant="pills"
      />

      {filteredMeetings.length === 0 ? (
        <Card>
          <div className="text-center py-12">
            <Calendar className="h-12 w-12 mx-auto text-gray-300 dark:text-gray-600 mb-4" />
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">No meetings found</h3>
            <p className="text-gray-500 dark:text-gray-400 mt-2">
              {activeTab === 'scheduled'
                ? 'You have no upcoming scheduled meetings.'
                : activeTab === 'confirmed'
                  ? 'You have not confirmed any meetings yet.'
                  : 'You have no past meetings.'}
            </p>
          </div>
        </Card>
      ) : (
        <Card>
          <Table columns={columns} data={filteredMeetings} />
        </Card>
      )}

      {/* Meeting Details Modal */}
      <Modal
        isOpen={detailsModalOpen}
        onClose={closeDetailsModal}
        title={selectedMeeting?.title || 'Meeting Details'}
        size="lg"
      >
        {detailsLoading ? (
          <div className="flex items-center justify-center py-12">
            <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-600"></div>
          </div>
        ) : detailsError ? (
          <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
            {detailsError}
          </div>
        ) : selectedMeeting ? (
          <div className="space-y-6">
            {/* Meeting info */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="flex items-start space-x-3">
                <div className="h-10 w-10 rounded-lg bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center flex-shrink-0">
                  <Calendar className="h-5 w-5 text-primary-600 dark:text-primary-400" />
                </div>
                <div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Date</p>
                  <p className="font-medium text-gray-900 dark:text-gray-100">{formatDate(selectedMeeting.meeting_date)}</p>
                </div>
              </div>
              <div className="flex items-start space-x-3">
                <div className="h-10 w-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                  <Clock className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Time</p>
                  <p className="font-medium text-gray-900 dark:text-gray-100">
                    {formatTime(selectedMeeting.start_time)} - {formatTime(selectedMeeting.end_time)}
                  </p>
                </div>
              </div>
              <div className="flex items-start space-x-3">
                <div className="h-10 w-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center flex-shrink-0">
                  <MapPin className="h-5 w-5 text-green-600 dark:text-green-400" />
                </div>
                <div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Location</p>
                  <p className="font-medium text-gray-900 dark:text-gray-100">{selectedMeeting.location}</p>
                </div>
              </div>
              <div className="flex items-start space-x-3">
                <div className="h-10 w-10 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0">
                  <User className="h-5 w-5 text-purple-600 dark:text-purple-400" />
                </div>
                <div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Organizer</p>
                  <p className="font-medium text-gray-900 dark:text-gray-101">
                    {selectedMeeting.org_first_name} {selectedMeeting.org_last_name}
                  </p>
                </div>
              </div>
            </div>

            {/* Status */}
            <div className="flex items-center space-x-2">
              <span className="text-sm text-gray-500 dark:text-gray-400">Status:</span>
              {getStatusBadge(selectedMeeting.status)}
            </div>

            {/* Description */}
            {selectedMeeting.description && (
              <div>
                <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center">
                  <FileText className="h-4 w-4 mr-1 text-gray-400" />
                  Description
                </h4>
                <p className="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{selectedMeeting.description}</p>
              </div>
            )}

            {/* Agenda */}
            {selectedMeeting.agenda && (
              <div>
                <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-101 mb-2 flex items-center">
                  <FileText className="h-4 w-4 mr-1 text-gray-400" />
                  Agenda
                </h4>
                <p className="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{selectedMeeting.agenda}</p>
              </div>
            )}

            {/* Invitations / Participants */}
            <div>
              <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-101 mb-3 flex items-center">
                <Users className="h-4 w-4 mr-1 text-gray-400" />
                Invited Employees ({selectedMeeting.invitations?.length || 0})
              </h4>
              {selectedMeeting.invitations && selectedMeeting.invitations.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                    <thead className="bg-gray-50 dark:bg-slate-700/50">
                      <tr>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Designation</th>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Response</th>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Attendance</th>
                      </tr>
                    </thead>
                    <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                      {selectedMeeting.invitations.map((inv) => (
                        <tr key={inv.id}>
                          <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
                            {inv.first_name} {inv.last_name}
                          </td>
                          <td className="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{inv.designation || '-'}</td>
                          <td className="px-4 py-2">{getResponseBadge(inv.response_status || 'pending')}</td>
                          <td className="px-4 py-2">{getAttendanceBadge(inv.attendance_status || 'not_marked')}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-sm text-gray-500 dark:text-gray-400">No employees have been invited to this meeting.</p>
              )}
            </div>
          </div>
        ) : null}
      </Modal>
    </div>
  )
}

export default MyMeetings
