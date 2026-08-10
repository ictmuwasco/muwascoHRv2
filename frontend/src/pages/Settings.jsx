import { useState, useEffect } from 'react'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Table from '../components/ui/Table'
import Badge from '../components/ui/Badge'
import Button from '../components/ui/Button'
import { Plus, Settings as SettingsIcon, FileText, Users as UsersIcon, Shield } from 'lucide-react'

const SettingsPage = () => {
  const [activeTab, setActiveTab] = useState('settings')
  const [users, setUsers] = useState([])
  const [usersLoading, setUsersLoading] = useState(true)

  useEffect(() => {
    if (activeTab === 'users') {
      fetchUsers()
    }
  }, [activeTab])

  const fetchUsers = async () => {
    try {
      const response = await api.get('/users')
      setUsers(response.data.data || [])
    } catch (error) {
      console.error('Failed to fetch users:', error)
    } finally {
      setUsersLoading(false)
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
  ]

  const tabs = [
    { id: 'settings', name: 'Settings', icon: <SettingsIcon className="h-4 w-4" /> },
    { id: 'audit', name: 'Audit', icon: <FileText className="h-4 w-4" /> },
    { id: 'users', name: 'Users', icon: <UsersIcon className="h-4 w-4" /> },
    { id: 'role_permissions', name: 'Role Permissions', icon: <Shield className="h-4 w-4" /> },
  ]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Settings</h1>
        <p className="text-gray-500">Manage system settings and preferences</p>
      </div>

      <div className="border-b border-gray-200 overflow-x-auto scrollbar-thin">
        <nav className="-mb-px flex space-x-1 sm:space-x-8" aria-label="Settings tabs">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex items-center space-x-1 sm:space-x-2 py-3 px-2 sm:px-1 border-b-2 text-xs sm:text-sm font-medium transition-colors whitespace-nowrap ${
                activeTab === tab.id
                  ? 'border-primary-600 text-primary-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {tab.icon}
              <span className="hidden xs:inline">{tab.name}</span>
              <span className="xs:hidden">{tab.name.split(' ')[0]}</span>
            </button>
          ))}
        </nav>
      </div>

      {activeTab === 'settings' && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <Card className="cursor-pointer hover:shadow-md transition-shadow">
            <div className="flex flex-col items-center text-center space-y-4">
              <div className="p-4 rounded-full bg-blue-500">
                <SettingsIcon className="h-8 w-8 text-white" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900">Profile Settings</h3>
                <p className="text-sm text-gray-500 mt-1">Update your personal information and preferences</p>
              </div>
            </div>
          </Card>
          <Card className="cursor-pointer hover:shadow-md transition-shadow">
            <div className="flex flex-col items-center text-center space-y-4">
              <div className="p-4 rounded-full bg-green-500">
                <SettingsIcon className="h-8 w-8 text-white" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900">Notifications</h3>
                <p className="text-sm text-gray-500 mt-1">Configure your notification preferences</p>
              </div>
            </div>
          </Card>
          <Card className="cursor-pointer hover:shadow-md transition-shadow">
            <div className="flex flex-col items-center text-center space-y-4">
              <div className="p-4 rounded-full bg-purple-500">
                <SettingsIcon className="h-8 w-8 text-white" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900">Security</h3>
                <p className="text-sm text-gray-500 mt-1">Manage your password and security settings</p>
              </div>
            </div>
          </Card>
        </div>
      )}

      {activeTab === 'audit' && (
        <Card>
          <div className="text-center py-8 text-gray-500">
            Audit logs and monitoring settings will be available here.
          </div>
        </Card>
      )}

      {activeTab === 'users' && (
        <div className="space-y-6">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-xl font-bold text-gray-900">Users</h2>
              <p className="text-gray-500">Manage system users</p>
            </div>
            <Button>
              <Plus className="h-4 w-4 mr-2" />
              Add User
            </Button>
          </div>

          <Card>
            {usersLoading ? (
              <div className="flex items-center justify-center h-64">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
              </div>
            ) : (
              <Table columns={userColumns} data={users} />
            )}
          </Card>
        </div>
      )}

      {activeTab === 'role_permissions' && (
        <Card>
          <div className="text-center py-8 text-gray-500">
            Role permissions management will be available here.
          </div>
        </Card>
      )}
    </div>
  )
}

export default SettingsPage
