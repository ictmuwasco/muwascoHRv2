import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import AttendanceReport from '../../pages/reports/AttendanceReport'
import apiClient from '../../api/client'
import { MemoryRouter } from 'react-router-dom'

// NOTE: Deliberately NO vi.mock('../../api/client') here - vi.mock factories
// cannot intercept TypeScript modules under this repo's toolchain (vitest
// 1.6.1 + vite 5.4). Instead we stub `.get` on the SHARED axios singleton with
// a URL-dispatching implementation so the component under test and this file
// see the exact same stub. Same proven pattern as Reports.test.jsx.

globalThis.IS_REACT_ACT_ENVIRONMENT = true

// jsdom does not implement ResizeObserver (recharts ResponsiveContainer needs
// it) or matchMedia - provide lightweight no-op stubs so the chart sections
// mount without crashing the component under test.
if (typeof globalThis.ResizeObserver === 'undefined') {
  globalThis.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}
if (typeof window.matchMedia === 'undefined') {
  window.matchMedia = (query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })
}

// ---- Fixtures (matching the AttendanceReportController contract) -----------
const summaryFixture = {
  start_date: '2026-08-01',
  end_date: '2026-08-31',
  grouping: 'daily',
  range_days: 31,
  holidays_in_range: 0,
  attendance_records: 5,
  employees_with_records: 3,
  employees_on_leave: 1,
  late_arrivals: 2,
  auto_clockouts: 1,
  missing_clockouts: 1,
  total_hours: 41.5,
  avg_hours_per_day: 8.3,
  avg_hours_per_employee: 13.8,
  expected_working_days: 22,
  present_days: 5,
  leave_days: 1,
  absent_days: 2,
  compliance_rate: 95.5,
}

const trendsFixture = {
  grouping: 'daily',
  points: [
    { label: 'Aug 3', present: 3, late: 2, missing: 0, auto: 0, on_leave: 0, absent: 1, hours: 24.5 },
    { label: 'Aug 4', present: 2, late: 0, missing: 1, auto: 1, on_leave: 1, absent: 1, hours: 17 },
  ],
}

const byStatusFixture = [
  { status: 'present', count: 2, hours: 41.5 },
  { status: 'late', count: 2 },
  { status: 'missing_clockout', count: 1 },
  { status: 'auto_clockout', count: 1 },
  { status: 'on_leave', count: 1 },
  { status: 'absent', count: 2 },
]

const byDepartmentFixture = [
  {
    department_id: 1,
    department: 'Finance',
    present: 5,
    late: 2,
    auto: 1,
    missing: 1,
    on_leave: 1,
    absent: 2,
    expected_days: 22,
    total_hours: 41.5,
    attendance_rate: 99.5,
  },
]

const lateArrivalsFixture = {
  total_late: 2,
  repeat_offenders: 1,
  threshold: 3,
  employees: [
    { employee_id: 1, emp_no: 'EMP-001', name: 'John Doe', department: 'Finance', late_days: 2 },
  ],
  by_department: [{ department: 'Finance', late_days: 2 }],
}

const workingHoursFixture = {
  grouping: 'daily',
  total_hours: 41.5,
  avg_hours_per_day: 8.3,
  avg_hours_per_employee: 13.8,
  trend: [
    { label: 'Aug 3', hours: 24.5, records: 3, avg_hours: 8.2 },
  ],
}

const complianceFixture = {
  start_date: '2026-08-01',
  end_date: '2026-08-31',
  grouping: 'daily',
  expected_working_days: 22,
  present_days: 5,
  leave_days: 1,
  absent_days: 2,
  compliance_rate: 95.5,
  series: [
    { label: 'Aug 3', expected: 4, present: 3, on_leave: 0, absent: 1, rate: 75 },
  ],
  lowest: [{ label: 'Aug 4', rate: 50 }],
}

const insightsFixture = {
  insights: [
    'Attendance compliance rate was 95.5% (5 present day(s) out of 22 expected working day(s)).',
    'Attendance increased compared to the previous period (5 vs 4 record(s)).',
  ],
  previous_period: { from: '2026-07-01', to: '2026-07-31' },
}

