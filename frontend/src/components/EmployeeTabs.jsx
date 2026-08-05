import { NavLink } from 'react-router-dom'

const EmployeeTabs = () => {
  const tabs = [
    { name: 'Employees', href: '/employees', icon: (
      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10-4v-2a3 3 0 00-5.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2a3 3 0 015.356-1.857m2.644 1.857a3 3 0 00-2.644 1.857" />
      </svg>
    )},
    { name: 'Employee Profile', href: '/employees/profile', icon: (
      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
      </svg>
    )},
  ]

  return (
    <div className="border-b border-gray-200 mb-6">
      <nav className="-mb-px flex space-x-8" aria-label="Employee tabs">
        {tabs.map((tab) => (
          <NavLink
            key={tab.name}
            to={tab.href}
            className={({ isActive }) =>
              `flex items-center space-x-2 py-4 px-1 border-b-2 text-sm font-medium transition-colors ${
                isActive
                  ? 'border-primary-600 text-primary-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`
            }
          >
            {tab.icon}
            <span>{tab.name}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  )
}

export default EmployeeTabs