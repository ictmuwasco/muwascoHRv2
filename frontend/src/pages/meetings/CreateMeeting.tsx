import { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Badge from '../../components/ui/Badge'
import Modal from '../../components/ui/Modal'
import { CalendarDays, Plus, Calendar, Clock, MapPin, Eye, Edit, Trash2, X, FileText } from 'lucide-react'
import MeetingMinutesModal, { MinutesMeetingInfo } from './MeetingMinutesModal'

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
  minutes?: { exists: boolean; can_manage: boolean; can_view_published: boolean; status: string | null }
}

interface Pagination {
  total: number
  per_page: number
  current_page: number
  last_page: number
}

interface MeetingFormState {
  title: string
  description: string
  agenda: string
  meeting_date: string
  start_time: string
  end_time: string
  location: string
}

const EMPTY_FORM: MeetingFormState = {
  title: '',
  description: '',
  agenda: '',
  meeting_date: '',
  start_time: '',
  end_time: '',
  location: '',
}

const CreateMeeting = () => {
  const navigate = useNavigate()
  const { id } = useParams()
  const [meetings, setMeetings] = useState<Meeting[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [pagination, setPagination] = useState<Pagination>({ total: 0, per_page: 20, current_page: 1, last_page: 1 })
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(id ? Number(id) : null)
  const [form, setForm] = useState<MeetingFormState>(EMPTY_FORM)
  const [selectedEmployees, setSelectedEmployees] = useState<number[]>([])
  const [employees, setEmployees] = useState<any[]>([])
  const [employeeSearch, setEmployeeSearch] = useState('')
    const [actionLoading, setActionLoading] = useState<number | null>(null)
  const [minutesMeeting, setMinutesMeeting] = useState<Meeting | null>(null)

  const loadMeetings = async (page = 1) => {
    setLoading(true)
    setError('')
    try {
      const params: Record<string, any> = { page, per_page: pagination.per_page }
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

  const loadEmployees = async () => {
    try {
      const response = await api.get('/meetings/eligible-employees')
      setEmployees(response.data?.data || [])
    } catch (err) {
      console.error('Failed to load employees:', err)
    }
  }

  const loadMeetingForEdit = async (meetingId: number) => {
    setError('')
    setSuccess('')
    try {
      const response = await api.get(`/meetings/${meetingId}`)
      const data = response.data?.data
      if (data) {
        setForm({
          title: data.title || '',
          description: data.description || '',
          agenda: data.agenda || '',
          meeting_date: data.meeting_date || '',
          start_time: data.start_time || '',
          end_time: data.end_time || '',
          location: data.location || '',
        })
        const invitedIds = (data.invitations || []).map((inv: any) => inv.employee_id)
        setSelectedEmployees(invitedIds)
        setEditingId(meetingId)
        setShowCreateModal(true)
      }
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to load meeting details'
      setError(msg)
    }
  }

  useEffect(() => {
    loadMeetings(1)
    loadEmployees()
    if (id) {
      loadMeetingForEdit(Number(id))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  const openCreateModal = () => {
    setForm(EMPTY_FORM)
    setSelectedEmployees([])
    setEmployeeSearch('')
    setEditingId(null)
    setError('')
    setSuccess('')
    setShowCreateModal(true)
  }

  const handleInputChange = (field: keyof MeetingFormState, value: string) => {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  const toggleEmployee = (employeeId: number) => {
    setSelectedEmployees((prev) =>
      prev.includes(employeeId) ? prev.filter((eid) => eid !== employeeId) : [...prev, employeeId]
    )
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    setSuccess('')
    setSaving(true)

    if (!form.title.trim()) { setError('Meeting title is required'); setSaving(false); return }
    if (!form.meeting_date) { setError('Meeting date is required'); setSaving(false); return }
    if (!form.start_time) { setError('Start time is required'); setSaving(false); return }
    if (!form.end_time) { setError('End time is required'); setSaving(false); return }
    if (!form.location.trim()) { setError('Location is required'); setSaving(false); return }
    if (!editingId && selectedEmployees.length === 0) { setError('At least one employee must be invited'); setSaving(false); return }

    try {
      if (editingId) {
        await api.put(`/meetings/${editingId}`, {
          ...form,
          employee_ids: selectedEmployees,
        })
        setSuccess('Meeting updated successfully')
      } else {
        await api.post('/meetings', {
          ...form,
          employee_ids: selectedEmployees,
        })
        setSuccess('Meeting created successfully')
      }
      setShowCreateModal(false)
      setEditingId(null)
      loadMeetings(1)
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.error || 'Failed to save meeting'
      setError(msg)
    } finally {
      setSaving(false)
    }
  }

  const handleCancel = async (meetingId: number) => {
    if (!window.confirm('Are you sure you want to cancel this meeting?')) return
    setActionLoading(meetingId)
    try {
      await api.post(`/meetings/${meetingId}/cancel`)
      setMeetings((prev) => prev.map((m) => (m.id === meetingId ? { ...m, status: 'cancelled' } : m)))
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to cancel meeting'
      setError(msg)
    } finally {
      setActionLoading(null)
    }
  }

  const handleDelete = async (meetingId: number) => {
    if (!window.confirm('Are you sure you want to permanently delete this meeting?')) return
    setActionLoading(meetingId)
    try {
      await api.delete(`/meetings/${meetingId}`)
      setMeetings((prev) => prev.filter((m) => m.id !== meetingId))
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to delete meeting'
      setError(msg)
    } finally {
      setActionLoading(null)
    }
  }

  const formatDate = (dateStr: string) => {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    return d.toLocaleDateString('en-GB', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' })
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
    return <Badge variant={variants[status] || 'default'}>{status.charAt(0).toUpperCase() + status.slice(1)}</Badge>
  }

  const filteredEmployees = employees.filter((emp) => {
    if (!employeeSearch.trim()) return true
    const term = employeeSearch.toLowerCase()
    return (
      emp.first_name?.toLowerCase().includes(term) ||
      emp.last_name?.toLowerCase().includes(term) ||
      emp.employee_id?.toLowerCase().includes(term) ||
      emp.designation?.toLowerCase().includes(term) ||
      emp.email?.toLowerCase().includes(term)
    )
  })

  const columns = [
    {
      key: 'title',
      label: 'Meeting',
      render: (value: string, row: Meeting) => (
        <div>
          <div className="font-medium text-gray-900 dark:text-gray-100">{value}</div>
          <div className="text-sm text-gray-500 dark:text-gray-400">
            {row.org_first_name ? `Organized by ${row.org_first_name} ${row.org_last_name}` : ''}
          </div>
        </div>
      ),
    },
    {
      key: 'meeting_date',
      label: 'Date',
      render: (value: string) => (
        <div className="flex items-center text-sm"><Calendar className="h-4 w-4 mr-1 text-gray-400" />{formatDate(value)}</div>
      ),
    },
    {
      key: 'start_time',
      label: 'Time',
      render: (value: string, row: Meeting) => (
        <div className="flex items-center text-sm"><Clock className="h-4 w-4 mr-1 text-gray-400" />{formatTime(value)} - {formatTime(row.end_time)}</div>
      ),
    },
    {
      key: 'location',
      label: 'Location',
      render: (value: string) => (
        <div className="flex items-center text-sm"><MapPin className="h-4 w-4 mr-1 text-gray-400" />{value}</div>
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
          <button onClick={() => navigate(`/meetings/${row.id}/details`)} className="p-1 text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 rounded" title="View Details">
            <Eye className="h-4 w-4" />
          </button>
          {row.status !== 'completed' && row.status !== 'cancelled' && (
            <button onClick={() => loadMeetingForEdit(row.id)} className="p-1 text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 rounded" title="Edit Meeting">
              <Edit className="h-4 w-4" />
            </button>
          )}
          {row.status === 'scheduled' && (
            <button onClick={() => handleCancel(row.id)} disabled={actionLoading === row.id} className="p-1 text-gray-600 dark:text-gray-400 hover:text-amber-600 dark:hover:text-amber-400 rounded" title="Cancel Meeting">
              <X className="h-4 w-4" />
            </button>
          )}
                    <button onClick={() => handleDelete(row.id)} disabled={actionLoading === row.id} className="p-1 text-gray-600 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 rounded" title="Delete Meeting">
            <Trash2 className="h-4 w-4" />
          </button>
          {(row.minutes?.can_manage || row.status === 'completed') && (
            <button onClick={() => setMinutesMeeting(row)} className="p-1 text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 rounded" title="Add Minutes">
                            <FileText className="h-4 w-4" />
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <div className="space-y-6">
      {/* Header with Create button on top right */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Meeting Management</h1>
          <p className="text-gray-500 dark:text-gray-400">Create, manage and schedule meetings</p>
        </div>
        <div className="flex items-center space-x-3">
          <Button onClick={openCreateModal}>
            <Plus className="h-4 w-4 mr-2" />
            Create Meeting
          </Button>
        </div>
      </div>

      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">{error}</div>
      )}
      {success && (
        <div className="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg">{success}</div>
      )}

      {/* Meetings table */}
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
              Click "Create Meeting" to schedule your first meeting.
            </p>
          </div>
        ) : (
          <>
            <Table columns={columns} data={meetings} />
            {pagination.last_page > 1 && (
              <div className="flex items-center justify-between mt-4 px-2">
                <div className="text-sm text-gray-500 dark:text-gray-400">
                  Showing {((pagination.current_page - 1) * pagination.per_page) + 1} - {Math.min(pagination.current_page * pagination.per_page, pagination.total)} of {pagination.total}
                </div>
                <div className="flex items-center space-x-2">
                  <Button size="sm" variant="outline" disabled={pagination.current_page <= 1 || loading} onClick={() => loadMeetings(pagination.current_page - 1)}>Previous</Button>
                  <span className="text-sm text-gray-700 dark:text-gray-300">Page {pagination.current_page} of {pagination.last_page}</span>
                  <Button size="sm" variant="outline" disabled={pagination.current_page >= pagination.last_page || loading} onClick={() => loadMeetings(pagination.current_page + 1)}>Next</Button>
                </div>
              </div>
            )}
          </>
        )}
      </Card>

      {/* Create / Edit Meeting Modal */}
      <Modal isOpen={showCreateModal} onClose={() => setShowCreateModal(false)} title={editingId ? 'Edit Meeting' : 'Create Meeting'} size="2xl">
        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="md:col-span-2">
              <Input label="Meeting Title *" value={form.title} onChange={(e) => handleInputChange('title', e.target.value)} placeholder="e.g. Weekly Department Meeting" required />
            </div>
            <div className="md:col-span-2">
              <Input label="Description" value={form.description} onChange={(e) => handleInputChange('description', e.target.value)} placeholder="Brief description of the meeting" />
            </div>
            <div className="md:col-span-2">
              <Input label="Agenda" value={form.agenda} onChange={(e) => handleInputChange('agenda', e.target.value)} placeholder="Agenda items (one per line)" />
            </div>
            <Input label="Meeting Date *" type="date" value={form.meeting_date} onChange={(e) => handleInputChange('meeting_date', e.target.value)} required />
            <Input label="Location *" value={form.location} onChange={(e) => handleInputChange('location', e.target.value)} placeholder="e.g. Conference Room A / Zoom" required />
            <Input label="Start Time *" type="time" value={form.start_time} onChange={(e) => handleInputChange('start_time', e.target.value)} required />
            <Input label="End Time *" type="time" value={form.end_time} onChange={(e) => handleInputChange('end_time', e.target.value)} required />
          </div>

          {/* Employee selection */}
          <div>
            <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Invite Employees {!editingId && '*'}</h4>
            <Input label="Search Employees" value={employeeSearch} onChange={(e) => setEmployeeSearch(e.target.value)} placeholder="Search by name, employee ID, or designation..." />
            <div className="max-h-60 overflow-y-auto border dark:border-slate-700 rounded-lg divide-y dark:divide-slate-700 mt-2">
              {filteredEmployees.length === 0 ? (
                <div className="p-4 text-center text-gray-500 dark:text-gray-400">No employees found</div>
              ) : (
                filteredEmployees.map((emp) => (
                  <label key={emp.id} className="flex items-center px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer">
                    <input type="checkbox" checked={selectedEmployees.includes(emp.id)} onChange={() => toggleEmployee(emp.id)} className="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded" />
                    <div className="ml-3">
                      <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{emp.first_name} {emp.last_name}</p>
                      <p className="text-xs text-gray-500 dark:text-gray-400">
                        {emp.designation && <span>{emp.designation} • </span>}
                        {emp.employee_id && <span>ID: {emp.employee_id}</span>}
                      </p>
                    </div>
                  </label>
                ))
              )}
            </div>
            {selectedEmployees.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-2">
                {employees.filter((emp) => selectedEmployees.includes(emp.id)).map((emp) => (
                  <span key={emp.id} className="inline-flex items-center px-3 py-1 rounded-full bg-primary-50 dark:bg-slate-700 text-primary-700 dark:text-primary-300 text-sm">
                    {emp.first_name} {emp.last_name}
                    <button type="button" onClick={() => toggleEmployee(emp.id)} className="ml-2 text-primary-600 dark:text-primary-400 hover:text-red-500">×</button>
                  </span>
                ))}
              </div>
            )}
          </div>

          <div className="flex items-center justify-end space-x-3">
            <Button type="button" variant="outline" onClick={() => setShowCreateModal(false)}>Cancel</Button>
            <Button type="submit" loading={saving}>{editingId ? 'Update Meeting' : 'Create Meeting'}</Button>
          </div>
        </form>
            </Modal>

      {minutesMeeting && (
        <MeetingMinutesModal
          meeting={{
            id: minutesMeeting.id,
            title: minutesMeeting.title,
            meeting_date: minutesMeeting.meeting_date,
            start_time: minutesMeeting.start_time,
            end_time: minutesMeeting.end_time,
            location: minutesMeeting.location,
            status: minutesMeeting.status,
          } as MinutesMeetingInfo}
          onClose={() => setMinutesMeeting(null)}
          onSaved={() => {
            setMinutesMeeting(null)
            loadMeetings(pagination.current_page)
          }}
        />
      )}
    </div>
  )
}

export default CreateMeeting