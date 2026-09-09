import { describe, it, expect } from 'vitest'
import { buildSuggestedQuestions } from '../../components/ai/suggestedQuestions'

// Mirror of AuthContext's can() helper: effective 'module:action' strings.
const canFor = (permissions) => (module, action = 'view') =>
  permissions.includes(`${module}:${action}`)

describe('buildSuggestedQuestions (permission-aware AI starter prompts)', () => {
  it('offers only self-service questions to a plain employee', () => {
    const questions = buildSuggestedQuestions(
      { id: 42, permissions: ['leave:view'] },
      canFor(['leave:view'])
    )

    expect(questions).toContain('What is my remaining leave balance?')
    expect(questions).toContain('Summarize my attendance this month.')
    expect(questions).toContain('Explain the leave approval process.')

    expect(questions).not.toContain('Which leave applications need my approval?')
    expect(questions).not.toContain('Show my delegated approvals.')
    expect(questions).not.toContain('Show employees currently on leave.')
    expect(questions).not.toContain('Show pending appraisals for my team.')
  })

  it('adds the approval-queue question for approvers', () => {
    const permissions = ['leave:view', 'leave:approve']
    const questions = buildSuggestedQuestions({ id: 42, permissions }, canFor(permissions))
    expect(questions).toContain('Which leave applications need my approval?')
  })

  it('adds delegation prompts when the user holds active delegations', () => {
    const user = {
      id: 42,
      permissions: ['leave:view'],
      active_delegations: [{ id: 7, delegator_name: 'Samuel Mwangi', end_date: '2026-09-25' }],
    }
    const questions = buildSuggestedQuestions(user, canFor(['leave:view']))
    expect(questions).toContain('Show my delegated approvals.')
    expect(questions).toContain('Who is currently acting for me?')
  })

  it('adds leave-roster and appraisal prompts for HR visibility', () => {
    const permissions = ['leave:manage', 'employee:view', 'performance:view']
    const questions = buildSuggestedQuestions({ id: 9, permissions }, canFor(permissions))
    expect(questions).toContain('Show employees currently on leave.')
    expect(questions).toContain('Show pending appraisals for my team.')
    // leave:manage includes the approval queue in the Phase 2 capability
    // matrix (HR manage the leave workflow), so the approval prompt shows.
    expect(questions).toContain('Which leave applications need my approval?')
  })

  it('adds the team-attendance prompt for attendance dashboard holders', () => {
    const permissions = ['attendance:view']
    const questions = buildSuggestedQuestions({ id: 5, permissions }, canFor(permissions))
    expect(questions).toContain('Summarize my team\u2019s attendance this month.')
  })

  it('caps the list at six prompts and never duplicates one', () => {
    const permissions = [
      'leave:view',
      'leave:approve',
      'leave:manage',
      'employee:view',
      'performance:view',
      'attendance:view',
    ]
    const user = { id: 1, permissions, active_delegations: [{ id: 1 }] }
    const questions = buildSuggestedQuestions(user, canFor(permissions))
    expect(questions.length).toBeLessThanOrEqual(6)
    expect(new Set(questions).size).toBe(questions.length)
  })

  it('is resilient to a missing can() and a user without permissions', () => {
    const questions = buildSuggestedQuestions({ id: 3 }, undefined)
    expect(questions).toContain('What is my remaining leave balance?')
    expect(questions).not.toContain('Which leave applications need my approval?')
  })
})