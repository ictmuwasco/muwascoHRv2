import { ReactNode } from 'react';

interface CardProps {
  children: ReactNode;
  className?: string;
  title?: string;
  subtitle?: string;
}

const Card = ({ children, className = '', title, subtitle }: CardProps) => {
  return (
    <div className={`bg-white dark:bg-slate-800 rounded-xl border border-primary-600 dark:border-slate-700 shadow-md shadow-primary-600/40 ${className}`}>
      {(title || subtitle) && (
        <div className="px-6 py-4 border-b dark:border-slate-700">
          {title && (
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">{title}</h3>
          )}
          {subtitle && (
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{subtitle}</p>
          )}
        </div>
      )}
      <div className="p-6">
        {children}
      </div>
    </div>
  )
}

export default Card