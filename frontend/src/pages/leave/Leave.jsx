import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import { Plus } from 'lucide-react'

const Leave = () => {
  const navigate = useNavigate()
  const [leaveRequests, setLeaveRequests] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fetchLeaveRequests()
  }, [])

  const fetchLeaveRequests = async () => {
    try {
      const response = await api.get('/leave')
      setLeaveRequests(response.data.data || [])
    } catch (error) {
      console.error('Failed to fetch leave requests:', error)
    } finally {
      setLoading(false)
    }
  }

  const columns = [
    { key: 'employee_name', label: 'Employee' },
    { key: 'leave_type', label: 'Leave Type' },
    { key: 'start_date', label: 'Start Date' },
    { key: 'end_date', label: 'End Date' },
    { key: 'days_requested', label: 'Days' },
    { key: 'delegate_name', label: 'Delegate' },
    {
      key: 'status',
      label: 'Status',
      render: (value) => (
        <Badge variant={value === 'Approved' ? 'success' : value === 'Pending' ? 'warning' : 'danger'}>
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
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">My Leave Applications</h1>
          <p className="text-gray-500">View your leave history</p>
        </div>
        <Button onClick={() => navigate('/leave/apply')}>
          <Plus className="h-4 w-4 mr-2" />
          Apply Leave
        </Button>
      </div>

      <Card>
        <Table columns={columns} data={leaveRequests} />
      </Card>
    </div>
  )
}

export default Leave
