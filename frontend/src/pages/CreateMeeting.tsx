import { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Button from '../components/ui/Button'
import Input from '../components/ui/Input'
import { ArrowLeft } from 'lucide-react'

interface Employee {
  id: number
  first_name: string
  last_name: string
  employee_id: string
  designation?: string
  email?: string
}

interface MeetingForm {
  title: string
  description: string
  agenda: string
  meeting_date: string
  start_time: string
  end_time: string
  location: string
  employee_ids: number[]
}

const CreateMeeting = () => {
  const navigate = useNavigate()
  const { id } = useParams<{ id?: string }>()
  const isEditMode = Boolean(id)

  const [form, setForm] = useState<MeetingForm>({
    title: '',
    description: '',
    agenda: '',
    meeting_date: '',
    start_time: '',
    end_time: '',
    location: '',
    employee_ids: [],
  })
  const [employees, setEmployees] = useState<Employee[]>([])
  const [selectedEmployees, setSelectedEmployees] = useState<number[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [searchTerm, setSearchTerm] = useState('')

  useEffect(() => {
    loadData()
  }, [id])

  const loadData = async () => {
    setLoading(true)
    setError('')
    try {
      const empResponse = await api.get('/meetings/eligible-employees')
      const empData = empResponse.data?.data || []
      setEmployees(empData)

      if (isEditMode && id) {
        const meetingResponse = await api.get(`/meetings/${id}`)
        const meeting = meetingResponse.data?.data
        if (meeting) {
          setForm({
            title: meeting.title || '',
            description: meeting.description || '',
            agenda: meeting.agenda || '',
            meeting_date: meeting.meeting_date || '',
            start_time: meeting.start_time || '',
            end_time: meeting.end_time || '',
            location: meeting.location || '',
            employee_ids: [],
          })
          const invitedIds = (meeting.invitations || [])
            .filter((inv: any) => inv.employee_id)
            .map((inv: any) => Number(inv.employee_id))
          setSelectedEmployees(invitedIds)
        }
      }
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.error || 'Failed to load data'
      setError(msg)
    } finally {
      setLoading(false)
    }
  }

  const handleInputChange = (field: keyof MeetingForm, value: string) => {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  const toggleEmployee = (employeeId: number) => {
    setSelectedEmployees((prev) =>
      prev.includes(employeeId)
        ? prev.filter((eid) => eid !== employeeId)
        : [...prev, employeeId]
    )
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    setSuccess('')
    setSaving(true)

    if (!form.title.trim()) {
      setError('Meeting title is required')
      setSaving(false)
      return
    }
    if (!form.meeting_date) {
      setError('Meeting date is required')
      setSaving(false)
      return
    }
    if (!form.start_time) {
      setError('Start time is required')
      setSaving(false)
      return
    }
    if (!form.end_time) {
      setError('End time is required')
      setSaving(false)
      return
    }
    if (!form.location.trim()) {
      setError('Location is required')
      setSaving(false)
      return
    }
    if (selectedEmployees.length === 0) {
      setError('At least one employee must be invited')
      setSaving(false)
      return
    }

    try {
      if (isEditMode && id) {
        await api.put(`/meetings/${id}`, {
          title: form.title,
          description: form.description,
          agenda: form.agenda,
          meeting_date: form.meeting_date,
          start_time: form.start_time,
          end_time: form.end_time,
          location: form.location,
        })
        setSuccess('Meeting updated successfully')
      } else {
        await api.post('/meetings', {
          ...form,
          employee_ids: selectedEmployees,
        })
        setSuccess('Meeting created successfully')
      }
      setTimeout(() => navigate('/meetings'), 1500)
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.error || 'Failed to save meeting'
      setError(msg)
    } finally {
      setSaving(false)
    }
  }

  const filteredEmployees = employees.filter((emp) => {
    if (!searchTerm.trim()) return true
    const term = searchTerm.toLowerCase()
    return (
      emp.first_name?.toLowerCase().includes(term) ||
      emp.last_name?.toLowerCase().includes(term) ||
      emp.employee_id?.toLowerCase().includes(term) ||
      emp.designation?.toLowerCase().includes(term) ||
      emp.email?.toLowerCase().includes(term)
    )
  })

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
        <div className="flex items-center space-x-4">
          <button
            onClick={() => navigate('/meetings')}
            className="p-2 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg transition-colors"
          >
            <ArrowLeft className="h-5 w-5" />
          </button>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{isEditMode ? 'Edit Meeting' : 'Create Meeting'}</h1>
            <p className="text-gray-500">{isEditMode ? 'Update meeting details' : 'Schedule a new meeting and invite employees'}</p>
          </div>
        </div>
      </div>

      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
          {error}
        </div>
      )}

      {success && (
        <div className="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg">
          {success}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-6">
        <Card title="Meeting Details">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="md:col-span-2">
              <Input
                label="Meeting Title *"
                value={form.title}
                onChange={(e) => handleInputChange('title', e.target.value)}
                placeholder="e.g. Weekly Department Meeting"
                required
              />
            </div>

            <div className="md:col-span-2">
              <Input
                label="Description"
                value={form.description}
                onChange={(e) => handleInputChange('description', e.target.value)}
                placeholder="Brief description of the meeting"
              />
            </div>

            <div className="md:col-span-2">
              <Input
                label="Agenda"
                value={form.agenda}
                onChange={(e) => handleInputChange('agenda', e.target.value)}
                placeholder="Agenda items (one per line)"
              />
            </div>

            <Input
              label="Meeting Date *"
              type="date"
              value={form.meeting_date}
              onChange={(e) => handleInputChange('meeting_date', e.target.value)}
              required
            />

            <Input
              label="Location *"
              value={form.location}
              onChange={(e) => handleInputChange('location', e.target.value)}
              placeholder="e.g. Conference Room A / Zoom"
              required
            />

            <Input
              label="Start Time *"
              type="time"
              value={form.start_time}
              onChange={(e) => handleInputChange('start_time', e.target.value)}
              required
            />

            <Input
              label="End Time *"
              type="time"
              value={form.end_time}
              onChange={(e) => handleInputChange('end_time', e.target.value)}
              required
            />
          </div>
        </Card>

        <Card title={isEditMode ? 'Invited Employees' : 'Invite Employees'}>
          {!isEditMode && (
            <>
              <div className="mb-4">
                <Input
                  label="Search Employees"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  placeholder="Search by name, employee ID, or designation..."
                />
              </div>

              <div className="max-h-80 overflow-y-auto border dark:border-slate-700 rounded-lg divide-y dark:divide-slate-700">
                {filteredEmployees.length === 0 ? (
                  <div className="p-4 text-center text-gray-500 dark:text-gray-400">
                    No employees found
                  </div>
                ) : (
                  filteredEmployees.map((emp) => (
                    <label
                      key={emp.id}
                      className="flex items-center px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer"
                    >
                      <input
                        type="checkbox"
                        checked={selectedEmployees.includes(emp.id)}
                        onChange={() => toggleEmployee(emp.id)}
                        className="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                      />
                      <div className="ml-3">
                        <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                          {emp.first_name} {emp.last_name}
                        </p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                          {emp.designation && <span>{emp.designation} • </span>}
                          {emp.employee_id && <span>ID: {emp.employee_id}</span>}
                          {emp.email && <span> • {emp.email}</span>}
                        </p>
                      </div>
                    </label>
                  ))
                )}
              </div>
            </>
          )}

          {isEditMode && (
            <div className="space-y-2">
              {selectedEmployees.length === 0 ? (
                <p className="text-gray-500 dark:text-gray-400">No employees invited</p>
              ) : (
                employees
                  .filter((emp) => selectedEmployees.includes(emp.id))
                  .map((emp) => (
                    <div key={emp.id} className="flex items-center px-4 py-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
                      <p className="text-sm font-medium text-gray-900 dark:text-gray-101">
                        {emp.first_name} {emp.last_name}
                      </p>
                      <p className="text-xs text-gray-500 dark:text-gray-400 ml-3">
                        ID: {emp.employee_id}
                      </p>
                    </div>
                  ))
              )}
            </div>
          )}

          {!isEditMode && selectedEmployees.length > 0 && (
            <div className="mt-4">
              <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Selected ({selectedEmployees.length}):
              </h4>
              <div className="flex flex-wrap gap-2">
                {employees
                  .filter((emp) => selectedEmployees.includes(emp.id))
                  .map((emp) => (
                    <span
                      key={emp.id}
                      className="inline-flex items-center px-3 py-1 rounded-full bg-primary-50 dark:bg-slate-700 text-primary-700 dark:text-primary-300 text-sm"
                    >
                      {emp.first_name} {emp.last_name}
                      <button
                        type="button"
                        onClick={() => toggleEmployee(emp.id)}
                        className="ml-2 text-primary-600 dark:text-primary-400 hover:text-red-500"
                      >
                        ×
                      </button>
                    </span>
                  ))}
              </div>
            </div>
          )}
        </Card>

        <div className="flex items-center justify-end space-x-3">
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate('/meetings')}
          >
            Cancel
          </Button>
          <Button type="submit" loading={saving}>
            {isEditMode ? 'Update Meeting' : 'Create Meeting'}
          </Button>
        </div>
      </form>
    </div>
  )
}

export default CreateMeeting