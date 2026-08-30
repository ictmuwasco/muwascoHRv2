import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import LeaveReports from '../../pages/reports/LeaveReports'
import apiClient from '../../api/client'

globalThis.IS_REACT_ACT_ENVIRONMENT = true

let container = null
let root = null

const renderComponent = async () => {
  container = document.createElement('div')
  document.body.appendChild(container)
  root = createRoot(container)
  await act(async () => {
    root.render(
      <MemoryRouter initialEntries={['/leave/reports']}>
        <LeaveReports />
      </MemoryRouter>
    )
  })
}

const teardown = () => {
  if (root) {
    act(() => root.unmount())
  }
  if (container && container.parentNode) {
    container.parentNode.removeChild(container)
  }
  container = null
  root = null
}

const waitFor = async (predicate, timeout = 4000) => {
  const start = Date.now()
  while (!predicate()) {
    if (Date.now() - start > timeout) throw new Error('waitFor timed out')
    await new Promise((resolve) => setTimeout(resolve, 20))
  }
}

const textIncludes = (...needles) =>
  needles.every((needle) => container.textContent.includes(needle))

// Route each Leave Report endpoint to a canned payload keyed by URL.
const respond = (url, data) => ({ data: { success: true, data } })

const EMPTY_SUMMARY = {
  total_applications: 0,
  total_days: 0,
  avg_duration: 0,
  approved: 0,
  pending: 0,
  rejected: 0,
  cancelled: 0,
  invalidated: 0,
  approved_pct: 0,
  rejected_pct: 0,
}

const stubApi = ({ summary = EMPTY_SUMMARY, records = [] } = {}) => {
  vi.spyOn(apiClient, 'get').mockImplementation(async (url) => {
    const path = String(url)
    if (path.includes('/reports/leave/options')) {
      return respond(path, {
        departments: [{ id: 1, name: 'HR' }],
        leave_types: [{ id: 1, name: 'Annual Leave' }],
        financial_years: [{ id: 1, year_name: '2025/26' }],
        statuses: ['pending', 'approved', 'rejected', 'cancelled', 'invalidated'],
      })
    }
    if (path.includes('/reports/leave/summary')) return respond(path, summary)
    if (path.includes('/reports/leave/trends')) return respond(path, { grouping: 'monthly', points: [] })
    if (path.includes('/reports/leave/by-type')) return respond(path, [])
    if (path.includes('/reports/leave/by-department')) return respond(path, [])
    if (path.includes('/reports/leave/by-status')) return respond(path, [])
    if (path.includes('/reports/leave/duration')) return respond(path, [])
    if (path.includes('/reports/leave/insights')) return respond(path, [])
    if (path.includes('/reports/leave/records')) {
      return respond(path, { items: records, total: records.length, page: 1, per_page: 15, last_page: 1 })
    }
    return respond(null, {})
  })
}

beforeEach(() => {
  stubApi()
})

afterEach(() => {
  vi.restoreAllMocks()
  teardown()
})

describe('LeaveReports', () => {
  it('renders the page header', async () => {
    await renderComponent()
    await waitFor(() => textIncludes('Leave Reports'))
    expect(container.textContent.includes('Analyze employee leave patterns')).toBe(true)
  })

  it('shows an empty state when no records match the filters', async () => {
    await renderComponent()
    await waitFor(() => textIncludes('No leave records found'))
    expect(textIncludes('Try adjusting your filters or selecting a different reporting period.')).toBe(true)
  })

  it('renders KPI cards from the summary', async () => {
    stubApi({
      summary: { ...EMPTY_SUMMARY, total_applications: 0, approved: 4 },
    })
    await renderComponent()
    await waitFor(() => textIncludes('Total Applications', 'Approved'))
  })
})