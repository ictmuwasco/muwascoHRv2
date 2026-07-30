import { useState, useEffect } from 'react'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Table from '../components/ui/Table'
import Badge from '../components/ui/Badge'

const Attendance = () => {
  const [attendance, setAttendance] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fetchAttendance()
  }, [])

  const fetchAttendance = async () => {
    try {
      const response = await api.get('/attendance')
      setAttendance(response.data.data || [])
    } catch (error) {
      console.error('Failed to fetch attendance:', error)
    } finally {
      setLoading(false)
    }
  }

  const columns = [
    { key: 'employee_name', label: 'Employee' },
    { key: 'date', label: 'Date' },
    { key: 'clock_in_time', label: 'Clock In' },
    { key: 'clock_out_time', label: 'Clock Out' },
    {
      key: 'status',
      label: 'Status',
      render: (value) => (
        <Badge variant={value === 'Present' ? 'success' : value === 'Late' ? 'warning' : 'danger'}>
          {value}
        </Badge>
      ),
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
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Attendance</h1>
        <p className="text-gray-500">Track employee attendance</p>
      </div>

      <Card>
        <Table columns={columns} data={attendance} />
      </Card>
    </div>
  )
}

export default Attendance