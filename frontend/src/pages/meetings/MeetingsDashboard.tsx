import { useState, useEffect, useMemo, useCallback, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../utils/api'
import Card from '../../components/ui/Card'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import { downloadCsv, toCsv, csvFilenameWithDate } from '../../utils/csvUtils'
import {
  CalendarDays,
  Plus,
  Calendar,
  Clock,
  MapPin,
  CalendarCheck,
  CheckCircle2,
  XCircle,
  PlayCircle,
  Hourglass,
  Download,
  Search,
  X,
  ChevronUp,
  ChevronDown,
  ChevronsUpDown,
  Eye,
  Pencil,
  Trash2,
  Ban,
  RefreshCw,
  CalendarX2,
} from 'lucide-react'

interface Meeting {
  id: number
  title: string
  description: string
  agenda: string
  meeting_date: string
  start_time: string
  end_time: string
  location: string
  status: string
  created_by: number
  org_first_name: string
  org_last_name: string
  total_invited?: number
  confirmed_count?: number
  pending_count?: number
}

interface Stats {
  total_meetings: number
  status_counts: { scheduled: number; ongoing: number; completed: number; cancelled: number }
  pending_confirmations: number
  total_invited: number
}

type SortKey = 'title' | 'meeting_date' | 'start_time' | 'location' | 'status' | 'organizer'
type SortDirection = 'asc' | 'desc'

interface SortConfig {
  key: SortKey
  direction: SortDirection
}

type DateFilterValue = 'all' | 'today' | 'tomorrow' | 'this_week' | 'next_week' | 'this_month' | 'custom'

interface Filters {
  status: string
  dateFilter: DateFilterValue
  dateFrom: string
  dateTo: string
  organizer: string
  location: string
  search: string
}

const EMPTY_FILTERS: Filters = {
  status: '',
  dateFilter: 'all',
  dateFrom: '',
  dateTo: '',
  organizer: '',
  location: '',
  search: '',
}

const PAGE_SIZES = [10, 25, 50, 100]

const STATUS_OPTIONS = [
  { value: '', label: 'All Statuses' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'ongoing', label: 'Ongoing' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

const DATE_FILTER_OPTIONS = [
  { value: 'all', label: 'All Dates' },
  { value: 'today', label: 'Today' },
  { value: 'tomorrow', label: 'Tomorrow' },
  { value: 'this_week', label: 'This Week' },
  { value: 'next_week', label: 'Next Week' },
  { value: 'this_month', label: 'This Month' },
  { value: 'custom', label: 'Custom Date Range' },
]

const MeetingsDashboard = () => {
  const navigate = useNavigate()
  const [meetings, setMeetings] = useState<Meeting[]>([])
  const [stats, setStats] = useState<Stats | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS)
  const [sortConfig, setSortConfig] = useState<SortConfig>({ key: 'meeting_date', direction: 'desc' })
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [exporting, setExporting] = useState(false)
  const [actionLoading, setActionLoading] = useState<number | null>(null)

  const loadDashboard = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      // Fetch all meetings (no pagination) for client-side filtering/sorting/export
      const [meetingsRes, statsRes] = await Promise.all([
        api.get('/meetings?per_page=100000'),
        api.get('/meetings/stats'),
      ])
      setMeetings(meetingsRes.data?.data || [])
      setStats(statsRes.data?.data || null)
    } catch (err: any) {
      const msg = err.response?.data?.message || err.response?.data?.error || 'Failed to load meetings'
      setError(msg)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadDashboard()
  }, [loadDashboard])

  // ---------- Date helpers ----------
  const toDateStr = (d: Date) => {
    const year = d.getFullYear()
    const month = String(d.getMonth() + 1).padStart(2, '0')
    const day = String(d.getDate()).padStart(2, '0')
    return `${year}-${month}-${day}`
  }

  const getDateRange = (filter: DateFilterValue): { from: string; to: string } | null => {
    const today = new Date()
    const todayStr = toDateStr(today)

    switch (filter) {
      case 'today':
        return { from: todayStr, to: todayStr }
      case 'tomorrow': {
        const tomorrow = new Date(today)
        tomorrow.setDate(tomorrow.getDate() + 1)
        const tomorrowStr = toDateStr(tomorrow)
        return { from: tomorrowStr, to: tomorrowStr }
      }
      case 'this_week': {
        const day = today.getDay() // 0=Sun
        const diffToMonday = (day + 6) % 7
        const monday = new Date(today)
        monday.setDate(today.getDate() - diffToMonday)
        const sunday = new Date(monday)
        sunday.setDate(monday.getDate() + 6)
        return { from: toDateStr(monday), to: toDateStr(sunday) }
      }
      case 'next_week': {
        const day = today.getDay()
        const diffToMonday = (day + 6) % 7
        const nextMonday = new Date(today)
        nextMonday.setDate(today.getDate() - diffToMonday + 7)
        const nextSunday = new Date(nextMonday)
        nextSunday.setDate(nextMonday.getDate() + 6)
        return { from: toDateStr(nextMonday), to: toDateStr(nextSunday) }
      }
      case 'this_month': {
        const first = new Date(today.getFullYear(), today.getMonth(), 1)
        const last = new Date(today.getFullYear(), today.getMonth() + 1, 0)
        return { from: toDateStr(first), to: toDateStr(last) }
      }
      case 'custom':
        if (filters.dateFrom && filters.dateTo) {
          return { from: filters.dateFrom, to: filters.dateTo }
        }
        return null
      default:
        return null
    }
  }

  // ---------- Formatting helpers ----------
  const formatDate = (dateStr: string) => {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    return d.toLocaleDateString('en-GB', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' })
  }

  const formatTime = (timeStr: string) => {
    if (!timeStr) return ''
    const [hours, minutes] = timeStr.split(':')
    const h = parseInt(hours, 10)
    const ampm = h >= 12 ? 'PM' : 'AM'
    const h12 = h % 12 || 12
    return `${h12}:${minutes} ${ampm}`
  }

  const getOrganizerName = (m: Meeting) => {
    return [m.org_first_name, m.org_last_name].filter(Boolean).join(' ') || 'Unknown'
  }

  const getDuration = (m: Meeting) => {
    if (!m.start_time || !m.end_time) return ''
    const [sh, sm] = m.start_time.split(':').map(Number)
    const [eh, em] = m.end_time.split(':').map(Number)
    let startMin = sh * 60 + sm
    let endMin = eh * 60 + em
    // Handle overnight meetings (end time is on the next day)
    if (endMin <= startMin) {
      endMin += 24 * 60
    }
    const diffMin = endMin - startMin
    const hours = Math.floor(diffMin / 60)
    const mins = diffMin % 60
    return `${hours}h ${String(mins).padStart(2, '0')}m`
  }

  const getStatusBadge = (status: string) => {
    const variants: Record<string, 'default' | 'primary' | 'success' | 'warning' | 'danger'> = {
      scheduled: 'primary',
      ongoing: 'warning',
      completed: 'success',
      cancelled: 'danger',
    }
    return <Badge variant={variants[status] || 'default'}>{status.charAt(0).toUpperCase() + status.slice(1)}</Badge>
  }

  // ---------- Filtering ----------
  const filteredMeetings = useMemo(() => {
    let result = [...meetings]

    // Search (case-insensitive across title, organizer, location, id)
    if (filters.search.trim()) {
      const q = filters.search.trim().toLowerCase()
      result = result.filter((m) => {
        const organizer = getOrganizerName(m).toLowerCase()
        const title = (m.title || '').toLowerCase()
        const location = (m.location || '').toLowerCase()
        const idStr = String(m.id)
        return title.includes(q) || organizer.includes(q) || location.includes(q) || idStr.includes(q)
      })
    }

    // Status filter
    if (filters.status) {
      result = result.filter((m) => m.status === filters.status)
    }

    // Date filter
    const dateRange = getDateRange(filters.dateFilter)
    if (dateRange) {
      result = result.filter((m) => {
        if (!m.meeting_date) return false
        return m.meeting_date >= dateRange.from && m.meeting_date <= dateRange.to
      })
    }

    // Organizer filter
    if (filters.organizer) {
      result = result.filter((m) => {
        const organizer = getOrganizerName(m).toLowerCase()
        return organizer.includes(filters.organizer.toLowerCase())
      })
    }

    // Location filter
    if (filters.location) {
      result = result.filter((m) => (m.location || '').toLowerCase() === filters.location.toLowerCase())
    }

    return result
  }, [meetings, filters])

  // ---------- Sorting ----------
  const sortedMeetings = useMemo(() => {
    const sorted = [...filteredMeetings]
    const { key, direction } = sortConfig
    const dir = direction === 'asc' ? 1 : -1

    sorted.sort((a, b) => {
      let valA: string | number = ''
      let valB: string | number = ''

      switch (key) {
        case 'title':
          valA = (a.title || '').toLowerCase()
          valB = (b.title || '').toLowerCase()
          break
        case 'meeting_date':
          valA = a.meeting_date || ''
          valB = b.meeting_date || ''
          break
        case 'start_time':
          valA = a.start_time || ''
          valB = b.start_time || ''
          break
        case 'location':
          valA = (a.location || '').toLowerCase()
          valB = (b.location || '').toLowerCase()
          break
        case 'status':
          valA = a.status || ''
          valB = b.status || ''
          break
        case 'organizer':
          valA = getOrganizerName(a).toLowerCase()
          valB = getOrganizerName(b).toLowerCase()
          break
      }

      if (valA < valB) return -1 * dir
      if (valA > valB) return 1 * dir
      return 0
    })

    return sorted
  }, [filteredMeetings, sortConfig])

  // ---------- Pagination (visual only) ----------
  const totalPages = Math.max(1, Math.ceil(sortedMeetings.length / perPage))
  const currentPage = Math.min(page, totalPages)
  const paginatedMeetings = useMemo(() => {
    const start = (currentPage - 1) * perPage
    return sortedMeetings.slice(start, start + perPage)
  }, [sortedMeetings, currentPage, perPage])

  // Reset page when filters/sort change
  useEffect(() => {
    setPage(1)
  }, [filters, sortConfig, perPage])

  // ---------- Filter helpers ----------
  const handleFilterChange = (field: keyof Filters, value: string) => {
    setFilters((prev) => ({ ...prev, [field]: value }))
  }

  const clearFilters = () => {
    setFilters(EMPTY_FILTERS)
  }

  const hasActiveFilters = () => {
    const hasCustomDateRange = filters.dateFilter === 'custom' && (filters.dateFrom !== '' || filters.dateTo !== '')
    return (
      filters.status !== '' ||
      filters.dateFilter !== 'all' ||
      filters.organizer !== '' ||
      filters.location !== '' ||
      filters.search.trim() !== '' ||
      hasCustomDateRange
    )
  }

  const applyStatusFilter = (status: string) => {
    setFilters((prev) => ({ ...prev, status }))
  }

  // ---------- Sorting helpers ----------
  const handleSort = (key: SortKey) => {
    setSortConfig((prev) => {
      if (prev.key === key) {
        return { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' }
      }
      return { key, direction: 'asc' }
    })
  }

  const SortIcon = ({ columnKey }: { columnKey: SortKey }) => {
    if (sortConfig.key !== columnKey) {
      return <ChevronsUpDown className="h-3 w-3 inline-block ml-1 text-gray-400" />
    }
    return sortConfig.direction === 'asc' ? (
      <ChevronUp className="h-3 w-3 inline-block ml-1 text-primary-600" />
    ) : (
      <ChevronDown className="h-3 w-3 inline-block ml-1 text-primary-600" />
    )
  }

  const SortableHeader = ({ columnKey, children }: { columnKey: SortKey; children: ReactNode }) => (
    <th
      className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider cursor-pointer select-none hover:text-gray-700 dark:hover:text-gray-200"
      onClick={() => handleSort(columnKey)}
    >
      <span className="inline-flex items-center">
        {children}
        <SortIcon columnKey={columnKey} />
      </span>
    </th>
  )

  // ---------- Organizer / Location options ----------
  const organizerOptions = useMemo(() => {
    const unique = new Set<string>()
    meetings.forEach((m) => {
      const name = getOrganizerName(m)
      if (name && name !== 'Unknown') unique.add(name)
    })
    return Array.from(unique).sort().map((name) => ({ value: name, label: name }))
  }, [meetings])

  const locationOptions = useMemo(() => {
    const unique = new Set<string>()
    meetings.forEach((m) => {
      if (m.location) unique.add(m.location)
    })
    return Array.from(unique).sort().map((loc) => ({ value: loc, label: loc }))
  }, [meetings])

  // ---------- CSV Export ----------
  const handleExport = () => {
    if (sortedMeetings.length === 0) {
      setError('No meetings to export. Adjust your filters or create a meeting first.')
      return
    }

    setExporting(true)
    setError('')
    try {
      const headers = [
        'Meeting',
        'Date',
        'Start Time',
        'End Time',
        'Duration',
        'Location',
        'Organizer',
        'Invited',
        'Confirmed',
        'Pending',
        'Status',
      ]

      const rows = sortedMeetings.map((m) => ({
        Meeting: m.title || '',
        Date: m.meeting_date || '',
        'Start Time': m.start_time || '',
        'End Time': m.end_time || '',
        Duration: getDuration(m),
        Location: m.location || '',
        Organizer: getOrganizerName(m),
        Invited: m.total_invited ?? 0,
        Confirmed: m.confirmed_count ?? 0,
        Pending: m.pending_count ?? 0,
        Status: m.status ? m.status.charAt(0).toUpperCase() + m.status.slice(1) : '',
      }))

      const csv = toCsv(headers, rows)
      const filename = csvFilenameWithDate('meetings')
      downloadCsv(csv, filename)
    } catch (err: any) {
      setError('Failed to export meetings. Please try again.')
    } finally {
      setExporting(false)
    }
  }

  // ---------- Actions ----------
  const handleCancelMeeting = async (meetingId: number) => {
    if (!window.confirm('Are you sure you want to cancel this meeting?')) return
    setActionLoading(meetingId)
    try {
      await api.post(`/meetings/${meetingId}/cancel`)
      setMeetings((prev) => prev.map((m) => (m.id === meetingId ? { ...m, status: 'cancelled' } : m)))
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to cancel meeting'
      setError(msg)
    } finally {
      setActionLoading(null)
    }
  }

  const handleDeleteMeeting = async (meetingId: number) => {
    if (!window.confirm('Are you sure you want to delete this meeting? This action cannot be undone.')) return
    setActionLoading(meetingId)
    try {
      await api.delete(`/meetings/${meetingId}`)
      setMeetings((prev) => prev.filter((m) => m.id !== meetingId))
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to delete meeting'
      setError(msg)
    } finally {
      setActionLoading(null)
    }
  }

  // ---------- KPI Cards ----------
  const statusCounts = stats?.status_counts || { scheduled: 0, ongoing: 0, completed: 0, cancelled: 0 }

  const kpiCards = [
    {
      label: 'Total Meetings',
      value: stats?.total_meetings ?? 0,
      icon: <CalendarDays className="h-5 w-5" />,
      color: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300',
      onClick: () => applyStatusFilter(''),
    },
    {
      label: 'Scheduled',
      value: statusCounts.scheduled,
      icon: <Calendar className="h-5 w-5" />,
      color: 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
      onClick: () => applyStatusFilter('scheduled'),
    },
    {
      label: 'Ongoing',
      value: statusCounts.ongoing,
      icon: <PlayCircle className="h-5 w-5" />,
      color: 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
      onClick: () => applyStatusFilter('ongoing'),
    },
    {
      label: 'Completed',
      value: statusCounts.completed,
      icon: <CheckCircle2 className="h-5 w-5" />,
      color: 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
      onClick: () => applyStatusFilter('completed'),
    },
    {
      label: 'Cancelled',
      value: statusCounts.cancelled,
      icon: <XCircle className="h-5 w-5" />,
      color: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
      onClick: () => applyStatusFilter('cancelled'),
    },
    {
      label: 'Pending Confirmations',
      value: stats?.pending_confirmations ?? 0,
      icon: <Hourglass className="h-5 w-5" />,
      color: 'bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400',
    },
  ]

  // ---------- Loading state ----------
  if (loading) {
    return (
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Meetings Dashboard</h1>
            <p className="text-gray-500 dark:text-gray-400">Loading meeting data...</p>
          </div>
        </div>
        {/* Skeleton cards */}
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="bg-white dark:bg-slate-800 rounded-xl border border-primary-600 dark:border-slate-700 shadow-md p-6 animate-pulse">
              <div className="h-10 w-10 rounded-lg bg-gray-200 dark:bg-slate-700 mb-3"></div>
              <div className="h-6 w-12 bg-gray-200 dark:bg-slate-700 rounded mb-2"></div>
              <div className="h-3 w-20 bg-gray-200 dark:bg-slate-700 rounded"></div>
            </div>
          ))}
        </div>
        {/* Skeleton table */}
        <Card>
          <div className="animate-pulse">
            <div className="h-8 bg-gray-200 dark:bg-slate-700 rounded mb-4 w-1/3"></div>
            <div className="space-y-3">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="h-12 bg-gray-100 dark:bg-slate-700/50 rounded"></div>
              ))}
            </div>
          </div>
        </Card>
      </div>
    )
  }

  // ---------- Error state ----------
  if (error && meetings.length === 0) {
    return (
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Meetings Dashboard</h1>
            <p className="text-gray-500 dark:text-gray-400">Overview of all scheduled meetings and attendance</p>
          </div>
        </div>
        <Card>
          <div className="text-center py-12">
            <CalendarX2 className="h-12 w-12 mx-auto text-red-400 mb-4" />
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Unable to load meetings</h3>
            <p className="text-gray-500 dark:text-gray-400 mt-2">{error}</p>
            <div className="mt-6">
              <Button variant="outline" onClick={loadDashboard}>
                <RefreshCw className="h-4 w-4 mr-1" />
                Retry
              </Button>
            </div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Meetings Dashboard</h1>
          <p className="text-gray-500 dark:text-gray-400">Overview of all scheduled meetings and attendance</p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button variant="outline" size="sm" onClick={() => navigate('/my-meetings')}>
            <CalendarCheck className="h-4 w-4 mr-1" />
            My Meetings
          </Button>
          <Button variant="outline" size="sm" onClick={handleExport} loading={exporting} disabled={sortedMeetings.length === 0}>
            <Download className="h-4 w-4 mr-1" />
            Export CSV
          </Button>
          <Button size="sm" onClick={() => navigate('/meetings/create')}>
            <Plus className="h-4 w-4 mr-1" />
            Create Meeting
          </Button>
        </div>
      </div>

      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
          {error}
        </div>
      )}

      {/* KPI Cards */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        {kpiCards.map((card) => (
          <div
            key={card.label}
            className={card.onClick ? 'cursor-pointer transition-transform hover:scale-[1.02]' : ''}
            onClick={card.onClick}
          >
            <Card>
              <div className="flex items-center space-x-3">
                <div className={`h-10 w-10 rounded-lg flex items-center justify-center ${card.color}`}>{card.icon}</div>
                <div>
                  <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">{card.value}</div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">{card.label}</p>
                </div>
              </div>
            </Card>
          </div>
        ))}
      </div>

      {/* Meetings Table Section */}
      <Card
        title="Meetings"
        subtitle="View, search, filter, manage and export all meetings."
      >
        {/* Toolbar */}
        <div className="space-y-4">
          {/* Search + Export */}
          <div className="flex flex-col md:flex-row md:items-center gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
              <Input
                value={filters.search}
                onChange={(e) => handleFilterChange('search', e.target.value)}
                placeholder="Search by title, organizer, location, or ID..."
                className="pl-9"
              />
            </div>
            <Button variant="outline" size="sm" onClick={handleExport} loading={exporting} disabled={sortedMeetings.length === 0}>
              <Download className="h-4 w-4 mr-1" />
              Export CSV
            </Button>
          </div>

          {/* Filters */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <Select
              label="Status"
              value={filters.status}
              onChange={(e) => handleFilterChange('status', e.target.value)}
              options={STATUS_OPTIONS}
            />
            <Select
              label="Date"
              value={filters.dateFilter}
              onChange={(e) => handleFilterChange('dateFilter', e.target.value as DateFilterValue)}
              options={DATE_FILTER_OPTIONS}
            />
            {filters.dateFilter === 'custom' && (
              <>
                <Input
                  label="From"
                  type="date"
                  value={filters.dateFrom}
                  onChange={(e) => handleFilterChange('dateFrom', e.target.value)}
                />
                <Input
                  label="To"
                  type="date"
                  value={filters.dateTo}
                  onChange={(e) => handleFilterChange('dateTo', e.target.value)}
                />
              </>
            )}
            <Select
              label="Organizer"
              value={filters.organizer}
              onChange={(e) => handleFilterChange('organizer', e.target.value)}
              options={organizerOptions}
            />
            <Select
              label="Location"
              value={filters.location}
              onChange={(e) => handleFilterChange('location', e.target.value)}
              options={locationOptions}
            />
          </div>

          {/* Active filter indicator + reset */}
          {hasActiveFilters() && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-gray-500 dark:text-gray-400">
                Showing {sortedMeetings.length} of {meetings.length} meetings
              </span>
              <Button variant="outline" size="sm" onClick={clearFilters}>
                <X className="h-4 w-4 mr-1" />
                Reset Filters
              </Button>
            </div>
          )}
        </div>

        {/* Table */}
        {sortedMeetings.length === 0 ? (
          <div className="text-center py-12">
            <CalendarX2 className="h-12 w-12 mx-auto text-gray-300 dark:text-gray-600 mb-4" />
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">No meetings found</h3>
            <p className="text-gray-500 dark:text-gray-400 mt-2">
              {meetings.length === 0
                ? 'There are no meetings in the system yet. Create your first meeting to get started.'
                : 'Try adjusting your search or filters.'}
            </p>
            {hasActiveFilters() && (
              <div className="mt-6">
                <Button variant="outline" onClick={clearFilters}>
                  <X className="h-4 w-4 mr-1" />
                  Clear Filters
                </Button>
              </div>
            )}
          </div>
        ) : (
          <>
            <div className="overflow-x-auto -mx-6 px-6">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                <thead className="bg-gray-50 dark:bg-slate-900">
                  <tr>
                    <SortableHeader columnKey="title">Meeting</SortableHeader>
                    <SortableHeader columnKey="meeting_date">Date</SortableHeader>
                    <SortableHeader columnKey="start_time">Time</SortableHeader>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Duration
                    </th>
                    <SortableHeader columnKey="location">Location</SortableHeader>
                    <SortableHeader columnKey="organizer">Organizer</SortableHeader>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Invited
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Confirmed
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Pending
                    </th>
                    <SortableHeader columnKey="status">Status</SortableHeader>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                  {paginatedMeetings.map((m) => (
                    <tr key={m.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                      <td className="px-4 py-4 text-sm">
                        <div className="font-medium text-gray-900 dark:text-gray-100">{m.title}</div>
                        <div className="text-xs text-gray-500 dark:text-gray-400">#{m.id}</div>
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap">
                        <div className="flex items-center">
                          <Calendar className="h-4 w-4 mr-1 text-gray-400" />
                          {formatDate(m.meeting_date)}
                        </div>
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap">
                        <div className="flex items-center">
                          <Clock className="h-4 w-4 mr-1 text-gray-400" />
                          {formatTime(m.start_time)} - {formatTime(m.end_time)}
                        </div>
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                        {getDuration(m)}
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-900 dark:text-gray-100">
                        <div className="flex items-center">
                          <MapPin className="h-4 w-4 mr-1 text-gray-400 flex-shrink-0" />
                          {m.location || '-'}
                        </div>
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap">
                        {getOrganizerName(m)}
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-900 dark:text-gray-100 text-center">
                        {m.total_invited ?? 0}
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-900 dark:text-gray-100 text-center">
                        {m.confirmed_count ?? 0}
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-900 dark:text-gray-100 text-center">
                        {m.pending_count ?? 0}
                      </td>
                      <td className="px-4 py-4 text-sm whitespace-nowrap">{getStatusBadge(m.status)}</td>
                      <td className="px-4 py-4 text-sm whitespace-nowrap">
                        <div className="flex items-center space-x-1">
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => navigate(`/meetings/${m.id}/details`)}
                            title="View details"
                          >
                            <Eye className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => navigate(`/meetings/${m.id}/edit`)}
                            title="Edit meeting"
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          {m.status !== 'cancelled' && m.status !== 'completed' && (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleCancelMeeting(m.id)}
                              loading={actionLoading === m.id}
                              title="Cancel meeting"
                            >
                              <Ban className="h-3.5 w-3.5 text-amber-600" />
                            </Button>
                          )}
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => handleDeleteMeeting(m.id)}
                            loading={actionLoading === m.id}
                            title="Delete meeting"
                          >
                            <Trash2 className="h-3.5 w-3.5 text-red-600" />
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            <div className="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div className="flex items-center space-x-2">
                <span className="text-sm text-gray-500 dark:text-gray-400">Rows per page:</span>
                <select
                  value={perPage}
                  onChange={(e) => setPerPage(Number(e.target.value))}
                  className="px-2 py-1 border rounded-md text-sm bg-white dark:bg-slate-900 dark:text-gray-100 border-gray-300 dark:border-slate-600"
                >
                  {PAGE_SIZES.map((size) => (
                    <option key={size} value={size}>
                      {size}
                    </option>
                  ))}
                </select>
              </div>
              <div className="flex items-center space-x-2">
                <span className="text-sm text-gray-500 dark:text-gray-400">
                  Showing {(currentPage - 1) * perPage + 1}-{Math.min(currentPage * perPage, sortedMeetings.length)} of {sortedMeetings.length}
                </span>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={currentPage <= 1}
                  onClick={() => setPage(currentPage - 1)}
                >
                  Previous
                </Button>
                <span className="text-sm text-gray-500 dark:text-gray-400">
                  Page {currentPage} of {totalPages}
                </span>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={currentPage >= totalPages}
                  onClick={() => setPage(currentPage + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  )
}

export default MeetingsDashboard