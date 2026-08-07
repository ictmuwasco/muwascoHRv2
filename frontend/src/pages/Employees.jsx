import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Table from '../components/ui/Table'
import Badge from '../components/ui/Badge'
import Button from '../components/ui/Button'
import EmployeeTabs from '../components/EmployeeTabs'
import { Plus, Search, Eye, Pencil, ChevronLeft, ChevronRight } from 'lucide-react'

const PER_PAGE = 50

const Employees = () => {
  const navigate = useNavigate()
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [page, setPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const [total, setTotal] = useState(0)
  const requestIdRef = useRef(0)

  useEffect(() => {
    const requestId = ++requestIdRef.current
    
    const fetchEmployees = async () => {
      setLoading(true)
      try {
        const params = { 
          page, 
          limit: PER_PAGE
        }
        if (searchTerm) {
          params.search = searchTerm
        }
        const response = await api.get('/employees', { params })
        
        // Ignore stale responses from previous page requests
        if (requestId !== requestIdRef.current) return
        
        const data = response.data?.data
        // Handle both paginated {data: [...], total, page} and plain array formats
        const employeeList = Array.isArray(data) ? data : (data?.data || [])
        const totalCount = Array.isArray(data) ? data.length : (data?.total || employeeList.length)
        
        setEmployees(employeeList)
        setTotal(totalCount)
        setTotalPages(Math.ceil(totalCount / PER_PAGE))
      } catch (error) {
        // Only log error if this is still the current request
        if (requestId === requestIdRef.current) {
          console.error('Failed to fetch employees:', error)
        }
      } finally {
        if (requestId === requestIdRef.current) {
          setLoading(false)
        }
      }
    }
    
    fetchEmployees()
  }, [page, searchTerm])

  const handleSearch = (e) => {
    e.preventDefault()
    setPage(1)
    setSearchTerm(search)
  }

  const handleReset = () => {
    setSearch('')
    setSearchTerm('')
    setPage(1)
  }

  const columns = [
    { key: 'employee_id', label: 'Employee ID' },
    { key: 'first_name', label: 'First Name' },
    { key: 'last_name', label: 'Last Name' },
    { key: 'email', label: 'Email' },
    {
      key: 'department',
      label: 'Department',
      render: (value, row) => row.department_name || row.department || value || '-',
    },
    { key: 'designation', label: 'Designation', render: (value) => value || '-' },
    {
      key: 'employee_status',
      label: 'Status',
      render: (value) => (
        <Badge variant={String(value).toLowerCase() === 'active' ? 'success' : 'danger'}>
          {value || 'Active'}
        </Badge>
      ),
    },
    {
      key: 'actions',
      label: 'Actions',
      render: (value, row) => (
        <div className="flex items-center space-x-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => navigate(`/employees/${row.id}/profile`)}
          >
            <Eye className="h-3 w-3 mr-1" />
            View Profile
          </Button>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => navigate(`/employees/${row.id}/edit`)}
          >
            <Pencil className="h-3 w-3 mr-1" />
            Edit
          </Button>
        </div>
      ),
    },
  ]

  if (loading) {
    return (
      <div className="space-y-6">
        <EmployeeTabs />
        <div className="flex items-center justify-center h-64">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <EmployeeTabs />

      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Employees</h1>
          <p className="text-gray-500">Manage employee records</p>
        </div>
        <Button onClick={() => navigate('/employees/add')}>
          <Plus className="h-4 w-4 mr-2" />
          Add Employee
        </Button>
      </div>

      <Card>
        {/* Search bar */}
        <div className="mb-4">
          <form onSubmit={handleSearch} className="flex items-center space-x-2">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-5 w-5 text-gray-400" />
              <input
                type="text"
                placeholder="Search employees..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500"
              />
            </div>
            <Button type="submit" size="md">
              <Search className="h-4 w-4 mr-1" />
              Search
            </Button>
            {searchTerm && (
              <Button type="button" variant="outline" onClick={handleReset}>
                Reset
              </Button>
            )}
          </form>
        </div>

        <Table columns={columns} data={employees} />

        {/* Pagination */}
        <div className="flex items-center justify-between mt-4 px-2 py-3">
          <p className="text-sm text-gray-500">
            Showing {employees.length > 0 ? ((page - 1) * PER_PAGE) + 1 : 0} to{' '}
            {Math.min(page * PER_PAGE, total)} of {total} employees
          </p>
          <div className="flex items-center space-x-2">
            <Button
              variant="outline"
              size="sm"
              disabled={page <= 1}
              onClick={() => setPage(page - 1)}
            >
              <ChevronLeft className="h-4 w-4 mr-1" />
              Previous
            </Button>
            <span className="text-sm text-gray-700">
              Page {page} of {Math.max(totalPages, 1)}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={page >= totalPages}
              onClick={() => setPage(page + 1)}
            >
              Next
              <ChevronRight className="h-4 w-4 ml-1" />
            </Button>
          </div>
        </div>
      </Card>
    </div>
  )
}

export default Employees