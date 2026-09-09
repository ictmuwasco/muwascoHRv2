/**
 * suggestedQuestions.js — permission-aware starter prompts for the AI
 * assistant (Phase 6).
 *
 * SECURITY MODEL: these chips only PRE-FILL questions. They are filtered by
 * the effective permission strings delivered on /auth/user so a user is not
 * invited to ask something they could never be authorised for — but the
 * backend independently enforces the Phase 2 AI capability matrix on every
 * answer. Hiding a chip is UX; the API is the authority.
 */

export const buildSuggestedQuestions = (user, can) => {
  const questions = []

  // Self-service questions — available to every authenticated employee.
  questions.push('What is my remaining leave balance?')
  questions.push('Summarize my attendance this month.')
  questions.push('Explain the leave approval process.')

  const canAny = (pairs) =>
    typeof can === 'function' && pairs.some(([module, action]) => can(module, action))

  // Approvers / HR: the approval queue question.
  if (canAny([['leave', 'approve'], ['leave', 'manage']])) {
    questions.push('Which leave applications need my approval?')
  }

  // Delegates: prompts about the temporary authority itself (§27/§28).
  const delegations = Array.isArray(user?.active_delegations) ? user.active_delegations : []
  if (delegations.length > 0) {
    questions.push('Show my delegated approvals.')
    questions.push('Who is currently acting for me?')
  }

  // Leave administration: roster-level question.
  if (canAny([['leave', 'manage']])) {
    questions.push('Show employees currently on leave.')
  }

  // Appraisal visibility for heads / HR.
  if (canAny([['performance', 'view'], ['performance', 'manage']])) {
    questions.push('Show pending appraisals for my team.')
  }

  // Attendance dashboards for supervisors and above.
  if (canAny([['attendance', 'view'], ['attendance', 'manage']])) {
    questions.push('Summarize my team\u2019s attendance this month.')
  }

  // De-duplicate (defensive) and cap the chips so the panel stays tidy.
  return Array.from(new Set(questions)).slice(0, 6)
}

export default buildSuggestedQuestions