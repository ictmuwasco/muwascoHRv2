import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Button from '../components/ui/Button'
import { Users, CalendarCheck, Calendar, TrendingUp, Clock, FileText, Star, Bell } from 'lucide-react'

const Dashboard = () => {
  const navigate = useNavigate()
  const [stats, setStats] = useState({
    totalEmployees: 0,
    presentToday: 0,
    onLeave: 0,
    pendingApprovals: 0,
  })
  
  // Attendance state
  const [attendanceData, setAttendanceData] = useState({
    is_clocked_in: false,
    has_clocked_in_today: false,
    current_session: null,
    today_record: null,
    offices: []
  })
  const [clockingIn, setClockingIn] = useState(false)
  const [clockingOut, setClockingOut] = useState(false)
  const [locationError, setLocationError] = useState('')
  const [selectedOffice, setSelectedOffice] = useState('')
  
  // Notifications state
  const [notifications, setNotifications] = useState([])
  const [unreadCount, setUnreadCount] = useState(0)
  
  // Analytics state
  const [analytics, setAnalytics] = useState({
    attendance: null,
    departments: null,
    leave: null
  })

  useEffect(() => {
    fetchStats()
    fetchAttendanceDashboard()
    fetchNotifications()
    fetchAnalytics()
  }, [])

  const fetchStats = async () => {
    try {
      const response = await api.get('/dashboard/stats')
      setStats(response.data.data)
    } catch (error) {
      console.error('Failed to fetch dashboard stats:', error)
    }
  }

  const fetchAttendanceDashboard = async () => {
    try {
      const response = await api.get('/attendance/dashboard')
      setAttendanceData(response.data.data)
      if (response.data.data.offices?.length > 0) {
        setSelectedOffice(response.data.data.offices[0].id.toString())
      }
    } catch (error) {
      console.error('Failed to fetch attendance data:', error)
    }
  }

  const fetchNotifications = async () => {
    try {
      const response = await api.get('/notifications')
      setNotifications(response.data.data || [])
      setUnreadCount(response.data.unread_count || 0)
    } catch (error) {
      console.error('Failed to fetch notifications:', error)
    }
  }

  const fetchAnalytics = async () => {
    try {
      const [attendanceRes, departmentsRes, leaveRes] = await Promise.all([
        api.get('/dashboard/charts/attendance'),
        api.get('/dashboard/charts/departments'),
        api.get('/dashboard/charts/leave')
      ])
      setAnalytics({
        attendance: attendanceRes.data.data,
        departments: departmentsRes.data.data,
        leave: leaveRes.data.data
      })
    } catch (error) {
      console.error('Failed to fetch analytics:', error)
    }
  }

  const getLocation = () => {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        reject(new Error('Geolocation not supported'))
        return
      }
      navigator.geolocation.getCurrentPosition(
        (position) => {
          resolve({
            lat: position.coords.latitude,
            lng: position.coords.longitude,
            accuracy: position.coords.accuracy
          })
        },
        (error) => reject(error),
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
      )
    })
  }

  const handleClockIn = async () => {
    setClockingIn(true)
    setLocationError('')
    
    try {
      const location = await getLocation()
      const office = attendanceData.offices.find(o => o.id.toString() === selectedOffice)
      
      if (!office) {
        setLocationError('Please select an office')
        setClockingIn(false)
        return
      }

      const response = await api.post('/attendance/clock-in', {
        office_id: parseInt(selectedOffice),
        latitude: location.lat,
        longitude: location.lng,
        accuracy: location.accuracy
      })

      if (response.data.success) {
        fetchAttendanceDashboard()
        fetchStats()
      }
    } catch (error) {
      setLocationError(error.message || 'Failed to clock in')
    } finally {
      setClockingIn(false)
    }
  }

  const handleClockOut = async () => {
    setClockingOut(true)
    setLocationError('')
    
    try {
      const location = await getLocation()
      
      const response = await api.post('/attendance/clock-out', {
        office_id: parseInt(selectedOffice),
        latitude: location.lat,
        longitude: location.lng,
        accuracy: location.accuracy
      })

      if (response.data.success) {
        fetchAttendanceDashboard()
        fetchStats()
      }
    } catch (error) {
      setLocationError(error.message || 'Failed to clock out')
    } finally {
      setClockingOut(false)
    }
  }

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

  const getStatusBadge = () => {
    if (attendanceData.is_clocked_in) {
      return (
        <div className="flex items-center space-x-2">
          <div className="h-3 w-3 bg-green-500 rounded-full animate-pulse"></div>
          <span className="text-sm font-medium text-green-700">Clocked In</span>
        </div>
      )
    } else if (attendanceData.has_clocked_in_today) {
      return (
        <div className="flex items-center space-x-2">
          <div className="h-3 w-3 bg-gray-400 rounded-full"></div>
          <span className="text-sm font-medium text-gray-700">Clocked Out</span>
        </div>
      )
    }
    return (
      <div className="flex items-center space-x-2">
        <div className="h-3 w-3 bg-red-500 rounded-full"></div>
        <span className="text-sm font-medium text-red-700">Not Clocked In</span>
      </div>
    )
  }

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

      {/* Clock In/Clock Out Card */}
      <Card>
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-lg font-semibold text-gray-900">Attendance</h3>
            {getStatusBadge()}
          </div>
          
          {locationError && (
            <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md">
              {locationError}
            </div>
          )}

          {attendanceData.current_session && (
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
              <p className="text-sm text-blue-700">
                <strong>Clocked in at:</strong> {new Date(attendanceData.current_session.clock_in).toLocaleTimeString()}
              </p>
              <p className="text-sm text-blue-700 mt-1">
                <strong>Location:</strong> {attendanceData.current_session.office_name}
              </p>
              {attendanceData.current_session.is_late && (
                <p className="text-sm text-yellow-700 mt-1">
                  <strong>Status:</strong> Late Arrival
                </p>
              )}
            </div>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                {attendanceData.is_clocked_in ? 'Clock Out At:' : 'Clock In At:'}
              </label>
              <select
                value={selectedOffice}
                onChange={(e) => setSelectedOffice(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                disabled={attendanceData.is_clocked_in}
              >
                {attendanceData.offices.map((office) => (
                  <option key={office.id} value={office.id}>
                    {office.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex items-end">
              {attendanceData.is_clocked_in ? (
                <Button
                  onClick={handleClockOut}
                  disabled={clockingOut}
                  variant="danger"
                  className="w-full"
                >
                  {clockingOut ? (
                    <>
                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                      Clocking Out...
                    </>
                  ) : (
                    <>
                      <Clock className="h-4 w-4 mr-2" />
                      Clock Out
                    </>
                  )}
                </Button>
              ) : (
                <Button
                  onClick={handleClockIn}
                  disabled={clockingIn || attendanceData.has_clocked_in_today}
                  className="w-full"
                >
                  {clockingIn ? (
                    <>
                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                      Clocking In...
                    </>
                  ) : (
                    <>
                      <Clock className="h-4 w-4 mr-2" />
                      {attendanceData.has_clocked_in_today ? 'Already Clocked In Today' : 'Clock In'}
                    </>
                  )}
                </Button>
              )}
            </div>
          </div>
        </div>
      </Card>

      {/* Quick Actions */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Card>
          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-4">
              <div className="p-3 bg-blue-100 rounded-lg">
                <FileText className="h-6 w-6 text-blue-600" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900">Apply Leave</h3>
                <p className="text-sm text-gray-500">Submit leave application</p>
              </div>
            </div>
            <Button onClick={() => navigate('/leave')}>
              Apply Now
            </Button>
          </div>
        </Card>

        <Card>
          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-4">
              <div className="p-3 bg-purple-100 rounded-lg">
                <Star className="h-6 w-6 text-purple-600" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900">My Appraisal</h3>
                <p className="text-sm text-gray-500">View performance reviews</p>
              </div>
            </div>
            <Button onClick={() => navigate('/appraisal')} variant="secondary">
              View Appraisals
            </Button>
          </div>
        </Card>
      </div>

      {/* Notifications Widget */}
      <Card>
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center space-x-2">
            <Bell className="h-5 w-5 text-gray-600" />
            <h3 className="text-lg font-semibold text-gray-900">Notifications</h3>
            {unreadCount > 0 && (
              <span className="bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">
                {unreadCount}
              </span>
            )}
          </div>
          <Button variant="outline" size="sm" onClick={fetchNotifications}>
            Refresh
          </Button>
        </div>

        <div className="space-y-3 max-h-96 overflow-y-auto">
          {notifications.length > 0 ? (
            notifications.map((notification) => (
              <div
                key={notification.id}
                className={`p-3 rounded-lg border ${
                  notification.is_read ? 'bg-gray-50 border-gray-200' : 'bg-blue-50 border-blue-200'
                }`}
              >
                <div className="flex items-start justify-between">
                  <div className="flex-1">
                    <h4 className="text-sm font-medium text-gray-900">
                      {notification.title}
                    </h4>
                    <p className="text-sm text-gray-600 mt-1">
                      {notification.message}
                    </p>
                    <p className="text-xs text-gray-500 mt-2">
                      {new Date(notification.created_at).toLocaleString()}
                    </p>
                  </div>
                  {!notification.is_read && (
                    <div className="h-2 w-2 bg-blue-500 rounded-full mt-1"></div>
                  )}
                </div>
              </div>
            ))
          ) : (
            <p className="text-gray-500 text-center py-8">No notifications</p>
          )}
        </div>
      </Card>

      {/* Analytics Graphs */}
      {analytics.attendance && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Card title="Attendance Analytics">
            <div className="h-64 flex items-center justify-center">
              <div className="text-center">
                <CalendarCheck className="h-12 w-12 text-gray-400 mx-auto mb-2" />
                <p className="text-sm text-gray-600">
                  Present: {analytics.attendance.present || 0}
                </p>
                <p className="text-sm text-gray-600">
                  Late: {analytics.attendance.late || 0}
                </p>
              </div>
            </div>
          </Card>

          <Card title="Leave Statistics">
            <div className="h-64 flex items-center justify-center">
              <div className="text-center">
                <Calendar className="h-12 w-12 text-gray-400 mx-auto mb-2" />
                <p className="text-sm text-gray-600">
                  On Leave: {analytics.leave?.on_leave || 0}
                </p>
                <p className="text-sm text-gray-600">
                  Pending: {analytics.leave?.pending || 0}
                </p>
              </div>
            </div>
          </Card>

          <Card title="Department Distribution">
            <div className="h-64 flex items-center justify-center">
              <div className="text-center">
                <Users className="h-12 w-12 text-gray-400 mx-auto mb-2" />
                <p className="text-sm text-gray-600">
                  Total Departments: {analytics.departments?.total_departments || 0}
                </p>
              </div>
            </div>
          </Card>

          <Card title="Employee Statistics">
            <div className="h-64 flex items-center justify-center">
              <div className="text-center">
                <TrendingUp className="h-12 w-12 text-gray-400 mx-auto mb-2" />
                <p className="text-sm text-gray-600">
                  Active Employees: {stats.totalEmployees}
                </p>
              </div>
            </div>
          </Card>
        </div>
      )}
    </div>
  )
}

export default Dashboard