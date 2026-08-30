import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../utils/api'
import { requestLocation } from '../../utils/geolocation'
import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import { Users, CalendarCheck, Calendar, TrendingUp, Clock, FileText, Star, Bell } from 'lucide-react'
import {
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
  Tooltip,
  Legend,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  LabelList,
  RadialBarChart,
  RadialBar,
} from 'recharts'
import { useTheme } from '../../context/ThemeContext'

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
  const { theme } = useTheme()
  const isDark = theme === 'dark'
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
      const resp: any = (error as any)?.response?.data

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

  /**
   * Shared presentation tokens so every chart follows light/dark mode.
   */
  const tooltipStyle = {
    backgroundColor: isDark ? '#1e293b' : '#ffffff',
    border: `1px solid ${isDark ? '#334155' : '#e5e7eb'}`,
    borderRadius: 8,
    fontSize: 12,
    boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.15)',
  }
  const chartFg = isDark ? '#f1f5f9' : '#111827'
  const tickColor = isDark ? '#94a3b8' : '#4b5563'
  const gridColor = isDark ? '#334155' : '#e5e7eb'
  const labelColor = isDark ? '#cbd5e1' : '#374151'
  const legendColor = isDark ? '#cbd5e1' : '#374151'
  const hoverFill = isDark ? 'rgba(148, 163, 184, 0.08)' : 'rgba(59, 130, 246, 0.06)'
  const radialTrackFill = isDark ? '#1e293b' : '#eef2f7'

  /** Department headcounts, largest first - chart shows the top six. */
  const deptRows: Array<{ name: string; employees: number }> =
    (((analytics.departments?.departments ?? []) as Array<any>) || [])
      .map((d) => ({ name: String(d?.department ?? 'Unassigned'), employees: Number(d?.count) || 0 }))
      .filter((d) => d.employees > 0)
      .sort((a, b) => b.employees - a.employees)
      .slice(0, 6)

  const leaveRows = [
    { name: 'On Leave', value: Number(analytics.leave?.on_leave || 0) },
    { name: 'Pending', value: Number(analytics.leave?.pending || 0) },
  ]

  const presentToday = Number(analytics.attendance?.present || 0)
  const lateToday = Number(analytics.attendance?.late || 0)
  const absentToday = Number(analytics.attendance?.absent || 0)
  const onLeaveToday = Number(analytics.leave?.on_leave || 0)
  /** Active employees neither present nor on leave today. */
  const elsewhereToday = Math.max(0, stats.totalEmployees - presentToday - onLeaveToday)
  const atWorkPct = stats.totalEmployees
    ? Math.round((presentToday / stats.totalEmployees) * 100)
    : 0
  /** Present excluding late arrivals, so donut segments never overlap. */
  const onTimeToday = Math.max(0, presentToday - lateToday)

  /** Graceful empty state shared by all four charts. */
  const EmptyChart = ({ label }: { label: string }) => (
    <div className="h-64 flex items-center justify-center text-sm text-gray-500 dark:text-gray-400">{label}</div>
  )

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
          <span className="text-sm font-medium text-green-700 dark:text-green-400">Clocked In</span>
        </div>
      )
    } else if (attendanceData.has_clocked_in_today) {
      return (
        <div className="flex items-center space-x-2">
          <div className="h-3 w-3 bg-gray-400 rounded-full"></div>
          <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Clocked Out</span>
        </div>
      )
    }
    return (
      <div className="flex items-center space-x-2">
        <div className="h-3 w-3 bg-red-500 rounded-full"></div>
        <span className="text-sm font-medium text-red-700 dark:text-red-400">Not Clocked In</span>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Dashboard</h1>
        <p className="text-gray-500 dark:text-gray-400">Welcome to MUWASCO HR Management System</p>
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
                <p className="text-sm text-gray-500 dark:text-gray-400">{card.title}</p>
                <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{card.value}</p>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {/* Clock In/Clock Out Card */}
      <Card>
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Attendance</h3>
            {getStatusBadge()}
          </div>

          {locationError && (
            <div className="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-md">
              {locationError}
            </div>
          )}

          {actionMessage && !locationError && (
            <div className="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-300 px-4 py-3 rounded-md">
              {actionMessage}
            </div>
          )}

          {locationFallback && !locationError && !locating && (
            <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 text-amber-900 dark:text-amber-200 px-4 py-3 rounded-md">
              {locationFallback.code === 'OUTSIDE_RADIUS' ? (
                <>
                  <p className="text-sm font-medium">📍 You are too far from the office.</p>
                  <p className="text-sm mt-1">{locationFallback.message}</p>
                  <p className="text-xs mt-1">
                    Walk closer to <strong>{locationFallback.officeName}</strong> until you are
                    inside the {formatDistance(locationFallback.requiredRadius ?? 0)} zone, then
                    press <strong>Try Again</strong>.
                  </p>
                  <p className="text-xs mt-1 text-amber-700 dark:text-amber-300">
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
            <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
              <p className="text-sm text-blue-700 dark:text-blue-300">
                <strong>Clocked in at:</strong> {new Date(attendanceData.current_session.clock_in).toLocaleTimeString()}
              </p>
              <p className="text-sm text-blue-700 dark:text-blue-300 mt-1">
                <strong>Location:</strong> {attendanceData.current_session.office_name}
              </p>
              {attendanceData.current_session.is_late && (
                <p className="text-sm text-yellow-700 dark:text-yellow-300 mt-1">
                  <strong>Status:</strong> Late Arrival
                </p>
              )}
            </div>
          )}

          {!attendanceData.is_clocked_in && attendanceData.today_record?.clock_out && (
            <div className="bg-gray-50 dark:bg-slate-900/40 border border-gray-200 dark:border-slate-600 rounded-lg p-4">
              <p className="text-sm text-gray-700 dark:text-gray-300">
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
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  This record was closed automatically at midnight.
                </p>
              )}
            </div>
          )}

          {locating && (
            <p className="flex items-center text-xs text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-md px-3 py-2">
              <span className="inline-block animate-spin rounded-full h-3 w-3 border-b-2 border-blue-700 mr-2"></span>
              Getting your location - please allow the browser prompt if it appears. This can take
              up to about 35 seconds on desktops; we keep trying until we get a fix.
            </p>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                {attendanceData.is_clocked_in ? 'Clock Out At:' : 'Clock In At:'}
              </label>
              <select
                value={selectedOffice}
                onChange={(e) => setSelectedOffice(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md shadow-sm bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
              >
                {attendanceData.offices.map((office) => (
                  <option key={office.id} value={office.id}>
                    {office.name}
                  </option>
                ))}
              </select>
              <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
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
              <div className="p-3 bg-blue-100 dark:bg-blue-900/40 rounded-lg">
                <FileText className="h-6 w-6 text-blue-600 dark:text-blue-300" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Apply Leave</h3>
                <p className="text-sm text-gray-500 dark:text-gray-400">Submit leave application</p>
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
              <div className="p-3 bg-purple-100 dark:bg-purple-900/40 rounded-lg">
                <Star className="h-6 w-6 text-purple-600 dark:text-purple-300" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">My Appraisal</h3>
                <p className="text-sm text-gray-500 dark:text-gray-400">View performance reviews</p>
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
            <Bell className="h-5 w-5 text-gray-600 dark:text-gray-400" />
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Notifications</h3>
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
                  notification.is_read ? 'bg-gray-50 dark:bg-slate-900/40 border-gray-200 dark:border-slate-700' : 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800'
                }`}
              >
                <div className="flex items-start justify-between">
                  <div className="flex-1">
                    <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100">
                      {notification.title}
                    </h4>
                    <p className="text-sm text-gray-600 dark:text-gray-300 mt-1">
                      {notification.message}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">
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
            <p className="text-gray-500 dark:text-gray-400 text-center py-8">No notifications</p>
          )}
        </div>
      </Card>

      {/* Analytics Graphs - live Recharts visualisations fed by the
           /dashboard/charts/* endpoints. Charts always render; each card
           degrades to its own empty state when data has not loaded. */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Attendance: donut split of on-time / late / absent today. */}
        <Card title="Attendance Analytics"
          subtitle={`${atWorkPct}% of ${stats.totalEmployees.toLocaleString()} active employees at work`} >
          {analytics.attendance ? (
            <>
              <div className="relative h-56">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie
                      data={[
                        { name: 'On Time', value: onTimeToday },
                        { name: 'Late', value: lateToday },
                        { name: 'Absent', value: absentToday },
                      ]}
                      dataKey="value"
                      nameKey="name"
                      innerRadius="60%"
                      outerRadius="82%"
                      paddingAngle={2}
                      stroke={isDark ? '#0f172a' : '#ffffff'}
                      strokeWidth={2}
                    >
                      <Cell fill="#22c55e" />
                      <Cell fill="#f59e0b" />
                      <Cell fill="#ef4444" />
                    </Pie>
                    <Tooltip
                      formatter={(value: any, name: any) => [`${value} employees`, name]}
                      contentStyle={tooltipStyle}
                      itemStyle={{ color: chartFg }}
                    />
                    <Legend verticalAlign="bottom" height={24}
                      wrapperStyle={{ fontSize: 11, color: legendColor }} />
                  </PieChart>
                </ResponsiveContainer>
                <div className="absolute inset-x-0 top-0 flex h-full flex-col items-center justify-center pb-7 pointer-events-none">
                  <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{atWorkPct}%</p>
                  <p className="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">at work</p>
                </div>
              </div>
              <p className="mt-2 text-xs text-center text-gray-500 dark:text-gray-400">
                On time {onTimeToday} &middot; Late {lateToday} &middot; Absent {absentToday}
              </p>
            </>
          ) : (
            <EmptyChart label="No attendance data yet." />
          )}
        </Card>


        {/* Leave: approved-vs-pending application volumes. */}
        <Card title="Leave Statistics"
          subtitle={`${onLeaveToday} away right now · ${leaveRows[1].value} awaiting approval`} >
          {analytics.leave ? (
            <div className="h-56">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={leaveRows} margin={{ top: 20, right: 16, left: -12, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke={gridColor} vertical={false} />
                  <XAxis dataKey="name" tick={{ fill: tickColor, fontSize: 12 }}
                    axisLine={{ stroke: gridColor }} tickLine={false} />
                  <YAxis allowDecimals={false} tick={{ fill: tickColor, fontSize: 11 }}
                    axisLine={false} tickLine={false} />
                  <Tooltip cursor={{ fill: hoverFill }}
                    formatter={(value: any) => [`${value} employees`, 'Count']}
                    contentStyle={tooltipStyle} itemStyle={{ color: chartFg }}
                    labelStyle={{ color: chartFg }} />
                  <Bar dataKey="value" radius={[6, 6, 0, 0]} maxBarSize={72}>
                    <LabelList dataKey="value" position="top" fontSize={13}
                      fontWeight={600} fill={labelColor} />
                    <Cell fill="#3b82f6" />
                    <Cell fill="#a855f7" />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>
          ) : (
            <EmptyChart label="No leave data yet." />
          )}
        </Card>

        {/* Departments: horizontal headcount ranking (top six shown). */}
        <Card title="Department Distribution"
          subtitle={`Top ${deptRows.length} of ${analytics.departments?.total_departments ?? deptRows.length} departments by headcount`} >
          {deptRows.length > 0 ? (
            <div className="h-72">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart layout="vertical" data={deptRows}
                  margin={{ top: 4, right: 44, left: 8, bottom: 4 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke={gridColor} horizontal={false} />
                  <XAxis type="number" allowDecimals={false}
                    tick={{ fill: tickColor, fontSize: 11 }} axisLine={false} tickLine={false} />
                  <YAxis type="category" dataKey="name" width={130}
                    tickFormatter={(v: string) => (v.length > 16 ? `${v.slice(0, 15)}…` : v || 'Unassigned')}
                    tick={{ fill: tickColor, fontSize: 11 }} axisLine={false} tickLine={false} />
                  <Tooltip cursor={{ fill: hoverFill }}
                    formatter={(value: any) => [`${value} employees`, 'Headcount']}
                    contentStyle={tooltipStyle} itemStyle={{ color: chartFg }}
                    labelStyle={{ color: chartFg }} />
                  <Bar dataKey="employees" name="Employees" fill="#3b82f6"
                    radius={[0, 6, 6, 0]} maxBarSize={18}>
                    <LabelList dataKey="employees" position="right" fontSize={11}
                      fill={labelColor} />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>
          ) : (
            <EmptyChart label="No department data yet." />
          )}
        </Card>

        {/* Workforce gauge: share of the active roster present right now. */}
        <Card title="Employee Statistics"
          subtitle="Active roster versus actual presence today" >
          <div className="relative h-56">
            <ResponsiveContainer width="100%" height="100%">
              <RadialBarChart
                data={[{ name: 'At work', value: Math.min(100, atWorkPct) }]}
                innerRadius="68%" outerRadius="106%"
                startAngle={90} endAngle={-270}>
                <RadialBar background={{ fill: radialTrackFill }} dataKey="value"
                  cornerRadius={14} fill="#22c55e" />
              </RadialBarChart>
            </ResponsiveContainer>
            <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
              <p className="text-3xl font-bold text-gray-900 dark:text-gray-100">
                {stats.totalEmployees.toLocaleString()}
              </p>
              <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                active employees
              </p>
              <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-1">
                {presentToday} present · {onLeaveToday} on leave · {elsewhereToday} elsewhere
              </p>
            </div>
          </div>
        </Card>
      </div>
    </div>
  )
}

export default Dashboard
