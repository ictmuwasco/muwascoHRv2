export const ROLE_KEYS: string[];
export const ROLE_LABELS: Record<string, string>;
export const ROLE_BADGE_CLASSES: Record<string, string>;

export const NO_ORG_ROLES: string[];
export const DEPT_ONLY_ROLES: string[];
export const SECTION_ROLES: string[];
export const WIDE_SCOPE_ROLES: string[];
export const MONITORING_ROLES: string[];
export const SUPERVISOR_ROLES: string[];
export const BROAD_ACCESS_ROLES: string[];

export const SUPER_ADMIN: string;

export const getRoleLabel: (roleKey: string) => string;
export const getRoleBadgeClass: (roleKey: string) => string;
export const roleOptions: () => Array<{ value: string; label: string }>;
export const roleInGroup: (roles: string | string[], roleKey: string) => boolean;
export const isSuperAdmin: (roleKey: string) => boolean;

export const fetchRoles: () => Promise<Array<{ key: string; label: string }> | null>;
export const useRoleOptions: () => Array<{ value: string; label: string }>;