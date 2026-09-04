import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar from './Sidebar'
import Header from './Header'
import DelegateBanner from './DelegateBanner'

const Layout = () => {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  const toggleSidebar = () => setSidebarOpen(!sidebarOpen)
  const closeSidebar = () => setSidebarOpen(false)

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-slate-900">
      <Sidebar isOpen={sidebarOpen} onClose={closeSidebar} />
      <div className="lg:pl-64">
        <Header onToggleSidebar={toggleSidebar} />
        {/* Acting-delegate banner (§27/§28): explains WHY the signed-in user
            temporarily holds delegated authority. Never implies a role change
            — the banner disappears the moment the delegation expires or is
            cancelled because it renders from /auth/user active_delegations. */}
        <DelegateBanner />
        <main className="p-4 md:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

export default Layout
