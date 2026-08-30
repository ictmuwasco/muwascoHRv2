import { ReactNode } from 'react';

export interface ThemeContextType {
  theme: 'light' | 'dark';
  toggleTheme: () => void;
}

export const useTheme: () => ThemeContextType;

export const ThemeProvider: ({ children }: { children: ReactNode }) => JSX.Element;

declare const ThemeContext: import('react').Context<ThemeContextType | undefined>;
export default ThemeContext;
