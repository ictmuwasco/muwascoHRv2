import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import AttendanceDashboard from '../../pages/attendance/AttendanceDashboard'
import api from '../../utils/api'

// Mock the API client used by the dashboard page.
vi.mock('../../utils/api', () => ({
  default: { get: vi.fn() },
}))

/*
 * NOTE: We render with the frontend-local react-dom/client directly instead of
 * @testing-library/react, because @testing-library/react currently resolves to
 * the repository-root node_modules which carries a different React major —
 * mixing two React copies breaks rendering ("older version of React").
 */

globalThis.IS_REACT_ACT_ENVIRONMENT = true

let container = null
let root = null

const renderComponent = async (element) => {
  container = document.createElement('div')
  document.body.appendChild(container)
  root = createRoot(container)
  await act(async () => {
    root.render(element)
  })
}

const teardown = () => {
  if (root) {
    act(() => {
      root.unmount()
    })
  }
  if (container && container.parentNode) {
    container.parentNode.removeChild(container)
  }
  container = null
  root = null
}

/** Poll until predicate() returns true or timeout (ms). */
const waitFor = async (predicate, timeout = 4000) => {
  const start = Date.now()
  while (!predicate()) {
    if (Date.now() - start > timeout) {
      throw new Error('waitFor timed out')
    }
    await new Promise((resolve) => setTimeout(resolve, 20))
  }
}

const textIncludes = (...needles) =>
  needles.every((needle) => container.textContent.includes(needle))

const baseEmployee = {
  employee_db_id: 101,
  employee_no: 'EMP-001',
  name: 'John Kamau',
  department: 'Finance',
  department_id: 3,
  position: 'Accountant',
  phone: '0712345678',
  email: null,
  profile_image_url: null,
  date: '2026-08-21',
  has_record: true,
  clock_in_time: '08:03',
  clock_out_time: '17:05',
  work_hours: '9h 02m',
  worked_minutes: 542,
  status: 'PRESENT',
  status_label: 'Present',
  is_late: false,
  auto_clocked_out: false,
  attendance_status: 'clocked_out',
}

const buildPayload = (overrides = {}) => ({
  date: '2026-08-21',
  is_today: true,
  context: {
    working_day: true,
    is_holiday: false,
    holiday_name: null,
    is_non_working_day: false,
    not_clocked_in_enabled: false,
  },
  summary: {
    total_employees: 245,
    expected_to_work: 237,
    present: 198,
    not_clocked_in: 0,
    absent: 27,
    on_leave: 8,
    holiday: 0,
    non_working_day: 0,
    late: 4,
    missing_clock_out: 0,
    auto_clocked_out: 0,
    attendance_rate: 83.54,
  },
  employees: [baseEmployee],
  pagination: { page: 1, limit: 25, total: 1, total_pages: 1 },
  departments: [
    {
      department_id: 3,
      department: 'Finance',
      total_employees: 10,
      expected_to_work: 9,
      present: 8,
      absent: 1,
      on_leave: 0,
      late: 0,
      attendance_rate: 88.89,
    },
  ],
  trend: [
    {
      date: '2026-08-15',
      label: 'Sat 15 Aug',
      is_today: false,
      working_day: false,
      is_holiday: false,
      holiday_name: null,
      is_non_working_day: true,
      present: 2,
      late: 0,
      on_leave: 0,
      absent: 0,
      not_clocked_in: 0,
      attendance_rate: null,
    },
  ],
  absent_employees: [],
  statuses: ['PRESENT'],
  filters_applied: {},
  ...overrides,
})

