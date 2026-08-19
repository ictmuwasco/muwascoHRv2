import apiClient from '../client';

// Types
export interface PermissionAction {
  key: string;
  label: string;
  type: 'page' | 'action';
}

export interface PermissionModule {
  key: string;
  label: string;
  actions: PermissionAction[];
}

export interface RoleInfo {
  key: string;
  label: string;
}

export interface UserPermissionInfo {
  id: number;
  email: string;
  first_name: string | null;
  last_name: string | null;
  surname: string;
  role: string;
  is_active: number;
  designation: string | null;
  employee_id: string | null;
}

export interface RolePermissionRecord {
  module: string;
  action: string;
  is_granted: boolean;
}

export interface PermissionOverride {
  id: number;
  user_id: number;
  module: string;
  action: string;
  permission_type: 'allow' | 'deny';
  granted_by: number | null;
  updated_by: number | null;
  granted_at: string;
  updated_at: string;
  active: number;
  notes: string | null;
}

export interface EffectivePermission {
  module: string;
  module_name: string;
  action: string;
  allowed: boolean;
  source: string;
  permission_type: string | null;
}

export interface UserPermissionsResponse {
  user: UserPermissionInfo;
  role: string;
  role_permissions: RolePermissionRecord[];
  overrides: PermissionOverride[];
  effective: EffectivePermission[];
}

export interface CatalogResponse {
  modules: Record<string, PermissionModule>;
  roles: RoleInfo[];
}

// API Service
export const permissionService = {
  /**
   * Get the permission catalog (modules/actions/roles)
   */
  getCatalog: async (): Promise<CatalogResponse> => {
    const response = await apiClient.get('/permissions/catalog');
    return response.data.data;
  },

  /**
   * Get permission statistics
   */
  getStatistics: async () => {
    const response = await apiClient.get('/permissions/statistics');
    return response.data.data;
  },

  /**
   * Get paginated user list for permission management
   */
  getUsers: async (params?: { search?: string; page?: number; per_page?: number }) => {
    const response = await apiClient.get('/permissions/users', { params });
    return response.data.data;
  },

  /**
   * Get a user's permissions (user info + role perms + overrides + effective)
   */
  getUserPermissions: async (userId: number): Promise<UserPermissionsResponse> => {
    const response = await apiClient.get(`/permissions/users/${userId}`);
    return response.data.data;
  },

  /**
   * Get all permission overrides
   */
  getOverrides: async (params?: { user_id?: number; module?: string; action?: string; permission_type?: string }) => {
    const response = await apiClient.get('/permissions/overrides', { params });
    return response.data.data;
  },

  /**
   * Set/update a permission override
   */
  setOverride: async (
    userId: number,
    data: { module: string; action: string; permission_type: 'allow' | 'deny'; notes?: string }
  ) => {
    const response = await apiClient.post(`/permissions/users/${userId}/overrides`, data);
    return response.data;
  },

  /**
   * Remove (deactivate) a permission override
   */
  removeOverride: async (userId: number, data: { module: string; action: string }) => {
    const response = await apiClient.delete(`/permissions/users/${userId}/overrides`, { data });
    return response.data;
  },

  /**
   * Get all available roles
   */
  getRoles: async () => {
    const response = await apiClient.get('/permissions/roles');
    return response.data.data.roles;
  },
};