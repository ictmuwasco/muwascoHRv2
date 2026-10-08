import React, { useState, useEffect, useMemo } from 'react';
import {
  Shield,
  Users,
  Search,
  User as UserIcon,
  Check,
  X,
  RefreshCw,
  AlertTriangle,
  Info,
  SlidersHorizontal,
  History,
  Filter,
} from 'lucide-react';
import Card from '../ui/Card';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Badge from '../ui/Badge';
import { permissionService } from '../../api/services/permissionService';
import { useAuth } from '../../context/AuthContext';
// Permission gate (§global rule): reads use permission_overrides:view, writes
// (POST /permissions/users/{id}/overrides, DELETE …) use
// permission_overrides:manage. The /settings/permissions tab route only
// requires settings:permissions, so the Allow/Deny/Inherit controls must check
// the write permission for themselves.
import { Can } from '../ui/PermissionGate';
// Role badge colors — centralized in the global role registry (config/roles.js)
import { ROLE_BADGE_CLASSES, isSuperAdmin } from '../../config/roles';

const roleColor = (role) => ROLE_BADGE_CLASSES[role] || 'bg-gray-100 text-gray-800';

const PermissionsTab = () => {
  const { can } = useAuth();
  /** Write capability for overrides — reads only need permission_overrides:view. */
  const canManageOverrides = can('permission_overrides', 'manage');

  // Tab switcher: 'users' (User Permission Matrix) | 'dashboard' (Overrides Accountability Dashboard)
  const [activeTab, setActiveTab] = useState('users');

  const [catalog, setCatalog] = useState(null);
  const [stats, setStats] = useState(null);
  const [users, setUsers] = useState({ data: [], total: 0, page: 1, pages: 0 });
  const [search, setSearch] = useState('');
  const [moduleSearch, setModuleSearch] = useState('');
  const [selectedUserId, setSelectedUserId] = useState(null);
  const [userPerms, setUserPerms] = useState(null);
  const [loadingUser, setLoadingUser] = useState(false);
  const [error, setError] = useState(null);
  const [successMsg, setSuccessMsg] = useState(null);
  const [saveState, setSaveState] = useState({
    userId: null,
    module: null,
    action: null,
    saving: false,
  });
  const [notes, setNotes] = useState({});

  // Overrides Accountability Dashboard state
  const [overridesList, setOverridesList] = useState([]);
  const [loadingOverrides, setLoadingOverrides] = useState(false);
  const [overrideFilterText, setOverrideFilterText] = useState('');
  const [overrideTypeFilter, setOverrideTypeFilter] = useState('all'); // 'all' | 'allow' | 'deny'
  const [overrideModuleFilter, setOverrideModuleFilter] = useState('all');

  // Load catalog and stats on mount
  useEffect(() => {
    loadCatalog();
    loadStats();
    loadAllOverrides();
  }, []);

  const loadAllOverrides = async () => {
    setLoadingOverrides(true);
    try {
      const data = await permissionService.getOverrides();
      setOverridesList(Array.isArray(data) ? data : []);
    } catch (err) {
      // Overrides fetch error is non-fatal for matrix
    } finally {
      setLoadingOverrides(false);
    }
  };

  // Load users when search changes (debounced)
  useEffect(() => {
    const timer = setTimeout(() => {
      loadUsers(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  const loadCatalog = async () => {
    try {
      const data = await permissionService.getCatalog();
      setCatalog(data);
    } catch (err) {
      setError('Failed to load permission catalog');
    }
  };

  const loadStats = async () => {
    try {
      const data = await permissionService.getStatistics();
      setStats(data);
    } catch (err) {
      // Stats are optional
    }
  };

  const loadUsers = async (page = 1) => {
    try {
      const data = await permissionService.getUsers({
        search: search || undefined,
        page,
        per_page: 10,
      });
      setUsers(data);
    } catch (err) {
      setError('Failed to load users');
    }
  };

  const loadUserPermissions = async (userId) => {
    setLoadingUser(true);
    setError(null);
    setSelectedUserId(userId);
    try {
      const data = await permissionService.getUserPermissions(userId);
      setUserPerms(data);

      // Initialize notes state from existing overrides
      const notesMap = {};
      data.overrides.forEach((ov) => {
        notesMap[`${ov.module}|${ov.action}`] = ov.notes || '';
      });
      setNotes(notesMap);
    } catch (err) {
      setError('Failed to load user permissions');
      setUserPerms(null);
    } finally {
      setLoadingUser(false);
    }
  };

  const handleSaveOverride = async (module, action, permissionType) => {
    if (!selectedUserId) return;
    // Never mutate without the write permission, even if a control is reached
    // through some other path (the API enforces it too).
    if (!canManageOverrides) return;

    setSaveState({ userId: selectedUserId, module, action, saving: true });
    setError(null);
    setSuccessMsg(null);

    try {
      const noteKey = `${module}|${action}`;
      await permissionService.setOverride(selectedUserId, {
        module,
        action,
        permission_type: permissionType,
        notes: notes[noteKey] || undefined,
      });

      // Reload user permissions, stats and overrides list
      await Promise.all([loadUserPermissions(selectedUserId), loadStats(), loadAllOverrides()]);
      setSuccessMsg(`Permission ${module}:${action} set to ${permissionType}`);

      // Clear success after 3s
      setTimeout(() => setSuccessMsg(null), 3000);
    } catch (err) {
      setError(err.response?.data?.message || `Failed to set ${module}:${action}`);
    } finally {
      setSaveState({ userId: null, module: null, action: null, saving: false });
    }
  };

  const handleRemoveOverride = async (module, action) => {
    if (!selectedUserId) return;
    // Same write-permission guard as handleSaveOverride.
    if (!canManageOverrides) return;

    setSaveState({ userId: selectedUserId, module, action, saving: true });
    setError(null);
    setSuccessMsg(null);

    try {
      await permissionService.removeOverride(selectedUserId, { module, action });
      await Promise.all([loadUserPermissions(selectedUserId), loadStats(), loadAllOverrides()]);
      setSuccessMsg(`Override ${module}:${action} removed (will inherit role permission)`);
      setTimeout(() => setSuccessMsg(null), 3000);
    } catch (err) {
      setError(err.response?.data?.message || `Failed to remove ${module}:${action}`);
    } finally {
      setSaveState({ userId: null, module: null, action: null, saving: false });
    }
  };

  const handleRemoveOverrideFromDashboard = async (userId, module, action) => {
    if (!canManageOverrides) return;
    if (!window.confirm(`Are you sure you want to remove the override for ${module}:${action}?`)) {
      return;
    }
    setError(null);
    setSuccessMsg(null);
    try {
      await permissionService.removeOverride(userId, { module, action });
      await Promise.all([
        loadAllOverrides(),
        loadStats(),
        selectedUserId === userId ? loadUserPermissions(userId) : Promise.resolve(),
      ]);
      setSuccessMsg(`Override ${module}:${action} removed successfully`);
      setTimeout(() => setSuccessMsg(null), 3000);
    } catch (err) {
      setError(err.response?.data?.message || `Failed to remove override ${module}:${action}`);
    }
  };

  const getOverrideFor = (module, action) => {
    if (!userPerms) return null;
    return userPerms.overrides.find((o) => o.module === module && o.action === action);
  };

  const getEffectiveFor = (module, action) => {
    if (!userPerms) return null;
    return userPerms.effective.find((e) => e.module === module && e.action === action);
  };

  const handleNotesChange = (module, action, value) => {
    setNotes((prev) => ({ ...prev, [`${module}|${action}`]: value }));
  };

  const formatDate = (dateStr) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleString();
  };

  // Build permission matrix from role_permissions for display
  const rolePermissionMap = useMemo(() => {
    const map = {};
    if (userPerms?.role_permissions) {
      userPerms.role_permissions.forEach((rp) => {
        if (!map[rp.module]) map[rp.module] = {};
        map[rp.module][rp.action] = { granted: rp.is_granted, defined: true };
      });
    }
    return map;
  }, [userPerms]);

  // Filter modules and actions based on moduleSearch
  const filteredModules = useMemo(() => {
    if (!catalog?.modules) return [];
    const query = moduleSearch.trim().toLowerCase();
    if (!query) return Object.entries(catalog.modules);

    return Object.entries(catalog.modules)
      .map(([moduleKey, mod]) => {
        const matchesModule =
          mod.label.toLowerCase().includes(query) || moduleKey.toLowerCase().includes(query);

        // Filter actions within module
        const matchingActions = mod.actions.filter(
          (act) =>
            matchesModule ||
            act.label.toLowerCase().includes(query) ||
            act.key.toLowerCase().includes(query) ||
            `${moduleKey}:${act.key}`.toLowerCase().includes(query),
        );

        if (matchingActions.length > 0) {
          return [moduleKey, { ...mod, actions: matchingActions }];
        }
        return null;
      })
      .filter(Boolean);
  }, [catalog, moduleSearch]);

  // Filter overrides for accountability dashboard
  const filteredDashboardOverrides = useMemo(() => {
    return overridesList.filter((ov) => {
      // Type filter
      if (overrideTypeFilter !== 'all' && ov.permission_type !== overrideTypeFilter) {
        return false;
      }
      // Module filter
      if (overrideModuleFilter !== 'all' && ov.module !== overrideModuleFilter) {
        return false;
      }
      // Text search
      if (overrideFilterText.trim()) {
        const q = overrideFilterText.toLowerCase();
        const userName = `${ov.user_first_name || ''} ${ov.user_last_name || ''}`.toLowerCase();
        const userEmail = (ov.user_email || '').toLowerCase();
        const role = (ov.user_role || '').toLowerCase();
        const mod = (ov.module || '').toLowerCase();
        const act = (ov.action || '').toLowerCase();
        const granter = (ov.granted_by_name || '').toLowerCase();
        const notesText = (ov.notes || '').toLowerCase();

        return (
          userName.includes(q) ||
          userEmail.includes(q) ||
          role.includes(q) ||
          mod.includes(q) ||
          act.includes(q) ||
          granter.includes(q) ||
          notesText.includes(q)
        );
      }
      return true;
    });
  }, [overridesList, overrideTypeFilter, overrideModuleFilter, overrideFilterText]);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold flex items-center gap-2 text-gray-900 dark:text-gray-100">
            <Shield className="h-5 w-5 text-blue-600" />
            Permission Management
          </h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 dark:text-gray-400 mt-1">
            Hybrid RBAC + User Overrides — {stats?.total_users ?? '...'} users,{' '}
            {stats?.total_roles ?? '...'} roles, {stats?.total_modules ?? '...'} modules
          </p>
        </div>
        {stats && (
          <div className="flex gap-3">
            <Badge className="bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300">
              {stats.total_overrides} Overrides
            </Badge>
            <Badge className="bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
              {stats.allow_count} Allowed
            </Badge>
            <Badge className="bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">
              {stats.deny_count} Denied
            </Badge>
          </div>
        )}
      </div>

      {error && (
        <div className="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-md flex items-center gap-2">
          <AlertTriangle className="h-4 w-4" />
          {error}
        </div>
      )}

      {successMsg && (
        <div className="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-400 dark:text-green-300 px-4 py-3 rounded-md flex items-center gap-2">
          <Check className="h-4 w-4" />
          {successMsg}
        </div>
      )}

      {!canManageOverrides && (
        <div className="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200 px-4 py-3 rounded-md flex items-start gap-2">
          <Info className="h-4 w-4 mt-0.5 shrink-0" />
          <div className="text-sm">
            <p className="font-medium">Read-only permission view</p>
            <p className="mt-0.5">
              You can review role permissions, overrides and effective access for every user, but
              changing an override requires the <strong>Manage</strong> permission on Permission
              Overrides (permission_overrides:manage).
            </p>
          </div>
        </div>
      )}

      {/* Sub Tabs navigation: User Permissions vs Overrides Dashboard */}
      <div className="flex border-b border-gray-200 dark:border-slate-700 space-x-4">
        <button
          onClick={() => setActiveTab('users')}
          className={`flex items-center gap-2 pb-3 px-1 border-b-2 text-sm font-medium transition-colors ${
            activeTab === 'users'
              ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200'
          }`}
        >
          <SlidersHorizontal className="h-4 w-4" />
          User Permissions Matrix
        </button>
        <button
          onClick={() => {
            setActiveTab('dashboard');
            loadAllOverrides();
          }}
          className={`flex items-center gap-2 pb-3 px-1 border-b-2 text-sm font-medium transition-colors ${
            activeTab === 'dashboard'
              ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200'
          }`}
        >
          <History className="h-4 w-4" />
          Overrides Accountability Dashboard
          {overridesList.length > 0 && (
            <span className="ml-1 px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300 font-semibold">
              {overridesList.length}
            </span>
          )}
        </button>
      </div>

      {/* VIEW 1: USER PERMISSIONS MATRIX */}
      {activeTab === 'users' && (
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
                    className={`w-full text-left px-3 py-2 rounded-md transition-colors ${
                      selectedUserId === user.id
                        ? 'bg-blue-50 dark:bg-blue-900/30 border-blue-200 dark:border-blue-800 border'
                        : 'hover:bg-gray-50 dark:hover:bg-slate-700/40'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <div className="h-8 w-8 rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center">
                        <UserIcon className="h-4 w-4 text-gray-500 dark:text-gray-300" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium truncate">
                          {user.first_name || user.last_name || user.email}
                        </p>
                        <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
                          {user.email}
                        </p>
                      </div>
                      <Badge className={roleColor(user.role)}>
                        {user.role?.replace(/_/g, ' ')}
                      </Badge>
                    </div>
                  </button>
                ))}
                {users.data.length === 0 && (
                  <p className="text-center text-sm text-gray-400 dark:text-gray-500 py-6">
                    No users found
                  </p>
                )}
              </div>

              {/* Pagination */}
              {users.pages > 1 && (
                <div className="flex items-center justify-between mt-3 pt-3 border-t border-gray-200 dark:border-slate-700">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={users.page <= 1}
                    onClick={() => loadUsers(users.page - 1)}
                  >
                    Prev
                  </Button>
                  <span className="text-sm text-gray-500 dark:text-gray-400">
                    Page {users.page} / {users.pages}
                  </span>
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
                  <Users className="h-12 w-12 mx-auto mb-4 text-gray-300 dark:text-slate-600" />
                  <p className="text-gray-500 dark:text-gray-300 font-medium">
                    Select a user to manage permissions
                  </p>
                  <p className="text-sm text-gray-400 dark:text-gray-500 mt-1">
                    Configure user-specific allow/deny overrides that affect their role-based
                    permissions
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
                {/* User info header & Module Search Bar */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4 pb-4 border-b border-gray-200 dark:border-slate-700">
                  <div>
                    <h3 className="font-semibold flex items-center gap-2">
                      {userPerms.user.first_name} {userPerms.user.last_name}
                      <Badge className={roleColor(userPerms.user.role)}>
                        {userPerms.user.role?.replace(/_/g, ' ')}
                      </Badge>
                    </h3>
                    <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                      {userPerms.user.email}{' '}
                      {userPerms.user.designation && `• ${userPerms.user.designation}`}
                    </p>
                  </div>

                  {/* Module search input matching user search */}
                  <div className="w-full sm:w-72 relative">
                    <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
                    <Input
                      value={moduleSearch}
                      onChange={(e) => setModuleSearch(e.target.value)}
                      placeholder="Search modules or actions..."
                      className="pl-9 text-sm"
                    />
                    {moduleSearch && (
                      <button
                        onClick={() => setModuleSearch('')}
                        className="absolute right-2.5 top-2.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                        title="Clear module search"
                      >
                        <X className="h-4 w-4" />
                      </button>
                    )}
                  </div>
                </div>

                {/* Permission matrix */}
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                    <thead>
                      <tr className="bg-gray-50 dark:bg-slate-900">
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          Module
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          Action
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          Role
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          Override
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          Effective
                        </th>
                      </tr>
                    </thead>
                    <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-100 dark:divide-slate-700">
                      {filteredModules.length === 0 ? (
                        <tr>
                          <td
                            colSpan={5}
                            className="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500"
                          >
                            No modules or actions match &ldquo;{moduleSearch}&rdquo;
                          </td>
                        </tr>
                      ) : (
                        filteredModules.map(([moduleKey, module]) => (
                          <React.Fragment key={moduleKey}>
                            {module.actions.map((action) => {
                              const rolePerm = rolePermissionMap[moduleKey]?.[action.key];
                              const override = getOverrideFor(moduleKey, action.key);
                              const effective = getEffectiveFor(moduleKey, action.key);
                              const isSaving =
                                saveState.saving &&
                                saveState.userId === selectedUserId &&
                                saveState.module === moduleKey &&
                                saveState.action === action.key;

                              return (
                                <tr
                                  key={`${moduleKey}-${action.key}`}
                                  className="hover:bg-gray-50 dark:hover:bg-slate-700/40"
                                >
                                  <td className="px-4 py-3 text-sm font-medium">
                                    {module.label}
                                    {action.type === 'page' && (
                                      <span className="ml-1 text-[10px] text-gray-400 dark:text-gray-500 uppercase">
                                        Page
                                      </span>
                                    )}
                                  </td>
                                  <td className="px-4 py-3 text-sm">{action.label}</td>
                                  <td className="px-4 py-3">
                                    {rolePerm?.defined ? (
                                      rolePerm.granted ? (
                                        <span className="inline-flex items-center gap-1 text-green-700 dark:text-green-400">
                                          <Check className="h-3.5 w-3.5" /> Granted
                                        </span>
                                      ) : (
                                        <span className="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                                          <X className="h-3.5 w-3.5" /> Denied
                                        </span>
                                      )
                                    ) : (
                                      <span className="text-gray-400 dark:text-gray-500 text-xs">
                                        Not defined
                                      </span>
                                    )}
                                  </td>
                                  <td className="px-4 py-3">
                                    <Can
                                      module="permission_overrides"
                                      action="manage"
                                      fallback={
                                        <span className="text-xs italic text-gray-400 dark:text-gray-500">
                                          View only — permission_overrides:manage is required to
                                          change overrides.
                                        </span>
                                      }
                                    >
                                      <div className="flex items-center gap-2">
                                        <button
                                          onClick={() =>
                                            handleSaveOverride(moduleKey, action.key, 'allow')
                                          }
                                          disabled={isSaving || isSuperAdmin(userPerms.user.role)}
                                          className={`px-2 py-1 rounded text-xs font-medium transition-colors ${
                                            override?.permission_type === 'allow'
                                              ? 'bg-green-600 text-white'
                                              : 'bg-green-50 text-green-700 dark:text-green-400 hover:bg-green-100 disabled:opacity-50'
                                          }`}
                                        >
                                          Allow
                                        </button>
                                        <button
                                          onClick={() =>
                                            handleSaveOverride(moduleKey, action.key, 'deny')
                                          }
                                          disabled={isSaving || isSuperAdmin(userPerms.user.role)}
                                          className={`px-2 py-1 rounded text-xs font-medium transition-colors ${
                                            override?.permission_type === 'deny'
                                              ? 'bg-red-600 text-white'
                                              : 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 hover:bg-red-100 dark:hover:bg-red-900/50 disabled:opacity-50'
                                          }`}
                                        >
                                          Deny
                                        </button>
                                        <button
                                          onClick={() =>
                                            handleRemoveOverride(moduleKey, action.key)
                                          }
                                          disabled={
                                            !override ||
                                            isSaving ||
                                            isSuperAdmin(userPerms.user.role)
                                          }
                                          title="Remove override (inherit role)"
                                          className="px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-slate-600 disabled:opacity-50"
                                        >
                                          Inherit
                                        </button>
                                        {isSaving && (
                                          <RefreshCw className="h-3 w-3 animate-spin text-blue-500" />
                                        )}
                                      </div>

                                      {/* Notes input for overrides */}
                                      {override && (
                                        <div className="mt-2">
                                          <Input
                                            size="sm"
                                            value={notes[`${moduleKey}|${action.key}`] || ''}
                                            onChange={(e) =>
                                              handleNotesChange(
                                                moduleKey,
                                                action.key,
                                                e.target.value,
                                              )
                                            }
                                            placeholder="Add note (e.g., Temporary access for audit)"
                                            className="text-xs"
                                          />
                                          <div className="flex items-center justify-between mt-1">
                                            <span className="text-[10px] text-gray-400 dark:text-gray-500">
                                              {override.granted_at &&
                                                `Granted: ${formatDate(override.granted_at)}`}
                                            </span>
                                            <button
                                              onClick={() =>
                                                handleSaveOverride(
                                                  moduleKey,
                                                  action.key,
                                                  override.permission_type,
                                                )
                                              }
                                              className="text-[10px] text-blue-600 dark:text-blue-400 hover:underline"
                                            >
                                              Save note
                                            </button>
                                          </div>
                                        </div>
                                      )}
                                    </Can>
                                  </td>
                                  <td className="px-4 py-3">
                                    {effective?.allowed !== undefined ? (
                                      effective.allowed ? (
                                        <div className="flex items-center gap-1.5">
                                          <span className="inline-flex h-2 w-2 rounded-full bg-green-500"></span>
                                          <span className="text-sm text-green-700 dark:text-green-400">
                                            Allowed
                                          </span>
                                          {effective.source && effective.source !== 'Role' && (
                                            <span className="text-[10px] text-gray-400 dark:text-gray-500">
                                              ({effective.source})
                                            </span>
                                          )}
                                        </div>
                                      ) : (
                                        <div className="flex items-center gap-1.5">
                                          <span className="inline-flex h-2 w-2 rounded-full bg-red-500"></span>
                                          <span className="text-sm text-red-600 dark:text-red-400">
                                            Denied
                                          </span>
                                          {effective.source && effective.source !== 'Role' && (
                                            <span className="text-[10px] text-gray-400 dark:text-gray-500">
                                              ({effective.source})
                                            </span>
                                          )}
                                        </div>
                                      )
                                    ) : (
                                      <span className="inline-flex items-center gap-1.5">
                                        <span className="inline-flex h-2 w-2 rounded-full bg-gray-300"></span>
                                        <span className="text-sm text-gray-500 dark:text-gray-400">
                                          Unknown
                                        </span>
                                      </span>
                                    )}
                                  </td>
                                </tr>
                              );
                            })}
                          </React.Fragment>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>

                {/* Super Admin notice */}
                {isSuperAdmin(userPerms.user.role) && (
                  <div className="mt-4 bg-purple-50 dark:bg-purple-900/30 border border-purple-200 dark:border-purple-800 rounded-md p-4 flex items-start gap-3">
                    <Info className="h-5 w-5 text-purple-500 mt-0.5" />
                    <div>
                      <p className="text-sm font-medium text-purple-800 dark:text-purple-200">
                        Super Admin — Full Access
                      </p>
                      <p className="text-xs text-purple-600 dark:text-purple-300 mt-1">
                        Super Admin always has global access. Permission overrides cannot be applied
                        to this role.
                      </p>
                    </div>
                  </div>
                )}
              </Card>
            ) : (
              <Card>
                <div className="text-center py-8 text-gray-500">User not found</div>
              </Card>
            )}
          </div>
        </div>
      )}

      {/* VIEW 2: OVERRIDES ACCOUNTABILITY DASHBOARD */}
      {activeTab === 'dashboard' && (
        <Card>
          <div className="space-y-4">
            {/* Dashboard Header & Description */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-gray-200 dark:border-slate-700 pb-4">
              <div>
                <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                  <History className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                  Accountability &amp; Override Audit Trail
                </h3>
                <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                  Complete view of all active page and feature overrides, indicating whose access
                  was modified, which page/action was affected, and who granted it.
                </p>
              </div>
              <Button
                variant="outline"
                size="sm"
                onClick={loadAllOverrides}
                disabled={loadingOverrides}
                className="flex items-center gap-2 self-start md:self-auto"
              >
                <RefreshCw className={`h-4 w-4 ${loadingOverrides ? 'animate-spin' : ''}`} />
                Refresh
              </Button>
            </div>

            {/* Dashboard Filters Toolbar */}
            <div className="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
              <div className="relative flex-1 max-w-md">
                <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
                <Input
                  value={overrideFilterText}
                  onChange={(e) => setOverrideFilterText(e.target.value)}
                  placeholder="Filter by user, module, action, grantor, or notes..."
                  className="pl-9 text-sm"
                />
                {overrideFilterText && (
                  <button
                    onClick={() => setOverrideFilterText('')}
                    className="absolute right-2.5 top-2.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                  >
                    <X className="h-4 w-4" />
                  </button>
                )}
              </div>

              <div className="flex flex-wrap items-center gap-3">
                {/* Type Filter */}
                <div className="flex items-center gap-1.5 text-sm">
                  <Filter className="h-4 w-4 text-gray-400" />
                  <span className="text-xs text-gray-500 dark:text-gray-400">Type:</span>
                  <select
                    value={overrideTypeFilter}
                    onChange={(e) => setOverrideTypeFilter(e.target.value)}
                    className="text-xs border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 text-gray-800 dark:text-gray-200 py-1.5 px-2"
                  >
                    <option value="all">All Types</option>
                    <option value="allow">Allow Only</option>
                    <option value="deny">Deny Only</option>
                  </select>
                </div>

                {/* Module Filter */}
                {catalog?.modules && (
                  <div className="flex items-center gap-1.5 text-sm">
                    <span className="text-xs text-gray-500 dark:text-gray-400">Module:</span>
                    <select
                      value={overrideModuleFilter}
                      onChange={(e) => setOverrideModuleFilter(e.target.value)}
                      className="text-xs border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 text-gray-800 dark:text-gray-200 py-1.5 px-2 max-w-[180px]"
                    >
                      <option value="all">All Modules</option>
                      {Object.entries(catalog.modules).map(([k, m]) => (
                        <option key={k} value={k}>
                          {m.label}
                        </option>
                      ))}
                    </select>
                  </div>
                )}
              </div>
            </div>

            {/* Overrides Table */}
            {loadingOverrides ? (
              <div className="py-12 flex justify-center items-center">
                <RefreshCw className="h-6 w-6 animate-spin text-blue-500" />
              </div>
            ) : filteredDashboardOverrides.length === 0 ? (
              <div className="py-12 text-center">
                <p className="text-gray-600 dark:text-gray-400 font-medium">No overrides found</p>
                <p className="text-xs text-gray-400 mt-1">
                  {overrideFilterText ||
                  overrideTypeFilter !== 'all' ||
                  overrideModuleFilter !== 'all'
                    ? 'Try clearing the search or filters.'
                    : 'No custom page or action permissions have been overridden.'}
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                  <thead>
                    <tr className="bg-gray-50 dark:bg-slate-900">
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Target User
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Role &amp; Org
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Overridden Permission
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Status / Override
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Granted By &amp; When
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Reason / Notes
                      </th>
                      {canManageOverrides && (
                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                          Actions
                        </th>
                      )}
                    </tr>
                  </thead>
                  <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-100 dark:divide-slate-700">
                    {filteredDashboardOverrides.map((ov) => {
                      const moduleObj = catalog?.modules?.[ov.module];
                      const moduleLabel = moduleObj?.label || ov.module;
                      const actionObj = moduleObj?.actions?.find((a) => a.key === ov.action);
                      const actionLabel = actionObj?.label || ov.action;

                      return (
                        <tr
                          key={ov.id || `${ov.user_id}-${ov.module}-${ov.action}`}
                          className="hover:bg-gray-50 dark:hover:bg-slate-700/40"
                        >
                          {/* Target user */}
                          <td className="px-4 py-3">
                            <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                              {ov.user_first_name} {ov.user_last_name}
                            </div>
                            <div className="text-xs text-gray-500 dark:text-gray-400">
                              {ov.user_email}
                            </div>
                          </td>

                          {/* Role & Org */}
                          <td className="px-4 py-3 text-xs">
                            <Badge className={roleColor(ov.user_role)}>
                              {ov.user_role?.replace(/_/g, ' ')}
                            </Badge>
                            {ov.department && (
                              <div className="text-gray-500 dark:text-gray-400 mt-1">
                                {ov.department}
                                {ov.section && ` › ${ov.section}`}
                              </div>
                            )}
                          </td>

                          {/* Overridden module & action */}
                          <td className="px-4 py-3">
                            <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                              {moduleLabel}
                              {actionObj?.type === 'page' && (
                                <span className="ml-1 text-[10px] text-gray-400 dark:text-gray-500 uppercase">
                                  Page
                                </span>
                              )}
                            </div>
                            <div className="text-xs text-blue-600 dark:text-blue-400 font-mono">
                              {ov.module}:{ov.action} ({actionLabel})
                            </div>
                          </td>

                          {/* Override Type */}
                          <td className="px-4 py-3">
                            {ov.permission_type === 'allow' ? (
                              <Badge className="bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300 flex items-center w-fit gap-1">
                                <Check className="h-3 w-3" /> ALLOWED
                              </Badge>
                            ) : (
                              <Badge className="bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300 flex items-center w-fit gap-1">
                                <X className="h-3 w-3" /> DENIED
                              </Badge>
                            )}
                          </td>

                          {/* Granted by & date */}
                          <td className="px-4 py-3 text-xs">
                            <div className="font-medium text-gray-800 dark:text-gray-200">
                              {ov.granted_by_name || `Admin #${ov.granted_by || '—'}`}
                            </div>
                            <div className="text-gray-500 dark:text-gray-400 mt-0.5">
                              {formatDate(ov.granted_at)}
                            </div>
                          </td>

                          {/* Reason / Notes */}
                          <td className="px-4 py-3 text-xs text-gray-600 dark:text-gray-300 max-w-xs truncate">
                            {ov.notes || (
                              <span className="text-gray-400 italic">No notes provided</span>
                            )}
                          </td>

                          {/* Actions */}
                          {canManageOverrides && (
                            <td className="px-4 py-3 text-right text-xs">
                              <div className="flex items-center justify-end gap-2">
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => {
                                    setSelectedUserId(ov.user_id);
                                    setActiveTab('users');
                                    loadUserPermissions(ov.user_id);
                                  }}
                                  className="text-blue-600 hover:text-blue-700 text-xs px-2 py-1"
                                >
                                  Edit in Matrix
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() =>
                                    handleRemoveOverrideFromDashboard(
                                      ov.user_id,
                                      ov.module,
                                      ov.action,
                                    )
                                  }
                                  className="text-red-600 hover:text-red-700 text-xs px-2 py-1"
                                  title="Remove override (inherit role default)"
                                >
                                  Remove
                                </Button>
                              </div>
                            </td>
                          )}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </Card>
      )}
    </div>
  );
};

export default PermissionsTab;
