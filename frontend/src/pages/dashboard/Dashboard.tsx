import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../utils/api'
import { requestLocation } from '../../utils/geolocation'
import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import { Users, CalendarCheck, Calendar, TrendingUp, Clock, FileText, Star, Bell } from 'lucide-react'

interface Stats {
  totalEmployees: number
  presentToday: number
  onLeave: number
  pendingApprovals: number
}

interface Office {
  id: number
  name: string
  latitude: number
  longitude: number
  geo_fence_radius: number
}

interface CurrentSession {
  id: number
  clock_in: string
  clock_out: string | null
  office_name: string
  office_id: number
  is_late: number
  lat: number
  lng: number
  accuracy: number
  status: string
}

interface AttendanceData {
  is_clocked_in: boolean
  has_clocked_in_today: boolean
  current_session: CurrentSession | null
  today_record: Record<string, any> | null
  /** Employee's assigned office (State A). null when unassigned (State C). */
  default_office: Office | null
  /** 'default' = State A, 'alternative' = State B, 'manual' = State C */
  office_mode: 'default' | 'alternative' | 'manual'
  offices: Office[]
}

interface Notification {
  id: number
  is_read: number
  title: string
  message: string
  created_at: string
}

interface Analytics {
  attendance: Record<string, any> | null
  departments: Record<string, any> | null
  leave: Record<string, any> | null
}

/**
 * Great-circle distance between two coordinates, in whole metres (Haversine).
 */
const haversineMeters = (
  lat1: number,
  lng1: number,
  lat2: number,
  lng2: number
): number => {
  const R = 6371000
  const rad = (d: number) => (d * Math.PI) / 180
  const dLat = rad(lat2 - lat1)
  const dLng = rad(lng2 - lng1)
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2
  return Math.round(2 * R * Math.asin(Math.min(1, Math.sqrt(a))))
}

/** Human-friendly metres: "80 m" or "1.2 km". */
const formatDistance = (meters: number): string =>
  meters >= 1000
    ? `${(meters / 1000).toFixed(meters >= 10000 ? 0 : 1)} km`
    : `${Math.round(meters)} m`

