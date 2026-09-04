import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import DelegateBanner from '../../components/DelegateBanner'

globalThis.IS_REACT_ACT_ENVIRONMENT = true

// The banner renders purely from the effective permission context the
// AuthContext delivers (/auth/user active_delegations) — no api calls.
let mockUser = { id: 42, active_delegations: [] }

vi.mock('../../context/AuthContext', () => ({
  useAuth: () => ({
    user: mockUser,
    can: (module, action) => false,
    canAny: () => false,
  }),
}))

describe('DelegateBanner (Temporary Delegation / Acting Authority)', () => {
  beforeEach(() => {
    mockUser = { id: 42, active_delegations: [] }
  })

  afterEach(() => {
    cleanup()
  })

  it('renders NOTHING when the user holds no active delegation', () => {
    const { container } = render(<DelegateBanner />)
    expect(container.textContent).toBe('')
  })

  it('renders nothing when active_delegations is missing entirely (stale cache)', () => {
    mockUser = { id: 42 }
    const { container } = render(<DelegateBanner />)
    expect(container.textContent).toBe('')
  })

  it('shows the acting-delegate notice with delegator, authority and validity (§27/§28)', () => {
    mockUser = {
      id: 42,
      active_delegations: [
        {
          id: 7,
          delegator_name: 'Samuel Mwangi',
          delegated_role: 'section_head',
          scope_label: 'Water Section',
          permissions: ['leave:approve', 'leave:manage'],
          start_date: '2026-09-10',
          end_date: '2026-09-25',
        },
      ],
    }

    render(<DelegateBanner />)

    const text = document.body.textContent
    expect(text).toContain('Acting Delegate')
    expect(text).toContain('Samuel Mwangi')
    expect(text).toContain('leave:approve')
    expect(text).toContain('Water Section')
    expect(text).toContain('temporary delegation')
  })

  it('renders one notice per active delegation (multiple delegators, §33)', () => {
    mockUser = {
      id: 42,
      active_delegations: [
        { id: 7, delegator_name: 'Samuel Mwangi', delegated_role: 'section_head', scope_label: 'Water Section', permissions: ['leave:approve'], end_date: '2026-09-25' },
        { id: 8, delegator_name: 'Grace Wanjiru', delegated_role: 'dept_head', scope_label: 'Organization-wide', permissions: ['leave:manage'], end_date: '2026-09-30' },
      ],
    }

    render(<DelegateBanner />)

    expect(document.body.textContent).toContain('Samuel Mwangi')
    expect(document.body.textContent).toContain('Grace Wanjiru')
  })
})
