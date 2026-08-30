import { useState, useEffect, useMemo } from 'react'
import { useNavigate, NavLink, Outlet, useOutletContext } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import { AlertTriangle, CheckCircle, X, Clock } from 'lucide-react'

const TABS = [
  { id: 'pending',  name: 'Pending',  icon: <Clock className="h-4 w-4" />,        href: '/leave/manage/pending' },
  { id: 'approved', name: 'Approved', icon: <CheckCircle className="h-4 w-4" />, href: '/leave/manage/approved' },
  { id: 'rejected', name: 'Rejected', icon: <X className="h-4 w-4" />,           href: '/leave/manage/rejected' },
]

export const MANAGER_ROLES = [
  'sub_section_head', 'section_head', 'dept_head',
  'managing_director', 'hr_manager', 'super_admin',
  'bod_chair', 'bod_chairman',
]

export const isManager = (user) => {
  const r = (user?.role || '').toLowerCase()
  return MANAGER_ROLES.includes(r)
}

const fetchCounts = async () => {
  try {
    const response = await api.get('/leave/manage', { params: { limit: 1 } })
    return {
      counts: response.data?.data?.counts || { pending: 0, approved: 0, rejected: 0 },
      role: response.data?.data?.role || '',
    }
  } catch {
    return { counts: { pending: 0, approved: 0, rejected: 0 }, role: '' }
  }
}

const ManageLeaveLayout = () => {
  const navigate = useNavigate()
  const { user } = useAuth()
  const isManagerRole = useMemo(() => isManager(user), [user])

  const [counts, setCounts] = useState({ pending: 0, approved: 0, rejected: 0 })
  const [pageRole, setPageRole] = useState('')
  const [loadFailed, setLoadFailed] = useState(false)

  useEffect(() => {
    if (!isManagerRole) return
    let cancelled = false
    fetchCounts().then(({ counts: c, role }) => {
      if (cancelled) return
      setCounts(c)
      setPageRole(role)
    }).catch(() => {
      if (!cancelled) setLoadFailed(true)
    })
    return () => { cancelled = true }
  }, [isManagerRole])

  if (!isManagerRole) {
    return (
      <div className="space-y-6">
        <Card>
          <div className="text-center py-12">
            <AlertTriangle className="h-10 w-10 mx-auto text-yellow-500 mb-3" />
            <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Not authorised</h2>
            <p className="text-gray-500 dark:text-gray-400 mt-1">
              The Manage Leave page is only available to supervisors and HR.
            </p>
            <div className="mt-4">
              <Button variant="outline" onClick={() => navigate('/leave')}>
                Back to Leaves
              </Button>
            </div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Manage Leave</h1>
          <p className="text-gray-500 dark:text-gray-400">
            Review and act on leave applications{pageRole ? ` (role: ${pageRole})` : ''}.
          </p>
        </div>
        <div className="text-sm text-gray-500 dark:text-gray-400">
          <span className="font-medium text-gray-900 dark:text-gray-100">{counts.pending}</span> pending
          {' · '}
          <span className="font-medium text-gray-900 dark:text-gray-100">{counts.approved}</span> approved
          {' · '}
          <span className="font-medium text-gray-900 dark:text-gray-100">{counts.rejected}</span> rejected
        </div>
      </div>

      {/* Horizontal tab nav */}
      <div className="border-b border-gray-200 dark:border-slate-700 overflow-x-auto scrollbar-thin">
        <nav className="-mb-px flex space-x-1 sm:space-x-8" aria-label="Manage Leave tabs">
          {TABS.map((tab) => (
            <NavLink
              key={tab.id}
              to={tab.href}
              className={({ isActive }) =>
                `flex items-center space-x-1 sm:space-x-2 py-3 px-2 sm:px-1 border-b-2 text-xs sm:text-sm font-medium transition-colors whitespace-nowrap ${
                  isActive
                    ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-400'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-slate-500'
                }`
              }
            >
              {tab.icon}
              <span className="hidden xs:inline">{tab.name}</span>
              <span className="xs:hidden">{tab.name}</span>
              {tab.id in counts && (
                <span className="ml-1 inline-flex items-center justify-center px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-gray-200">
                  {counts[tab.id]}
                </span>
              )}
            </NavLink>
          ))}
        </nav>
      </div>

      {loadFailed && (
        <div className="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-md">
          Could not load leave counts. Tab pages may be empty.
        </div>
      )}

      <Outlet context={{ counts, refreshCounts: () => fetchCounts().then(({ counts: c, role }) => { setCounts(c); setPageRole(role) }) }} />
    </div>
  )
}

export const useManageContext = () => useOutletContext()

export default ManageLeaveLayout

