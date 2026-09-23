import { ReactNode } from 'react';
import type { User } from '../types';

export type PermissionCheck = (module: string, action?: string) => boolean;
export type PermissionAnyCheck = (pairs: Array<[string, string]>) => boolean;
export type PermissionMutationCheck = (module: string) => boolean;
export type RoleCheck = (roles: string | string[]) => boolean;

export interface AuthContextType {
  user: User | null;
  login: (email: string, password: string) => Promise<{ success: boolean; message?: string }>;
  logout: () => Promise<void>;
  loading: boolean;
  isAuthenticated: boolean;
  can: PermissionCheck;
  canAny: PermissionAnyCheck;
  /** Mandatory mutation gate: view alone never unlocks Edit. */
  canEdit: PermissionMutationCheck;
  /** Mandatory destruction gate: only an explicit `<module>:delete` unlocks Delete. */
  canDelete: PermissionMutationCheck;
  hasRole: RoleCheck;
  refreshPermissions: () => Promise<void>;
}

export const useAuth: () => AuthContextType;

export const AuthProvider: ({ children }: { children: ReactNode }) => JSX.Element;
