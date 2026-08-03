import { useState, useEffect } from 'react'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Table from '../components/ui/Table'
import Badge from '../components/ui/Badge'
import Button from '../components/ui/Button'
import { Plus, Search } from 'lucide-react'

const Employees = () => {
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  useEffect(() => {
    fetchEmployees()
  }, [])

  const fetchEmployees = async () => {
    try {
      const response = await api.get('/employees')
      setEmployees(response.data.data || [])
    } catch (error) {
      console.error('Failed to fetch employees:', error)
    } finally {
      setLoading(false)
    }
  }

  const columns = [
    { key: 'employee_id', label: 'Employee ID' },
    { key: 'first_name', label: 'First Name' },
    { key: 'last_name', label: 'Last Name' },
    { key: 'email', label: 'Email' },
    { key: 'department', label: 'Department' },
    { key: 'designation', label: 'Designation' },
    {
      key: 'employee_status',
      label: 'Status',
      render: (value) => (
        <Badge variant={value === 'Active' ? 'success' : 'danger'}>
          {value}
        </Badge>
      ),
    },
  ]

  const filteredEmployees = employees.filter((emp) =>
    Object.values(emp).some((val) =>
      String(val).toLowerCase().includes(search.toLowerCase())
    )
  )

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
          <h1 className="text-2xl font-bold text-gray-900">Employees</h1>
          <p className="text-gray-500">Manage employee records</p>
        </div>
        <Button>
          <Plus className="h-4 w-4 mr-2" />
          Add Employee
        </Button>
      </div>

      <Card>
        <div className="mb-4">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-5 w-5 text-gray-400" />
            <input
              type="text"
              placeholder="Search employees..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500"
            />
          </div>
        </div>
        <Table columns={columns} data={filteredEmployees} />
      </Card>
    </div>
  )
}

export default Employees