const employeesFixture = {
  items: [
    {
      employee_id: 1,
      emp_no: 'EMP-001',
      name: 'John Doe',
      department: 'Finance',
      office: 'HQ',
      expected_days: 22,
      days_present: 3,
      absent_days: 0,
      leave_days: 0,
      late_days: 2,
      auto_days: 0,
      missing_out: 0,
      total_hours: 24.5,
      avg_hours: 8.2,
      attendance_rate: 100,
    },
    {
      employee_id: 2,
      emp_no: 'EMP-002',
      name: 'Jane Smith',
      department: 'HR',
      office: '',
      expected_days: 22,
      days_present: 1,
      absent_days: 1,
      leave_days: 1,
      late_days: 0,
      auto_days: 1,
      missing_out: 1,
      total_hours: 8,
      avg_hours: 8,
      attendance_rate: 88,
    },
    {
      employee_id: 3,
      emp_no: 'EMP-003',
      name: 'Bob Ochieng',
      department: 'Finance',
      office: 'HQ',
      expected_days: 22,
      days_present: 1,
      absent_days: 1,
      leave_days: 0,
      late_days: 0,
      auto_days: 0,
      missing_out: 0,
      total_hours: 9,
      avg_hours: 9,
      attendance_rate: 75.5,
    },
  ],
  total: 3,
  page: 1,
  per_page: 10,
  last_page: 1,
  sort: 'days_present',
  dir: 'desc',
}

const recordsFixture = {
  items: [
    {
      id: 1,
      attendance_date: '2026-08-05',
      clock_in: '2026-08-05 08:45:00',
      clock_out: '2026-08-05 17:00:00',
      hours: 8.3,
      is_late: true,
      auto_clocked_out: false,
      status_label: 'Late',
    },
    {
      id: 2,
      attendance_date: '2026-08-04',
      clock_in: '2026-08-04 08:10:00',
      clock_out: '2026-08-04 17:05:00',
      hours: 8.9,
      is_late: false,
      auto_clocked_out: false,
      status_label: 'Present',
    },
  ],
  total: 2,
  page: 1,
  per_page: 15,
  last_page: 1,
  employee: { id: 1, emp_no: 'EMP-001', name: 'John Doe', department: 'Finance', office: 'HQ' },
}

const optionsFixture = {
  departments: [
    { id: 1, name: 'Finance' },
    { id: 2, name: 'HR' },
  ],
  offices: [{ id: 1, name: 'HQ' }],
  employee_types: [{ id: 'permanent', name: 'permanent' }],
  statuses: ['present', 'late', 'missing', 'auto', 'absent', 'on_leave'],
}

/** Endpoint dispatch: url (+ optional params) -> payload (res.data?.data shape). */
const endpointPayload = (url) => {
  if (url.endsWith('/options')) return optionsFixture
  if (url.endsWith('/summary')) return summaryFixture
  if (url.endsWith('/trends')) return trendsFixture
  if (url.endsWith('/by-status')) return byStatusFixture
  if (url.endsWith('/by-department')) return byDepartmentFixture
  if (url.endsWith('/late-arrivals')) return lateArrivalsFixture
  if (url.endsWith('/working-hours')) return workingHoursFixture
  if (url.endsWith('/compliance')) return complianceFixture
  if (url.endsWith('/insights')) return insightsFixture
  if (url.endsWith('/employees')) return employeesFixture
  if (url.endsWith('/records')) return recordsFixture
  return {}
}

// ---- Harness ---------------------------------------------------------------
let container = null
let root = null

