import { useNavigate, useLocation } from 'react-router-dom'
import Tabs from './ui/Tabs'

const EmployeeTabs = () => {
  const navigate = useNavigate()
  const location = useLocation()

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
      href: '/employees',
      icon: (
        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
        </svg>
      ),
    },
  ]

  const handleTabChange = (tabId) => {
    const tab = tabs.find((t) => t.id === tabId)
    if (tab?.href) {
      navigate(tab.href)
    }
  }

  // Determine active tab based on current path
  const isProfilePage = location.pathname.includes('/profile') || location.pathname.includes('/edit') || location.pathname.includes('/add')
  const activeTab = isProfilePage ? 'employee-profile' : 'employees'

  return <Tabs tabs={tabs} activeTab={activeTab} onChange={handleTabChange} variant="underline" />
}

export default EmployeeTabs
