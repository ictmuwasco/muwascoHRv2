import { useState, useEffect, useCallback } from 'react'
import { Shield, Users, Search, User as UserIcon, Check, X, RefreshCw, AlertTriangle, Save, Layers, Info, Trash2 } from 'lucide-react'
import Card from '../ui/Card'
import Button from '../ui/Button'
import Input from '../ui/Input'
import Select from '../ui/Select'
import Badge from '../ui/Badge'
import { permissionService } from '../../api/services/permissionService'

const ROLE_COLORS = {
  super_admin: 'bg-purple-100 text-purple-800',
  hr_manager: 'bg-blue-100 text-blue-800',
  dept_head: 'bg-green-100 text-green-800',
  section_head: 'bg-teal-100 text-teal-800',
  sub_section_head: 'bg-cyan-100 text-cyan-800',
  manager: 'bg-indigo-100 text-indigo-800',
  officer: 'bg-yellow-100 text-yellow-800',
  employee: 'bg-gray-100 text-gray-800',
  managing_director: 'bg-red-100 text-red-800',
  bod_chairman: 'bg-rose-100 text-rose-800',
}

const roleColor = (role) => ROLE_COLORS[role] || 'bg-gray-100 text-gray-800'

const PermissionsTab = () => {
  const [catalog, setCatalog] = useState(null)
  const [stats, setStats] = useState(null)
  const [users, setUsers] = useState({ data: [], total: 0, page: 1, pages: 0 })
  const [search, setSearch] = useState('')
  const [selectedUserId, setSelectedUserId] = useState(null)
  const [userPerms, setUserPerms] = useState(null)
  const [overrides, setOverrides] = useState([])
  const [loading, setLoading] = useState(false)
  const [loadingUser, setLoadingUser] = useState(false)
  const [error, setError] = useState(null)
  const [successMsg, setSuccessMsg] = useState(null)
  const [saveState, setSaveState] = useState({ userId: null, module: null, action: null, saving: false })
  const [notes, setNotes] = useState({})

  // Load catalog and stats on mount
  useEffect(() => {
    loadCatalog()
    loadStats()
  }, [])

  // Load users when search changes (debounced)
  useEffect(() => {
    const timer = setTimeout(() => {
      loadUsers(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [search])

  const loadCatalog = async () => {
    try {
      const data = await permissionService.getCatalog()
      setCatalog(data)
    } catch (err) {
      setError('Failed to load permission catalog')
    }
  }

  const loadStats = async () => {
    try {
      const data = await permissionService.getStatistics()
      setStats(data)
    } catch (err) {
      // Stats are optional
    }
  }

  const loadUsers = async (page = 1) => {
    try {
      const data = await permissionService.getUsers({ search: search || undefined, page, per_page: 10 })
      setUsers(data)
    } catch (err) {
      setError('Failed to load users')
    }
  }

  const loadUserPermissions = async (userId) => {
    setLoadingUser(true)
    setError(null)
    setSelectedUserId(userId)
    try {
      const data = await permissionService.getUserPermissions(userId)
      setUserPerms(data)
      
      // Initialize notes state from existing overrides
      const notesMap = {}
      data.overrides.forEach(ov => {
        notesMap[`${ov.module}|${ov.action}`] = ov.notes || ''
      })
      setNotes(notesMap)
    } catch (err) {
      setError('Failed to load user permissions')
      setUserPerms(null)
    } finally {
      setLoadingUser(false)
    }
  }

  const handleSaveOverride = async (module, action, permissionType) => {
    if (!selectedUserId) return
    
    setSaveState({ userId: selectedUserId, module, action, saving: true })
    setError(null)
    setSuccessMsg(null)

    try {
      const noteKey = `${module}|${action}`
      await permissionService.setOverride(selectedUserId, {
        module,
        action,
        permission_type: permissionType,
        notes: notes[noteKey] || undefined,
      })
      
      // Reload user permissions
      await loadUserPermissions(selectedUserId)
      setSuccessMsg(`Permission ${module}:${action} set to ${permissionType}`)
      
      // Clear success after 3s
      setTimeout(() => setSuccessMsg(null), 3000)
    } catch (err) {
      setError(err.response?.data?.message || `Failed to set ${module}:${action}`)
    } finally {
      setSaveState({ userId: null, module: null, action: null, saving: false })
    }
  }

  const handleRemoveOverride = async (module, action) => {
    if (!selectedUserId) return
    
    setSaveState({ userId: selectedUserId, module, action, saving: true })
    setError(null)
    setSuccessMsg(null)

    try {
      await permissionService.removeOverride(selectedUserId, { module, action })
      await loadUserPermissions(selectedUserId)
      setSuccessMsg(`Override ${module}:${action} removed (will inherit role permission)`)
      setTimeout(() => setSuccessMsg(null), 3000)
    } catch (err) {
      setError(err.response?.data?.message || `Failed to remove ${module}:${action}`)
    } finally {
      setSaveState({ userId: null, module: null, action: null, saving: false })
    }
  }

  const getOverrideFor = (module, action) => {
    if (!userPerms) return null
    return userPerms.overrides.find(o => o.module === module && o.action === action)
  }

  const getEffectiveFor = (module, action) => {
    if (!userPerms) return null
    return userPerms.effective.find(e => e.module === module && e.action === action)
  }

  const handleNotesChange = (module, action, value) => {
    setNotes(prev => ({ ...prev, [`${module}|${action}`]: value }))
  }

  const formatDate = (dateStr) => {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    return d.toLocaleString()
  }

  const getModuleLabel = (key) => {
    if (!catalog) return key
    return catalog.modules[key]?.label || key
  }

  const getActionLabel = (module, action) => {
    if (!catalog?.modules[module]) return action
    const act = catalog.modules[module].actions.find(a => a.key === action)
    return act?.label || action
  }

  // Build permission matrix from role_permissions for display
  const rolePermissionMap = {}
  if (userPerms?.role_permissions) {
    userPerms.role_permissions.forEach(rp => {
      if (!rolePermissionMap[rp.module]) rolePermissionMap[rp.module] = {}
      rolePermissionMap[rp.module][rp.action] = { granted: rp.is_granted, defined: true }
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold flex items-center gap-2">
            <Shield className="h-5 w-5 text-blue-600" />
            Permission Management
          </h2>
          <p className="text-sm text-gray-500 mt-1">
            Hybrid RBAC + User Overrides — {stats?.total_users ?? '...'} users, {stats?.total_roles ?? '...'} roles, {stats?.total_modules ?? '...'} modules
          </p>
        </div>
        {stats && (
          <div className="flex gap-3">
            <Badge className="bg-blue-100 text-blue-800">
              {stats.total_overrides} Overrides
            </Badge>
            <Badge className="bg-green-100 text-green-800">
              {stats.allow_count} Allowed
            </Badge>
            <Badge className="bg-red-100 text-red-800">
              {stats.deny_count} Denied
            </Badge>
          </div>
        )}
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md flex items-center gap-2">
          <AlertTriangle className="h-4 w-4" />
          {error}
        </div>
      )}

      {successMsg && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md flex items-center gap-2">
          <Check className="h-4 w-4" />
          {successMsg}
        </div>
      )}

      <div className="grid grid-cols-12 gap-6">
        {/* User list sidebar */}
        <div className="col-span-12 lg:col-span-3">
          <Card>
            <div className="mb-3 relative">
              <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search users..."
                className="pl-9"
              />
            </div>
            <div className="max-h-[600px] overflow-y-auto">
              {users.data.map((user) => (
                <button
                  key={user.id}
                  onClick={() => loadUserPermissions(user.id)}
                  className={`w-full text-left px-3 py-2 rounded-md transition-colors ${selectedUserId === user.id ? 'bg-blue-50 border-blue-200 border' : 'hover:bg-gray-50'}`}
                >
                  <div className="flex items-center gap-3">
                    <div className="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center">
                      <UserIcon className="h-4 w-4 text-gray-500" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium truncate">
                        {user.first_name || user.last_name || user.email}
                      </p>
                      <p className="text-xs text-gray-500 truncate">{user.email}</p>
                    </div>
                    <Badge className={roleColor(user.role)}>
                      {user.role?.replace(/_/g, ' ')}
                    </Badge>
                  </div>
                </button>
              ))}
              {users.data.length === 0 && (
                <p className="text-center text-sm text-gray-400 py-6">No users found</p>
              )}
            </div>
            
            {/* Pagination */}
            {users.pages > 1 && (
              <div className="flex items-center justify-between mt-3 pt-3 border-t">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={users.page <= 1}
                  onClick={() => loadUsers(users.page - 1)}
                >
                  Prev
                </Button>
                <span className="text-sm text-gray-500">Page {users.page} / {users.pages}</span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={users.page >= users.pages}
                  onClick={() => loadUsers(users.page + 1)}
                >
                  Next
                </Button>
              </div>
            )}
          </Card>
        </div>

        {/* Selected user permissions panel */}
        <div className="col-span-12 lg:col-span-9">
          {!selectedUserId ? (
            <Card>
              <div className="text-center py-12">
                <Users className="h-12 w-12 mx-auto mb-4 text-gray-300" />
                <p className="text-gray-500 font-medium">Select a user to manage permissions</p>
                <p className="text-sm text-gray-400 mt-1">
                  Configure user-specific allow/deny overrides that affect their role-based permissions
                </p>
              </div>
            </Card>
          ) : loadingUser ? (
            <Card>
              <div className="flex items-center justify-center py-12">
                <RefreshCw className="h-6 w-6 animate-spin text-blue-500" />
              </div>
            </Card>
          ) : userPerms?.user ? (
            <Card>
              {/* User info header */}
              <div className="flex items-center justify-between mb-4 pb-4 border-b">
                <div>
                  <h3 className="font-semibold flex items-center gap-2">
                    {userPerms.user.first_name} {userPerms.user.last_name}
                    <Badge className={roleColor(userPerms.user.role)}>
                      {userPerms.user.role?.replace(/_/g, ' ')}
                    </Badge>
                  </h3>
                  <p className="text-sm text-gray-500 mt-1">
                    {userPerms.user.email} {userPerms.user.designation && `• ${userPerms.user.designation}`}
                  </p>
                </div>
                <div className="text-right text-xs text-gray-400">
                  {userPerms.user.is_active === 1 ? 'Active' : 'Inactive'}
                </div>
              </div>

              {/* Permission matrix */}
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead>
                    <tr className="bg-gray-50">
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Module
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Action
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Role
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Override
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Effective
                      </th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-100">
                    {catalog && Object.entries(catalog.modules).map(([moduleKey, module]) => (
                      <>
                        {module.actions.map((action) => {
                          const rolePerm = rolePermissionMap[moduleKey]?.[action.key]
                          const override = getOverrideFor(moduleKey, action.key)
                          const effective = getEffectiveFor(moduleKey, action.key)
                          const isSaving = saveState.saving && saveState.userId === selectedUserId && saveState.module === moduleKey && saveState.action === action.key
                          
                          return (
                            <tr key={`${moduleKey}-${action.key}`} className="hover:bg-gray-50">
                              <td className="px-4 py-3 text-sm font-medium">
                                {module.label}
                                {action.type === 'page' && (
                                  <span className="ml-1 text-[10px] text-gray-400 uppercase">Page</span>
                                )}
                              </td>
                              <td className="px-4 py-3 text-sm">
                                {action.label}
                              </td>
                              <td className="px-4 py-3">
                                {rolePerm?.defined ? (
                                  rolePerm.granted ? (
                                    <span className="inline-flex items-center gap-1 text-green-700">
                                      <Check className="h-3.5 w-3.5" /> Granted
                                    </span>
                                  ) : (
                                    <span className="inline-flex items-center gap-1 text-red-600">
                                      <X className="h-3.5 w-3.5" /> Denied
                                    </span>
                                  )
                                ) : (
                                  <span className="text-gray-400 text-xs">Not defined</span>
                                )}
                              </td>
                              <td className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                  <button
                                    onClick={() => handleSaveOverride(moduleKey, action.key, 'allow')}
                                    disabled={isSaving || userPerms.user.role === 'super_admin'}
                                    className={`px-2 py-1 rounded text-xs font-medium transition-colors ${
                                      override?.permission_type === 'allow'
                                        ? 'bg-green-600 text-white'
                                        : 'bg-green-50 text-green-700 hover:bg-green-100 disabled:opacity-50'
                                    }`}
                                  >
                                    Allow
                                  </button>
                                  <button
                                    onClick={() => handleSaveOverride(moduleKey, action.key, 'deny')}
                                    disabled={isSaving || userPerms.user.role === 'super_admin'}
                                    className={`px-2 py-1 rounded text-xs font-medium transition-colors ${
                                      override?.permission_type === 'deny'
                                        ? 'bg-red-600 text-white'
                                        : 'bg-red-50 text-red-700 hover:bg-red-100 disabled:opacity-50'
                                    }`}
                                  >
                                    Deny
                                  </button>
                                  <button
                                    onClick={() => handleRemoveOverride(moduleKey, action.key)}
                                    disabled={!override || isSaving || userPerms.user.role === 'super_admin'}
                                    title="Remove override (inherit role)"
                                    className="px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200 disabled:opacity-50"
                                  >
                                    Inherit
                                  </button>
                                  {isSaving && <RefreshCw className="h-3 w-3 animate-spin text-blue-500" />}
                                </div>
                                
                                {/* Notes input for overrides */}
                                {override && (
                                  <div className="mt-2">
                                    <Input
                                      size="sm"
                                      value={notes[`${moduleKey}|${action.key}`] || ''}
                                      onChange={(e) => handleNotesChange(moduleKey, action.key, e.target.value)}
                                      placeholder="Add note (e.g., Temporary access for audit)"
                                      className="text-xs"
                                    />
                                    <div className="flex items-center justify-between mt-1">
                                      <span className="text-[10px] text-gray-400">
                                        {override.granted_at && `Granted: ${formatDate(override.granted_at)}`}
                                      </span>
                                      <button
                                        onClick={() => handleSaveOverride(moduleKey, action.key, override.permission_type)}
                                        className="text-[10px] text-blue-600 hover:underline"
                                      >
                                        Save note
                                      </button>
                                    </div>
                                  </div>
                                )}
                              </td>
                              <td className="px-4 py-3">
                                {effective?.allowed !== undefined ? (
                                  effective.allowed ? (
                                    <div className="flex items-center gap-1.5">
                                      <span className="inline-flex h-2 w-2 rounded-full bg-green-500"></span>
                                      <span className="text-sm text-green-700">Allowed</span>
                                      {effective.source && effective.source !== 'Role' && (
                                        <span className="text-[10px] text-gray-400">({effective.source})</span>
                                      )}
                                    </div>
                                  ) : (
                                    <div className="flex items-center gap-1.5">
                                      <span className="inline-flex h-2 w-2 rounded-full bg-red-500"></span>
                                      <span className="text-sm text-red-600">Denied</span>
                                      {effective.source && effective.source !== 'Role' && (
                                        <span className="text-[10px] text-gray-400">({effective.source})</span>
                                      )}
                                    </div>
                                  )
                                ) : (
                                  <span className="inline-flex items-center gap-1.5">
                                    <span className="inline-flex h-2 w-2 rounded-full bg-gray-300"></span>
                                    <span className="text-sm text-gray-500">Unknown</span>
                                  </span>
                                )}
                              </td>
                            </tr>
                          )
                        })}
                      </>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Super Admin notice */}
              {userPerms.user.role === 'super_admin' && (
                <div className="mt-4 bg-purple-50 border border-purple-200 rounded-md p-4 flex items-start gap-3">
                  <Info className="h-5 w-5 text-purple-500 mt-0.5" />
                  <div>
                    <p className="text-sm font-medium text-purple-800">Super Admin — Full Access</p>
                    <p className="text-xs text-purple-600 mt-1">
                      Super Admin always has global access. Permission overrides cannot be applied to this role.
                    </p>
                  </div>
                </div>
              )}
            </Card>
          ) : (
            <Card>
              <div className="text-center py-8 text-gray-500">
                User not found
              </div>
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}

export default PermissionsTab