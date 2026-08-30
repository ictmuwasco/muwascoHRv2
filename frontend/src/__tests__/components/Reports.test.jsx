import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import Reports from '../../pages/reports/Reports'
import apiClient from '../../api/client'
import { MemoryRouter } from 'react-router-dom'

// NOTE: Deliberately NO vi.mock('../../api/client') here - see the comment
// inside the describe block below for the reason.



globalThis.IS_REACT_ACT_ENVIRONMENT = true

let container = null
let root = null

const renderComponent = async (element) => {
  container = document.createElement('div')
  document.body.appendChild(container)
  root = createRoot(container)
  await act(async () => {
    // Reports.tsx uses useNavigate/useLocation (cross-page report tabs), so it
    // must be rendered inside a router context.
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

// Fixture: 3 employees tailored to the reports logic.
//  - John (EMP-001) DOB 1966-05-15 -> age 60  (reached retirement)
//  - Jane (EMP-002) DOB 1964-03-10 -> age 62  (reached retirement), contract ends 2026-09-15 (near expiry)
//  - Bob  (EMP-003) DOB 1967-08-15 -> age 59  (approaching 60), contract already expired
//  - Ruth (EMP-004) DOB 1967-10-09 -> age 59  BUT status "retired" -> must be
//    excluded from the near-retirement set/table/count
const buildPayload = (overrides = {}) => ({
  summary: {
    total: 3,
    active: 2,
    inactive: 1,
    by_department: { Finance: 2, HR: 1 },
    by_employment_type: { permanent: 2, contract: 1 },
    by_status: { active: 2, inactive: 1 },
    by_gender: { male: 2, female: 1 },
  },
  records: [
    {
      id: 1,
      employee_id: 'EMP-001',
      first_name: 'John',
      last_name: 'Doe',
      surname: 'Kariuki',
      email: 'john@example.com',
      phone: '0711111111',
      gender: 'male',
      employee_status: 'active',
      employee_type: 'permanent',
      designation: 'Accountant',
      department_name: 'Finance',
      section_name: 'Accounts',
      date_of_birth: '1966-05-15',
      contract_start_date: '2020-01-01',
      contract_end_date: '2028-01-01',
      national_id: '12345678',
      hire_date: '2020-01-01',
      created_at: '2020-01-15',
    },
    {
      id: 2,
      employee_id: 'EMP-002',
      first_name: 'Jane',
      last_name: 'Smith',
      surname: null,
      email: 'jane@example.com',
      phone: '0722222222',
      gender: 'female',
      employee_status: 'active',
      employee_type: 'permanent',
      designation: 'HR Officer',
      department_name: 'HR',
      section_name: 'Recruitment',
      date_of_birth: '1964-03-10',
      contract_start_date: '2018-06-01',
      contract_end_date: '2026-09-15',
      national_id: '87654321',
      hire_date: '2018-06-01',
      created_at: '2018-06-01',
    },
    {
      id: 3,
      employee_id: 'EMP-003',
      first_name: 'Bob',
      last_name: 'Ochieng',
      surname: null,
      email: 'bob@example.com',
      phone: '0733333333',
      gender: 'male',
      employee_status: 'inactive',
      employee_type: 'contract',
      designation: 'Consultant',
      department_name: 'Finance',
      section_name: null,
      date_of_birth: '1967-08-15',
      contract_start_date: '2022-01-01',
      contract_end_date: '2025-12-01',
      national_id: '11111111',
      hire_date: '2022-01-01',
      created_at: '2022-01-01',
    },
    {
      id: 4,
      employee_id: 'EMP-004',
      first_name: 'Ruth',
      last_name: 'Wanjiku',
      surname: null,
      email: 'ruth@example.com',
      phone: '0744444444',
      gender: 'female',
      employee_status: 'retired',
      employee_type: 'permanent',
      designation: 'Officer',
      department_name: 'Technical',
      section_name: null,
      date_of_birth: '1967-10-09',
      contract_start_date: null,
      contract_end_date: null,
      national_id: '22222222',
      hire_date: '2010-01-01',
      created_at: '2010-01-01',
    },
    ...(overrides.records ?? []),
  ],
})

describe('Reports - Employee Reports', () => {
  // NOTE: vi.mock factories cannot intercept TypeScript modules under this
  // repo's toolchain (vitest 1.6.1 + vite 5.4 + @vitejs/plugin-react): the
  // component would silently receive the REAL axios instance while this file
  // receives the mock ("default.get.mockReset is not a function"). Instead,
  // stub the method on the SHARED singleton instance - axios.create() assigns
  // .get as an own property, so the component under test and this file see
  // the exact same stub.
  beforeEach(() => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({
      data: { success: true, data: buildPayload() },
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
    teardown()
  })

  it('renders summary stat cards from API data', async () => {
    await renderComponent(<Reports />)
    await waitFor(() => textIncludes('Total Employees', '3'))
    expect(textIncludes('Active', '2')).toBe(true)
    expect(textIncludes('Inactive', '1')).toBe(true)
    // Near-retirement = within 5 years of age 60 (ages >= 55): John 60, Jane 62, Bob 59 -> 3
    expect(textIncludes('Near Retirement', '3')).toBe(true)
    expect(textIncludes('Contracts Near Expiry', '1')).toBe(true)
    expect(textIncludes('Expired Contracts', '1')).toBe(true)
  })

  it('renders the near-retirement table for employees within 5 years of retirement', async () => {
    await renderComponent(<Reports />)
    await waitFor(() => container.querySelector('table'))

    // Ages: Jane 62 and John 60 (at/over 60) plus Bob 59 (within the 5-year window)
    expect(textIncludes('Jane', 'Smith')).toBe(true)
    expect(textIncludes('62')).toBe(true)
    expect(textIncludes('John', 'Doe')).toBe(true)
    expect(textIncludes('Bob', 'Ochieng')).toBe(true)
  })

  it('renders the contracts near-expiry table', async () => {
    await renderComponent(<Reports />)
    await waitFor(() => textIncludes('Contracts Near Expiry'))

    // Jane's contract ends 2026-09-15 (within 6 months of the run date)
    expect(textIncludes('Jane', 'Smith')).toBe(true)
    expect(container.textContent.includes('days left')).toBe(true)
  })

  it('renders the All Employees table with filter controls', async () => {
    await renderComponent(<Reports />)
    await waitFor(() => textIncludes('All Employees'))

    const searchInput = container.querySelector('input[type="text"]')
    expect(searchInput).toBeTruthy()

    const rows = container.querySelectorAll('tbody tr')
    expect(rows.length).toBeGreaterThan(0)
    expect(textIncludes('John', 'Doe')).toBe(true)
  })

  it('shows an error state when the API call fails', async () => {
    apiClient.get.mockRejectedValue(new Error('Network failure'))

    await renderComponent(<Reports />)
    await waitFor(() => textIncludes('Network failure'))
    expect(textIncludes('Retry')).toBe(true)
  })

  it('filters the All Employees table when a stat card is clicked', async () => {
    await renderComponent(<Reports />)
    await waitFor(() => textIncludes('Total Employees'))

    const statCard = (needle) =>
      Array.from(container.querySelectorAll('button')).find((b) => b.textContent.includes(needle))

    // Click the "Near Retirement" stat card -> quick filter applied to the table
    await act(async () => {
      statCard('Near Retirement').click()
    })
    await waitFor(() => textIncludes('Filtered by'))
    expect(textIncludes('Near retirement (within 5 yrs of 60, excl. retired)')).toBe(true)
    // Bob (age 59) belongs to the near-retirement set...
    expect(textIncludes('Bob', 'Ochieng')).toBe(true)
    // ...but Ruth (age 59, status "retired") must NOT be fetched into it
    expect(container.textContent.includes('ruth@example.com')).toBe(false)

    // Switch to the "Inactive" card -> only inactive employees are listed
    await act(async () => {
      statCard('Inactive').click()
    })
    await waitFor(() => !container.textContent.includes('john@example.com'))
    expect(textIncludes('bob@example.com')).toBe(true)
    expect(container.textContent.includes('jane@example.com')).toBe(false)

    // "Clear" removes the quick filter again
    const clearBtn = Array.from(container.querySelectorAll('button')).find(
      (b) => b.textContent.trim() === 'Clear'
    )
    await act(async () => {
      clearBtn.click()
    })
    await waitFor(() => !container.textContent.includes('Filtered by'))
    // Everyone is back
    expect(textIncludes('john@example.com', 'bob@example.com')).toBe(true)
  })
})
