import { ReactNode } from 'react';

interface BadgeProps {
  children: ReactNode;
  variant?: 'default' | 'primary' | 'success' | 'warning' | 'danger';
  className?: string;
}

const Badge = ({ children, variant = 'default', className = '' }: BadgeProps) => {
  const variants: Record<string, string> = {
    default: 'bg-gray-100 text-gray-800 dark:bg-slate-700 dark:text-gray-200',
    primary: 'bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-200',
    success: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
    warning: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200',
    danger: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
  }

  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${variants[variant]} ${className}`}>
      {children}
    </span>
  )
}

export default Badge