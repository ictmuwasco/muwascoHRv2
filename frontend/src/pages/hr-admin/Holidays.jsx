import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import { Plus, Pencil, Trash2 } from 'lucide-react'

const Holidays = () => {
  const navigate = useNavigate()
  const [holidays, setHolidays] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState({
    name: '',
    date: '',
    description: '',
    is_recurring: false,
  })

  useEffect(() => {
    fetchHolidays()
  }, [])

  const fetchHolidays = async () => {
    try {
      const response = await api.get('/holidays')
      setHolidays(response.data?.data || [])
    } catch (err) {
      setError('Failed to load holidays')
    } finally {
      setLoading(false)
    }
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setSaving(true)
    setError('')
    setSuccess('')

    try {
      if (editingId) {
        await api.put(`/holidays/${editingId}`, formData)
        setSuccess('Holiday updated successfully')
      } else {
        await api.post('/holidays', formData)
        setSuccess('Holiday created successfully')
      }

      setShowForm(false)
      setEditingId(null)
      setFormData({ name: '', date: '', description: '', is_recurring: false })
      fetchHolidays()
    } catch (err) {
      const message = err && typeof err === 'object' && 'response' in err
        ? (err.response && err.response.data && err.response.data.message)
        : undefined
      setError(message || 'Failed to save holiday')
    } finally {
      setSaving(false)
    }
  }

  const handleEdit = (holiday) => {
    setEditingId(holiday.id)
    setFormData({
      name: holiday.name || '',
      date: holiday.date || '',
      description: holiday.description || '',
      is_recurring: !!holiday.is_recurring,
    })
    setShowForm(true)
  }

  const handleDelete = async (id) => {
    if (!confirm('Are you sure you want to delete this holiday?')) return

    try {
      await api.delete(`/holidays/${id}`)
      setSuccess('Holiday deleted successfully')
      fetchHolidays()
    } catch (err) {
      setError('Failed to delete holiday')
    }
  }

  const columns = [
    { key: 'name', label: 'Holiday Name' },
    { key: 'date', label: 'Date' },
    { key: 'description', label: 'Description', render: (value) => value || '-' },
    {
      key: 'is_recurring',
      label: 'Recurring',
      render: (value) => (value ? 'Yes' : 'No'),
    },
    {
      key: 'actions',
      label: 'Actions',
      render: (_, row) => (
        <div className="flex items-center space-x-2">
          <Button variant="outline" size="sm" onClick={() => handleEdit(row)}>
            <Pencil className="h-3 w-3 mr-1" />
            Edit
          </Button>
          <Button variant="danger" size="sm" onClick={() => handleDelete(row.id)}>
            <Trash2 className="h-3 w-3 mr-1" />
            Delete
          </Button>
        </div>
      ),
    },
  ]

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="flex items-center justify-center h-64">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Holidays</h1>
          <p className="text-gray-500">Manage public holidays</p>
        </div>
        <Button onClick={() => { setShowForm(true); setEditingId(null); setFormData({ name: '', date: '', description: '', is_recurring: false }) }}>
          <Plus className="h-4 w-4 mr-2" />
          Add Holiday
        </Button>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md">
          {error}
        </div>
      )}

      {success && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md">
          {success}
        </div>
      )}

      {showForm && (
        <Card title={editingId ? 'Edit Holiday' : 'Add Holiday'}>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Input
                label="Holiday Name *"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                required
              />
              <Input
                label="Date *"
                type="date"
                value={formData.date}
                onChange={(e) => setFormData({ ...formData, date: e.target.value })}
                required
              />
            </div>
            <Input
              label="Description"
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
            />
            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="is_recurring"
                checked={formData.is_recurring}
                onChange={(e) => setFormData({ ...formData, is_recurring: e.target.checked })}
                className="h-4 w-4 text-primary-600 border-gray-300 rounded"
              />
              <label htmlFor="is_recurring" className="text-sm text-gray-700">
                Recurring annually
              </label>
            </div>
            <div className="flex items-center justify-end space-x-3">
              <Button type="button" variant="outline" onClick={() => { setShowForm(false); setEditingId(null) }}>
                Cancel
              </Button>
              <Button type="submit" disabled={saving}>
                {saving ? 'Saving...' : (editingId ? 'Update Holiday' : 'Create Holiday')}
              </Button>
            </div>
          </form>
        </Card>
      )}

      <Card>
        <Table columns={columns} data={holidays} />
      </Card>
    </div>
  )
}

export default Holidays