describe('AttendanceDashboard', () => {
  let mockGet

  beforeEach(() => {
    teardown()
    // Instance-agnostic mock: the hoisted vi.mock factory's vi.fn() may
    // belong to a different vitest copy than the running one (repo has
    // both root- and frontend-installed tooling), leaving it without
    // spy methods. Rebuild the spy from THIS file's `vi` each test.
    mockGet = vi.fn()
    mockGet.mockResolvedValue({
      data: { success: true, message: 'Success', data: buildPayload() },
    })
    api.get = mockGet
  })

  it('renders summary stat cards and the employee table from API data', async () => {
    await renderComponent(<AttendanceDashboard />)

    await waitFor(() => textIncludes('John Kamau'))

    // Cards show server-computed figures
    expect(textIncludes('245', '198', '27', '83.54%')).toBe(true)
    // Employee row rendered with authoritative status label and times
    expect(textIncludes('EMP-001', '08:03', '17:05', '9h 02m')).toBe(true)

    // API was called on the monitoring endpoint
    expect(vi.mocked(api.get).mock.calls[0][0]).toBe('/attendance/hr-dashboard')
  })

  it('clicking the Present card applies the Present quick filter', async () => {
    await renderComponent(<AttendanceDashboard />)
    await waitFor(() => textIncludes('John Kamau'))

    const callsBefore = vi.mocked(api.get).mock.calls.length
    const presentCard = container.querySelectorAll('button[title="Filter by present"]')
    expect(presentCard.length).toBeGreaterThan(0)

    await act(async () => {
      presentCard[0].dispatchEvent(new window.MouseEvent('click', { bubbles: true }))
    })

    await waitFor(() => vi.mocked(api.get).mock.calls.length > callsBefore)
    const latestParams = vi.mocked(api.get).mock.calls.at(-1)[1].params
    expect(latestParams.status).toBe('PRESENT')
  })

  it('renders horizontal status tabs and applies the Absent filter when its tab is clicked', async () => {
    await renderComponent(<AttendanceDashboard />)
    await waitFor(() => textIncludes('John Kamau'))

    // Tabs exist: All Employees / Present / Absent / On Leave
    const tabs = [...container.querySelectorAll('[role="tab"]')]
    expect(tabs.map((t) => t.textContent)).toEqual([
      'All Employees',
      'Present (198)',
      'Absent (27)',
      'On Leave (8)',
    ])
    // "All Employees" is the default active tab
    expect(tabs[0].getAttribute('aria-selected')).toBe('true')

    const callsBefore = vi.mocked(api.get).mock.calls.length
    const absentTab = tabs.find((t) => t.textContent === 'Absent (27)')
    expect(absentTab).toBeTruthy()

    await act(async () => {
      absentTab.dispatchEvent(new window.MouseEvent('click', { bubbles: true }))
    })

    // Filter applied to the fetch
    await waitFor(() => vi.mocked(api.get).mock.calls.length > callsBefore)
    const latestParams = vi.mocked(api.get).mock.calls.at(-1)[1].params
    expect(latestParams.status).toBe('ABSENT')

    // Active tab moved to Absent
    await waitFor(() => absentTab.getAttribute('aria-selected') === 'true')
    expect(container.querySelector('[role="tab"][aria-selected="true"]').textContent).toBe('Absent (27)')
  })

  it('On Leave tab shows ONLY employees on leave with their leave type', async () => {
    const onLeaveEmployee = {
      ...baseEmployee,
      employee_db_id: 202,
      employee_no: 'EMP-041',
      name: 'Peter Mwangi',
      status: 'ON_LEAVE',
      status_label: 'On Leave',
      leave_type: 'Annual Leave',
      has_record: false,
      clock_in_time: null,
      clock_out_time: null,
      work_hours: null,
      worked_minutes: null,
    }
    vi.mocked(api.get).mockImplementation((_url, config = {}) => {
      const status = config?.params?.status
      const payload =
        status === 'ON_LEAVE'
          ? buildPayload({
              employees: [onLeaveEmployee],
              pagination: { page: 1, limit: 25, total: 1, total_pages: 1 },
            })
          : buildPayload()
      return Promise.resolve({ data: { success: true, message: 'Success', data: payload } })
    })

    await renderComponent(<AttendanceDashboard />)
    await waitFor(() => textIncludes('John Kamau'))

    // Switch to the On Leave tab
    const leaveTab = [...container.querySelectorAll('[role="tab"]')].find((t) =>
      t.textContent.startsWith('On Leave'),
    )
    await act(async () => {
      leaveTab.dispatchEvent(new window.MouseEvent('click', { bubbles: true }))
    })

    // Card heading + subtitle reflect ONLY-leave content
    await waitFor(() => textIncludes('Employees On Approved Leave (8)'))
    expect(textIncludes('Only employees with approved leave covering this date are shown here.')).toBe(true)

    // Columns are leave-specific: Leave Type shown, attendance columns hidden
    // (scope to the first table = the main tab table, not the department summary)
    const mainTableHeaders = [...container.querySelector('table').querySelectorAll('th')].map((th) => th.textContent)
    expect(mainTableHeaders).toEqual(['Employee', 'Department', 'Leave Type', 'Actions'])
    expect(textIncludes('Annual Leave')).toBe(true)

    // The old always-visible "not clocked in" chips panel must NOT render here
    expect(container.textContent.includes('Who Have Not Clocked In')).toBe(false)
  })

  it('shows the holiday banner when the selected date is a public holiday', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        success: true,
        message: 'Success',
        data: buildPayload({
          context: {
            working_day: false,
            is_holiday: true,
            holiday_name: 'Mashujaa Day',
            is_non_working_day: false,
            not_clocked_in_enabled: false,
          },
        }),
      },
    })

    await renderComponent(<AttendanceDashboard />)

    await waitFor(() => textIncludes('Public Holiday — Mashujaa Day'))
    expect(textIncludes('Attendance tracking is not required.')).toBe(true)
  })

  it('shows an error state when the API call fails', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('Database unreachable'))

    await renderComponent(<AttendanceDashboard />)

    await waitFor(() => textIncludes('Something went wrong'))
    expect(textIncludes('Database unreachable')).toBe(true)

    const retryButton = [...container.querySelectorAll('button')].find((b) =>
      b.textContent.toLowerCase().includes('try again'),
    )
    expect(retryButton).toBeTruthy()
  })
})


