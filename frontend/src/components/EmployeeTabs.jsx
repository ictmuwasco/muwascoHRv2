import Tabs from './ui/Tabs'

const EmployeeTabs = () => {
  const tabs = [
    {
      id: 'employees',
      name: 'Employees',
      href: '/employees',
      icon: (
        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10-4v-2a3 3 0 00-5.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2a3 3 0 015.356-1.857m2.644 1.857a3 3 0 00-2.644 1.857" />
        </svg>
      ),
    },
    {
      id: 'employee-profile',
      name: 'Employee Profile',
      href: '/employees/profile',
      icon: (
        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
        </svg>
      ),
    },
  ]

  return <Tabs tabs={tabs} variant="underline" />
}

export default EmployeeTabs