import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import LeaveApplication from '../../pages/leave/LeaveApplication'

globalThis.IS_REACT_ACT_ENVIRONMENT = true

// Mock AuthContext so the form never warns about a missing provider.
vi.mock('../../context/AuthContext', () => ({
  useAuth: () => ({
    user: { employee_id: 'EMP001', first_name: 'John', last_name: 'Doe' },
  }),
}))

// Mock the api client (default export object with GET/POST).
vi.mock('../../utils/api', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

import api from '../../utils/api'

const TODAY = (() => {
  const d = new Date()
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
})()

const EMPLOYEES = [
  { id: 1, employee_id: 'EMP001', first_name: 'John', last_name: 'Doe' },
]
const DELEGATES = [
  { id: 2, employee_id: 'EMP002', first_name: 'Jane', last_name: 'Doe', role: 'Officer' },
]
const LEAVE_TYPES = [
  { leave_type_id: 1, leave_type_name: 'Annual Leave', remaining_days: 20 },
  { leave_type_id: 2, leave_type_name: 'Sick Leave', remaining_days: 10 },
]
const NO_ACTIVE = []

const routeGet = (existing = NO_ACTIVE) =>
  api.get.mockImplementation(async (url) => {
    const path = String(url)
    if (path.includes('/leave/eligible-employees')) {
      return { data: { data: EMPLOYEES } }
    }
    if (path.includes('/leave/eligible-delegates')) {
      return { data: { data: DELEGATES } }
    }
    if (path.includes('/leave/types?')) {
      return { data: { data: LEAVE_TYPES } }
    }
    if (path.includes('/leave?')) {
      return { data: { data: existing } }
    }
    return { data: { data: [] } }
  })

const routePost = () =>
  api.post.mockImplementation(async (url) => {
    const path = String(url)
    if (path.includes('/leave/calculate')) {
      return {
        data: {
          data: {
            eligible_days: 2,
            deduction_plan: { primary_deduction: 2, annual_deduction: 0, unpaid_days: 0 },
          },
        },
      }
    }
    return { data: { success: true } }
  })

const renderPage = async (existing = NO_ACTIVE) => {
  routeGet(existing)
  routePost()
  const utils = render(
    <MemoryRouter initialEntries={['/leave/apply']}>
      <LeaveApplication />
    </MemoryRouter>
  )
  // Wait for the employee to auto-select and dependent lists to load.
  await waitFor(() => expect(screen.getAllByRole('combobox').length).toBe(3))
  return utils
}

// The form's <label> elements are not wired with htmlFor/id, so we locate
// the controls by DOM position instead of by label text.
const getSelects = () => screen.getAllByRole('combobox')

const selectLeaveType = (typeId) => {
  fireEvent.change(getSelects()[1], {
    target: { value: String(typeId) },
  })
}

const fillDates = (start, end) => {
  const dateInputs = document.querySelectorAll('input[type="date"]')
  fireEvent.change(dateInputs[0], { target: { value: start } })
  fireEvent.change(dateInputs[1], { target: { value: end } })
}

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('LeaveApplication validation rules', () => {
  it('sets min=today on the start date for non-backdate leave types (Annual Leave)', async () => {
    await renderPage()
    selectLeaveType(1)
    await waitFor(() => {
      const input = document.querySelectorAll('input[type="date"]')[0]
      expect(input.getAttribute('min')).toBe(TODAY)
    })
  })

  it('does not restrict the start date for backdate-allowed leave types (Sick Leave)', async () => {
    await renderPage()
    selectLeaveType(2)
    await waitFor(() => {
      const input = document.querySelectorAll('input[type="date"]')[0]
      expect(input.getAttribute('min')).toBeNull()
    })
  })

  it('shows a conflict warning when applying for Annual Leave with a pending application', async () => {
    const pending = [
      { id: 10, start_date: '2026-01-01', end_date: '2026-01-05', status: 'pending_subsection_head' },
    ]
    await renderPage(pending)
    selectLeaveType(1)
    fillDates('2099-01-10', '2099-01-11')
    await waitFor(() => {
      expect(
        screen.getByText(/currently on leave or have a pending leave application/i)
      ).toBeTruthy()
    })
  })

  it('does not show the conflict warning for Sick Leave even with a pending application', async () => {
    const pending = [
      { id: 10, start_date: '2026-01-01', end_date: '2026-01-05', status: 'pending_subsection_head' },
    ]
    await renderPage(pending)
    selectLeaveType(2)
    fillDates('2099-01-10', '2099-01-11')
    await waitFor(() => {
      expect(screen.queryByText(/currently on leave or have a pending leave application/i)).toBeNull()
    })
  })

  it('does not show the conflict warning when no pending or approved applications exist', async () => {
    await renderPage(NO_ACTIVE)
    selectLeaveType(1)
    fillDates('2099-01-10', '2099-01-11')
    await waitFor(() => {
      expect(screen.queryByText(/currently on leave or have a pending leave application/i)).toBeNull()
    })
  })
})