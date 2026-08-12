import { ReactNode } from 'react';

export interface User {
  id: number;
  email: string;
  name: string;
  [key: string]: any;
}

export interface AuthContextType {
  user: User | null;
  login: (email: string, password: string) => Promise<{ success: boolean; message?: string }>;
  logout: () => Promise<void>;
  loading: boolean;
  isAuthenticated: boolean;
}

export const useAuth: () => AuthContextType;

export const AuthProvider: ({ children }: { children: ReactNode }) => JSX.Element;