import { ReactNode } from 'react';

export interface User {
  id: number;
  email: string;
  name: string;
  role?: string;
  permissions?: string[];
  [key: string]: any;
}

export type PermissionCheck = (module: string, action?: string) => boolean;
export type PermissionAnyCheck = (pairs: Array<[string, string]>) => boolean;

export interface AuthContextType {
  user: User | null;
  login: (email: string, password: string) => Promise<{ success: boolean; message?: string }>;
  logout: () => Promise<void>;
  loading: boolean;
  isAuthenticated: boolean;
  can: PermissionCheck;
  canAny: PermissionAnyCheck;
}

export const useAuth: () => AuthContextType;

export const AuthProvider: ({ children }: { children: ReactNode }) => JSX.Element;