const Dashboard = () => {
  const navigate = useNavigate()
  const [stats, setStats] = useState<Stats>({
    totalEmployees: 0,
    presentToday: 0,
    onLeave: 0,
    pendingApprovals: 0,
  })

  // Attendance state - mirrors the backend payload (backend is source of truth)
  const [attendanceData, setAttendanceData] = useState<AttendanceData>({
    is_clocked_in: false,
    has_clocked_in_today: false,
    current_session: null,
    today_record: null,
    default_office: null,
    office_mode: 'manual',
    offices: []
  })
  const [clockingIn, setClockingIn] = useState(false)
  const [clockingOut, setClockingOut] = useState(false)
  const [locationError, setLocationError] = useState('')
  const [actionMessage, setActionMessage] = useState('')
  const [selectedOffice, setSelectedOffice] = useState('')

  /**
   * Set when a fix could not be obtained at all, OR when a fix was
   * obtained but placed the employee outside the office geofence.
   * Carries everything needed to explain what went wrong, how far
   * they are, and how much closer they need to be.
   */
  const [locationFallback, setLocationFallback] = useState<{
    action: 'clock-in' | 'clock-out'
    code: string
    message: string
    officeName?: string
    requiredRadius?: number
    measuredDistance?: number
  } | null>(null)

  /** Which action is actively acquiring a GPS/Wi-Fi fix right now. */
  const [locating, setLocating] = useState<'clock-in' | 'clock-out' | null>(null)

  // Refs to prevent duplicate requests (state updates are async)
  const clockInInFlight = useRef(false)
  const clockOutInFlight = useRef(false)

  // Notifications state
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [unreadCount, setUnreadCount] = useState(0)

  // Analytics state
  const [analytics, setAnalytics] = useState<Analytics>({
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
      // Explicit cast: response.data is untyped (any), which previously made
      // the offices callback parameter implicitly-any below (TS error).
      const data = response.data.data as AttendanceData
      setAttendanceData(data)

      // The backend decides which office is pre-selected.
      // States A/B: the employee's assigned office. State C (no assignment):
      // keep the current pick while still valid, else fall back to the first
      // recognised office so the card never sits without a selection.
      // Functional update avoids reading a stale selectedOffice closure.
      const defaultId = data.default_office?.id
      if (defaultId != null) {
        setSelectedOffice(String(defaultId))
        return
      }

      setSelectedOffice((prev) => {
        if (data.offices?.some((o: Office) => String(o.id) === prev)) {
          return prev
        }
        return data.offices && data.offices.length > 0 ? String(data.offices[0].id) : prev
      })
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

  /**
   * Extract a user-friendly error message from an API error.
   */
  const getErrorMessage = (error: any): string => {
    if (error.response?.data?.message) {
      return error.response.data.message
    }
    if (error.code === 'ECONNABORTED') {
      return 'Request timed out. Please check your connection and try again.'
    }
    if (error.message) {
      return error.message
    }
    return 'An unexpected error occurred. Please try again.'
  }

  /**
   * Shared submit path for clock-in / clock-out. A device fix is
   * MANDATORY: the caller must resolve coordinates (startClock blocks
   * submission when no fix or when outside the geofence) before this
   * runs, and the backend independently re-validates the geofence.
   */
  const submitClock = async (
    action: 'clock-in' | 'clock-out',
    coords: { lat: number; lng: number; accuracy: number }
  ) => {
    const inFlight = action === 'clock-in' ? clockInInFlight : clockOutInFlight
    const isBusy = action === 'clock-in' ? clockingIn : clockingOut
    if (inFlight.current || isBusy) return

    inFlight.current = true
    setClockingIn(action === 'clock-in')
    setClockingOut(action === 'clock-out')
    setLocationError('')
    setActionMessage('')
    setLocationFallback(null)

    try {
      // Resolve the office first so an unselected office fails fast without
      // ever prompting for GPS permission.
      const office = attendanceData.offices.find(o => o.id.toString() === selectedOffice)
      if (!office) {
        setLocationError(
          attendanceData.office_mode === 'manual'
            ? 'No default office is assigned to you - please select your current office.'
            : 'Please select an office'
        )
        return
      }

      const body: Record<string, unknown> = {
        office_id: parseInt(selectedOffice),
        location_status: 'gps',
        latitude: coords.lat,
        longitude: coords.lng,
        accuracy: coords.accuracy,
      }

      const response = await api.post(
        action === 'clock-in' ? '/attendance/clock-in' : '/attendance/clock-out',
        body
      )

      if (response.data.success) {
        setActionMessage(
          response.data.message ||
            (action === 'clock-in' ? 'Clocked in successfully.' : 'Clocked out successfully.')
        )
        fetchAttendanceDashboard()
        fetchStats()
      }
    } catch (error) {
      const resp: any = error.response?.data

      if (resp?.code === 'OUTSIDE_RADIUS') {
        // Server-authoritative rejection - show exactly how far off they are.
        const measured = Number(resp.distance ?? 0)
        const allowed = Number(resp.allowed_radius ?? 0)
        setLocationError(
          `You are about ${formatDistance(measured)} from the office. You must be within ` +
            `${formatDistance(allowed)} to clock ${action === 'clock-in' ? 'in' : 'out'} - ` +
            'please move closer and try again.'
        )
      } else {
        setLocationError(getErrorMessage(error))
      }
    } finally {
      clockInInFlight.current = false
      clockOutInFlight.current = false
      setClockingIn(false)
      setClockingOut(false)
    }
  }

  /**
   * Entry point behind the Clock In / Clock Out buttons.
   * Tries to obtain a device fix; when that fails (typical on desktops),
   * surfaces a fallback panel instead of dead-ending with an error.
   */
  const startClock = async (action: 'clock-in' | 'clock-out') => {
    const inFlight = action === 'clock-in' ? clockInInFlight : clockOutInFlight
    const isBusy = action === 'clock-in' ? clockingIn : clockingOut
    if (inFlight.current || isBusy || locating) return

    setLocationError('')
    setActionMessage('')
    setLocationFallback(null)
    setLocating(action)

    try {
      const location = await requestLocation()
      if (!location.ok) {
        setLocationFallback({ action, code: location.code, message: location.message })
        return
      }

      // ---- Client-side geofence gate --------------------------------
      // Resolve the selected office and measure the distance BEFORE
      // submitting, so the employee immediately sees how far they are
      // and how much closer they need to be - without a server round-trip.
      const office = attendanceData.offices.find(o => o.id.toString() === selectedOffice)
      if (office && office.geo_fence_radius > 0) {
        const measured = haversineMeters(
          location.lat,
          location.lng,
          office.latitude,
          office.longitude
        )
        const allowed = office.geo_fence_radius

        if (measured > allowed) {
          setLocationFallback({
            action,
            code: 'OUTSIDE_RADIUS',
            message:
              `You are about ${formatDistance(measured)} from ${office.name}. You must be ` +
              `within ${formatDistance(allowed)} of the office to clock ` +
              `${action === 'clock-in' ? 'in' : 'out'}.`,
            officeName: office.name,
            requiredRadius: allowed,
            measuredDistance: measured,
          })
          return
        }
      }

      await submitClock(action, {
        lat: location.lat,
        lng: location.lng,
        accuracy: location.accuracy,
      })
    } finally {
      setLocating(null)
    }
  }

  const handleClockIn = () => startClock('clock-in')
  const handleClockOut = () => startClock('clock-out')

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

          {actionMessage && !locationError && (
            <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md">
              {actionMessage}
            </div>
          )}

          {locationFallback && !locationError && !locating && (
            <div className="bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 rounded-md">
              {locationFallback.code === 'OUTSIDE_RADIUS' ? (
                <>
                  <p className="text-sm font-medium">📍 You are too far from the office.</p>
                  <p className="text-sm mt-1">{locationFallback.message}</p>
                  <p className="text-xs mt-1">
                    Walk closer to <strong>{locationFallback.officeName}</strong> until you are
                    inside the {formatDistance(locationFallback.requiredRadius ?? 0)} zone, then
                    press <strong>Try Again</strong>.
                  </p>
                  <p className="text-xs mt-1 text-amber-700">
                    Measured distance: {formatDistance(locationFallback.measuredDistance ?? 0)} ·
                    Allowed: {formatDistance(locationFallback.requiredRadius ?? 0)}
                  </p>
                </>
              ) : (
                <>
                  <p className="text-sm font-medium">We tried hard, but could not get your location.</p>
                  <p className="text-xs mt-1">
                    {locationFallback.message} We attempted a GPS fix plus two network-based fixes over
                    roughly 35 seconds. This is common on desktop PCs without GPS - especially on
                    isolated office networks.
                  </p>
                  <p className="text-xs mt-1">
                    <strong>A location fix is required to clock in.</strong> Please move closer to the
                    office, turn on Wi-Fi to help network positioning, or step outside for a clear GPS
                    signal - then press <strong>Try Again</strong>.
                  </p>
                </>
              )}
              <div className="flex flex-wrap gap-2 mt-3">
                <Button size="sm" onClick={() => startClock(locationFallback.action)}>
                  Try Again
                </Button>
                <Button size="sm" variant="outline" onClick={() => setLocationFallback(null)}>
                  Cancel
                </Button>
              </div>
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

          {!attendanceData.is_clocked_in && attendanceData.today_record?.clock_out && (
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
              <p className="text-sm text-gray-700">
                <strong>Clocked in at:</strong>{' '}
                {new Date(attendanceData.today_record.clock_in).toLocaleTimeString()}
              </p>
              <p className="text-sm text-gray-700 mt-1">
                <strong>Clocked out at:</strong>{' '}
                {new Date(attendanceData.today_record.clock_out).toLocaleTimeString()}
              </p>
              <p className="text-sm text-gray-700 mt-1">
                <strong>Location:</strong> {attendanceData.today_record.office_name}
              </p>
              {!!attendanceData.today_record.auto_clocked_out && (
                <p className="text-xs text-gray-500 mt-1">
                  This record was closed automatically at midnight.
                </p>
              )}
            </div>
          )}

          {locating && (
            <p className="flex items-center text-xs text-blue-700 bg-blue-50 border border-blue-200 rounded-md px-3 py-2">
              <span className="inline-block animate-spin rounded-full h-3 w-3 border-b-2 border-blue-700 mr-2"></span>
              Getting your location - please allow the browser prompt if it appears. This can take
              up to about 35 seconds on desktops; we keep trying until we get a fix.
            </p>
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
              >
                {attendanceData.offices.map((office) => (
                  <option key={office.id} value={office.id}>
                    {office.name}
                  </option>
                ))}
              </select>
              <p className="mt-1 text-xs text-gray-500">
                {attendanceData.office_mode === 'manual'
                  ? 'No default office assigned to you - please select your current office.'
                  : `Default office: ${attendanceData.default_office?.name ?? ''} - you may clock in from any listed office.`}
              </p>
            </div>

            <div className="flex items-end">
              {attendanceData.is_clocked_in ? (
                <Button
                  onClick={handleClockOut}
                  disabled={clockingOut || locating !== null || !selectedOffice}
                  variant="danger"
                  className="w-full"
                >
                  {locating === 'clock-out' ? (
                    <>
                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                      Getting your location...
                    </>
                  ) : clockingOut ? (
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
                  disabled={
                    clockingIn ||
                    locating !== null ||
                    attendanceData.has_clocked_in_today ||
                    !selectedOffice
                  }
                  className="w-full"
                >
                  {locating === 'clock-in' ? (
                    <>
                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                      Getting your location...
                    </>
                  ) : clockingIn ? (
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