const renderComponent = async (element) => {
  container = document.createElement('div')
  document.body.appendChild(container)
  root = createRoot(container)
  await act(async () => {
    root.render(<MemoryRouter>{element}</MemoryRouter>)
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

const findButton = (needle) =>
  Array.from(container.querySelectorAll('button')).find((b) => b.textContent.includes(needle))

const callsTo = (url) =>
  apiClient.get.mock.calls.filter(([u]) => String(u).endsWith(url))

const lastCallParams = (url) => {
  const calls = callsTo(url)
  return calls.length ? calls[calls.length - 1][1]?.params ?? {} : null
}

describe('AttendanceReport', () => {
  let failSummaryOnce = false

  beforeEach(() => {
    failSummaryOnce = false
    vi.spyOn(apiClient, 'get').mockImplementation((url) => {
      if (failSummaryOnce && String(url).endsWith('/summary')) {
        failSummaryOnce = false
        return Promise.reject({ response: { data: { message: 'Network down' } } })
      }
      return Promise.resolve({ data: { success: true, data: endpointPayload(String(url)) } })
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
    teardown()
  })

  it('renders KPI cards from the summary endpoint', async () => {
    await renderComponent(<AttendanceReport />)
    await waitFor(() => textIncludes('Attendance Records', '5'))
    expect(textIncludes('Present', '3')).toBe(true)
    expect(textIncludes('Absent People', '2')).toBe(true)
    expect(textIncludes('Late Arrivals', '2')).toBe(true)
    expect(textIncludes('Missing Clock-Outs', '1')).toBe(true)
    expect(textIncludes('Auto Clock-Outs', '1')).toBe(true)
    expect(textIncludes('Total Hours', '41.5')).toBe(true)
    expect(textIncludes('Compliance Rate', '95.5%')).toBe(true)
  })

  it('renders analytics sections (trend, status distribution, departments, insights)', async () => {
    await renderComponent(<AttendanceReport />)
    await waitFor(() => textIncludes('Attendance Trend'))
    expect(textIncludes('Attendance Status Distribution')).toBe(true)
    expect(textIncludes('Department Analysis')).toBe(true)
    expect(textIncludes('Department Attendance Performance', 'Finance')).toBe(true)
    expect(textIncludes('Working Hours Analysis', 'Late Arrival Analysis')).toBe(true)
    expect(textIncludes('Attendance Compliance', 'Attendance Insights')).toBe(true)
    // Insights come from the insights endpoint verbatim.
    expect(textIncludes('Attendance increased compared to the previous period (5 vs 4 record(s)).')).toBe(true)
  })

  it('renders the per-employee attendance table from the employees endpoint', async () => {
    await renderComponent(<AttendanceReport />)
    await waitFor(() => textIncludes('John Doe', 'Jane Smith', 'Bob Ochieng'))
    // Sortable headers + server pagination footer.
    expect(textIncludes('Expected', 'Present', 'Absent', 'On Leave', 'Rate %')).toBe(true)
    expect(textIncludes('Page 1 of 1 · 3 employee(s)')).toBe(true)
    // Summary/employees endpoints hit with date params.
    const sumParams = lastCallParams('/reports/attendance/summary')
    expect(String(sumParams.from)).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    const empParams = lastCallParams('/reports/attendance/employees')
    expect(empParams.per_page).toBe(50)
  })

  it('filters server-side when a KPI card is clicked and clears via Attendance Records', async () => {
    await renderComponent(<AttendanceReport />)
    await waitFor(() => textIncludes('John Doe'))
    const before = callsTo('/reports/attendance/employees').length

    await act(async () => {
      container.querySelector('[data-testid="stat-late-arrivals"]').click()
    })
    await waitFor(() => callsTo('/reports/attendance/employees').length > before)
    expect(lastCallParams('/reports/attendance/employees').status).toBe('late')

    // Clicking "Attendance Records" clears the lens (status param absent).
    const withLens = callsTo('/reports/attendance/employees').length
    await act(async () => {
      container.querySelector('[data-testid="stat-attendance-records"]').click()
    })
    await waitFor(() => callsTo('/reports/attendance/employees').length > withLens)
    expect(lastCallParams('/reports/attendance/employees').status).toBeUndefined()
  })

  it('opens the drill-down drawer with the employee daily records', async () => {
    await renderComponent(<AttendanceReport />)
    await waitFor(() => textIncludes('John Doe'))

    await act(async () => {
      container.querySelector('button[aria-label="View John Doe attendance details"]').click()
    })
    await waitFor(() => container.querySelector('[role="dialog"]')?.textContent.includes('Late'))
    // Drawer fetched the records endpoint scoped to the employee + period.
    const recParams = lastCallParams('/reports/attendance/records')
    expect(recParams.employee_id).toBe(1)
    expect(String(recParams.from)).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    // Drawer header carries the employee identity + both status rows rendered.
    expect(container.querySelector('[role="dialog"]')?.textContent).toContain('John Doe')
    expect(container.querySelector('[role="dialog"]')?.textContent).toContain('Present')
  })

  it('exports a CSV respecting the active filters', async () => {
    const created = vi.fn()
    window.URL.createObjectURL = created.mockReturnValue('blob:mock')
    window.URL.revokeObjectURL = vi.fn()

    await renderComponent(<AttendanceReport />)
    await waitFor(() => textIncludes('John Doe'))

    await act(async () => {
      findButton('Export CSV').click()
    })
    await waitFor(() => callsTo('/reports/attendance/export').length > 0)
    const exportParams = lastCallParams('/reports/attendance/export')
    expect(String(exportParams.from)).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(created).toHaveBeenCalled()
  })

  it('shows an error state and recovers via Retry', async () => {
    failSummaryOnce = true
    await renderComponent(<AttendanceReport />)
    await waitFor(() => textIncludes('Network down'))
    expect(textIncludes('Retry')).toBe(true)

    // The next summary call resolves (failSummaryOnce resets itself), so the
    // retry recovers the dashboard.
    await act(async () => {
      findButton('Retry').click()
    })
    await waitFor(() => textIncludes('Attendance Records', '5'))
  })
})
