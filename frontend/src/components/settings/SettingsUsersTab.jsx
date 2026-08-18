import { useState, useEffect, useRef } from 'react'
import api from '../../utils/api'
import Card from '../ui/Card'
import Table from '../ui/Table'
import Badge from '../ui/Badge'
import Button from '../ui/Button'
import Input from '../ui/Input'
import { Trash2, KeyRound, ChevronLeft, ChevronRight, Search, X } from 'lucide-react'

const PER_PAGE = 30

const UsersTab = () => {
  const [users, setUsers] = useState([])
  const [usersLoading, setUsersLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const [total, setTotal] = useState(0)
  const [actionLoading, setActionLoading] = useState(null)
  const [resetUser, setResetUser] = useState(null)
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const requestIdRef = useRef(0)
  const searchTimeoutRef = useRef(null)

  useEffect(() => {
    const requestId = ++requestIdRef.current

    const fetchUsers = async () => {
      setUsersLoading(true)
      try {
        const params = { page, limit: PER_PAGE }
        if (search) params.search = search

        const response = await api.get('/users', { params })

        // Ignore stale responses from previous page requests
        if (requestId !== requestIdRef.current) return

        const data = response.data?.data
        // Handle both paginated {data: [...], total, page} and plain array formats
        const userList = Array.isArray(data) ? data : (data?.data || [])
        const totalCount = Array.isArray(data) ? data.length : (data?.total || userList.length)

        setUsers(userList)
        setTotal(totalCount)
        setTotalPages(Math.ceil(totalCount / PER_PAGE))
      } catch (error) {
        if (requestId === requestIdRef.current) {
          console.error('Failed to fetch users:', error)
        }
      } finally {
        if (requestId === requestIdRef.current) {
          setUsersLoading(false)
        }
      }
    }

    fetchUsers()
  }, [page, search])

  // Debounced search - updates `search` after user stops typing
  const handleSearchChange = (e) => {
    const value = e.target.value
    setSearchInput(value)

    // Clear previous timeout
    if (searchTimeoutRef.current) {
      clearTimeout(searchTimeoutRef.current)
    }

    // Debounce: wait 400ms after typing stops before searching
    searchTimeoutRef.current = setTimeout(() => {
      setSearch(value.trim())
      setPage(1)
    }, 400)
  }

  const clearSearch = () => {
    setSearchInput('')
    setSearch('')
    setPage(1)
    if (searchTimeoutRef.current) {
      clearTimeout(searchTimeoutRef.current)
    }
  }

  const handleResetPassword = async (userId) => {
    // Client-side validation & sanitization
    const trimmedPassword = (newPassword || '').trim()
    if (!trimmedPassword) {
      setError('Please enter a new password')
      return
    }
    if (trimmedPassword.length < 8) {
      setError('Password must be at least 8 characters')
      return
    }
    if (trimmedPassword !== (confirmPassword || '').trim()) {
      setError('Passwords do not match')
      return
    }
    setActionLoading(`reset-${userId}`)
    setError('')
    setSuccess('')
    try {
      await api.post(`/users/${userId}/change-password`, { password: trimmedPassword })
      setSuccess('Password reset successfully')
      setResetUser(null)
      setNewPassword('')
      setConfirmPassword('')
    } catch (error) {
      console.error('Failed to reset password:', error)
      setError(error.response?.data?.message || 'Failed to reset password')
    } finally {
      setActionLoading(null)
    }
  }

  const handleDelete = async (userId, userName) => {
    if (!window.confirm(`Are you sure you want to delete user "${userName}"? This action cannot be undone.`)) {
      return
    }
    setActionLoading(`delete-${userId}`)
    setError('')
    setSuccess('')
    try {
      await api.delete(`/users/${userId}`)
      setUsers((prev) => prev.filter((u) => u.id !== userId))
      setTotal((prev) => Math.max(0, prev - 1))
      setSuccess('User deleted successfully')
    } catch (error) {
      console.error('Failed to delete user:', error)
      setError(error.response?.data?.message || 'Failed to delete user')
    } finally {
      setActionLoading(null)
    }
  }

  const userColumns = [
    { key: 'first_name', label: 'First Name' },
    { key: 'last_name', label: 'Last Name' },
    { key: 'email', label: 'Email' },
    { key: 'role', label: 'Role' },
    {
      key: 'is_active',
      label: 'Status',
      render: (value) => (
        <Badge variant={value ? 'success' : 'danger'}>
          {value ? 'Active' : 'Inactive'}
        </Badge>
      ),
    },
    {
      key: 'actions',
      label: 'Actions',
      render: (_, row) => (
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            loading={actionLoading === `reset-${row.id}`}
            onClick={() => { setResetUser(row); setNewPassword(''); setConfirmPassword(''); setError(''); setSuccess('') }}
          >
            <KeyRound className="h-4 w-4 mr-1" />
            Reset Password
          </Button>
          <Button
            variant="danger"
            size="sm"
            loading={actionLoading === `delete-${row.id}`}
            onClick={() => handleDelete(row.id, row.first_name || row.email)}
          >
            <Trash2 className="h-4 w-4 mr-1" />
            Delete
          </Button>
        </div>
      ),
    },
  ]

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="text-xl font-bold text-gray-900">Users</h2>
          <p className="text-gray-500">Manage system users</p>
        </div>

        {/* Responsive search */}
        <div className="relative w-full sm:w-72 md:w-80">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
          <input
            type="text"
            placeholder="Search by name or email..."
            value={searchInput}
            onChange={handleSearchChange}
            className="w-full pl-9 pr-9 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
          />
          {searchInput && (
            <button
              onClick={clearSearch}
              className="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600"
              title="Clear search"
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md text-sm">
          {error}
        </div>
      )}
      {success && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md text-sm">
          {success}
        </div>
      )}

      <Card>
        {usersLoading ? (
          <div className="flex items-center justify-center h-64">
            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
          </div>
        ) : (
          <Table columns={userColumns} data={users} />
        )}

        {/* Pagination */}
        <div className="flex flex-col sm:flex-row items-center justify-between mt-4 px-2 py-3 gap-3">
          <p className="text-sm text-gray-500">
            Showing {users.length > 0 ? ((page - 1) * PER_PAGE) + 1 : 0} to{' '}
            {Math.min(page * PER_PAGE, total)} of {total} users
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

      {resetUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-1">
              Reset Password
            </h3>
            <p className="text-sm text-gray-500 mb-4">
              Set a new password for <span className="font-medium text-gray-900">{resetUser.email}</span>
            </p>
            {(error || success) && (
              <div className={`px-4 py-3 rounded-md text-sm mb-4 ${error ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-700'}`}>
                {error || success}
              </div>
            )}
            <Input
              label="New Password"
              type="password"
              value={newPassword}
              onChange={(e) => { setNewPassword(e.target.value); setError(''); setSuccess('') }}
              placeholder="Enter new password (min 8 characters)"
            />
            <div className="mt-3">
              <Input
                label="Confirm Password"
                type="password"
                value={confirmPassword}
                onChange={(e) => { setConfirmPassword(e.target.value); setError(''); setSuccess('') }}
                placeholder="Re-enter new password"
              />
            </div>
            <div className="mt-6 flex justify-end gap-3">
              <Button variant="secondary" onClick={() => setResetUser(null)}>
                Cancel
              </Button>
              <Button
                loading={actionLoading === `reset-${resetUser.id}`}
                onClick={() => handleResetPassword(resetUser.id)}
              >
                Reset Password
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

export default UsersTab
