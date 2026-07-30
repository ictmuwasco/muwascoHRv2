import { useState, useEffect } from 'react'
import api from '../utils/api'
import Card from '../components/ui/Card'
import { Users, CalendarCheck, Calendar, TrendingUp } from 'lucide-react'

const Dashboard = () => {
  const [stats, setStats] = useState({
    totalEmployees: 0,
    presentToday: 0,
    onLeave: 0,
    pendingApprovals: 0,
  })

  useEffect(() => {
    const fetchStats = async () => {
      try {
        const response = await api.get('/dashboard/stats')
        setStats(response.data.data)
      } catch (error) {
        console.error('Failed to fetch dashboard stats:', error)
      }
    }

    fetchStats()
  }, [])

  const statCards = [
    {
      title: 'Total Employees',
      value: stats.totalEmployees,
      icon: Users,
      color: 'bg-blue-500',
    },
    {
      title: 'Present Today',
      value: stats.presentToday,
      icon: CalendarCheck,
      color: 'bg-green-500',
    },
    {
      title: 'On Leave',
      value: stats.onLeave,
      icon: Calendar,
      color: 'bg-yellow-500',
    },
    {
      title: 'Pending Approvals',
      value: stats.pendingApprovals,
      icon: TrendingUp,
      color: 'bg-purple-500',
    },
  ]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>
        <p className="text-gray-500">Welcome to MUWASCO HR Management System</p>
      </div>

      {/* Statistics Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {statCards.map((card) => (
          <Card key={card.title}>
            <div className="flex items-center space-x-4">
              <div className={`p-3 rounded-lg ${card.color}`}>
                <card.icon className="h-6 w-6 text-white" />
              </div>
              <div>
                <p className="text-sm text-gray-500">{card.title}</p>
                <p className="text-2xl font-bold text-gray-900">{card.value}</p>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {/* Recent Activity */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card title="Recent Attendance">
          <p className="text-gray-500 text-sm">Attendance data will be displayed here</p>
        </Card>

        <Card title="Upcoming Leave">
          <p className="text-gray-500 text-sm">Leave data will be displayed here</p>
        </Card>
      </div>
    </div>
  )
}

export default Dashboard