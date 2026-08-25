import { ReactNode } from 'react'

export interface TabItem {
  id: string
  name: string
  icon?: ReactNode
  href?: string
}

interface TabsProps {
  tabs: TabItem[]
  activeTab?: string
  onChange?: (tabId: string) => void
  variant?: 'underline' | 'pills' | 'boxed'
  className?: string
  /** Accessible name for the tab list (forwarded to the <nav> element). */
  ariaLabel?: string
}

const Tabs = ({ tabs, activeTab, onChange, variant = 'underline', className = '', ariaLabel }: TabsProps) => {
  const variantStyles: Record<string, any> = {
    underline: {
      container: 'border-b border-gray-200 dark:border-slate-700',
      tab: 'py-4 px-1 border-b-2 text-sm font-medium transition-colors',
      active: 'border-primary-600 text-primary-700 dark:text-primary-400',
      inactive: 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-slate-600',
    },
    pills: {
      container: 'flex space-x-2',
      tab: 'px-4 py-2 rounded-md text-sm font-medium transition-colors',
      active: 'bg-primary-600 text-white',
      inactive: 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-gray-200',
    },
    boxed: {
      container: 'flex space-x-1 border border-gray-200 rounded-lg p-1 bg-gray-50 dark:border-slate-700 dark:bg-slate-900',
      tab: 'px-4 py-2 rounded-md text-sm font-medium transition-colors',
      active: 'bg-white text-primary-700 shadow-sm dark:bg-slate-700 dark:text-primary-400',
      inactive: 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200',
    },
  }

  const styles = variantStyles[variant]

  return (
    <div className={`${styles.container} ${className}`}>
      <nav className="-mb-px flex space-x-8" aria-label={ariaLabel || 'Tabs'}>
        {tabs.map((tab) => {
          const isActive = activeTab ? activeTab === tab.id : false
          const content = (
            <>
              {tab.icon && <span className="mr-2">{tab.icon}</span>}
              <span>{tab.name}</span>
            </>
          )

          const tabClassName = `flex items-center ${styles.tab} ${
            isActive ? styles.active : styles.inactive
          }`

          if (tab.href) {
            return (
              <a
                key={tab.id}
                href={tab.href}
                className={tabClassName}
                onClick={(e) => {
                  e.preventDefault()
                  onChange?.(tab.id)
                }}
              >
                {content}
              </a>
            )
          }

          return (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={isActive}
              onClick={() => onChange?.(tab.id)}
              className={tabClassName}
            >
              {content}
            </button>
          )
        })}
      </nav>
    </div>
  )
}

export default Tabs