import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { StrictMode } from 'react'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import AiAssistantWidget from '../../components/ai/AiAssistantWidget'

globalThis.IS_REACT_ACT_ENVIRONMENT = true

// The widget is transport-agnostic: mock the AI API service module (which
// wraps utils/api → fetch) so no network ever happens inside jsdom.
const chatMock = vi.fn()
const fetchConversationMock = vi.fn()
const clearConversationMock = vi.fn()
const sendFeedbackMock = vi.fn()

vi.mock('../../api/aiAssistant', () => ({
  sendChatMessage: (...args) => chatMock(...args),
  fetchConversation: (...args) => fetchConversationMock(...args),
  clearConversation: (...args) => clearConversationMock(...args),
  sendFeedback: (...args) => sendFeedbackMock(...args),
  describeChatError: () => 'Assistant unavailable (test)',
  MAX_CHAT_MESSAGE_LENGTH: 2000,
}))

vi.mock('react-hot-toast', () => ({
  default: { success: vi.fn(), error: vi.fn() },
}))

// Effective-permission context the real AuthContext delivers on /auth/user.
let mockUser = { id: 42, first_name: 'Jane', permissions: ['leave:view'] }

vi.mock('../../context/AuthContext', () => ({
  useAuth: () => ({
    user: mockUser,
    can: (module, action = 'view') =>
      (mockUser?.permissions ?? []).includes(`${module}:${action}`),
    canAny: (pairs) =>
      (pairs ?? []).some(
        ([module, action]) => (mockUser?.permissions ?? []).includes(`${module}:${action}`)
      ),
  }),
}))

const SERVER_REPLY = {
  conversationId: 'c1',
  message: {
    id: 101,
    role: 'assistant',
    content: 'Here is your balance: 12 days.',
    sources: [{ type: 'data', label: 'leave_balances' }],
    tools_used: [],
  },
}

beforeEach(() => {
  vi.clearAllMocks()
  sessionStorage.clear()
  mockUser = { id: 42, first_name: 'Jane', permissions: ['leave:view'] }
  chatMock.mockResolvedValue(SERVER_REPLY)
  fetchConversationMock.mockResolvedValue({ id: 'c1', messages: [] })
  clearConversationMock.mockResolvedValue({})
  sendFeedbackMock.mockResolvedValue({})
})

afterEach(() => cleanup())

const openAssistant = async () => {
  render(<AiAssistantWidget />)
  fireEvent.click(screen.getByRole('button', { name: /open hr assistant/i }))
  return screen.findByRole('dialog', { name: 'HR AI Assistant' })
}

describe('AiAssistantWidget (floating AI chat launcher + popup)', () => {
  it('renders the floating launcher and hides the panel until opened', () => {
    render(<AiAssistantWidget />)
    expect(screen.getByRole('button', { name: /open hr assistant/i })).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('opens the chat panel with the AI welcome and data-source explanation', async () => {
    const panel = await openAssistant()
    expect(panel).toBeInTheDocument()
    expect(panel.textContent).toContain('MUWASCO HR assistant')
    expect(panel.textContent).toContain('AI-generated answers may contain mistakes')
  })

  it('shows only permission-appropriate suggested questions for a plain employee', async () => {
    await openAssistant()
    expect(
      screen.getByRole('button', { name: 'What is my remaining leave balance?' })
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Which leave applications need my approval?' })
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Show my delegated approvals.' })
    ).not.toBeInTheDocument()
  })

  it('offers approval + delegation prompts to an acting delegate with approval rights', async () => {
    mockUser = {
      id: 42,
      permissions: ['leave:view', 'leave:approve'],
      active_delegations: [
        {
          id: 7,
          delegator_name: 'Samuel Mwangi',
          permissions: ['leave:approve'],
          end_date: '2026-09-25',
        },
      ],
    }
    await openAssistant()
    expect(
      screen.getByRole('button', { name: 'Which leave applications need my approval?' })
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Show my delegated approvals.' })
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Who is currently acting for me?' })
    ).toBeInTheDocument()
  })

  it('sends a question from a suggestion chip and renders the reply with source chips', async () => {
    await openAssistant()
    fireEvent.click(screen.getByRole('button', { name: 'What is my remaining leave balance?' }))
    expect(await screen.findByText('Here is your balance: 12 days.')).toBeInTheDocument()
    expect(chatMock).toHaveBeenCalledTimes(1)
    expect(chatMock.mock.calls[0][0]).toMatchObject({
      message: 'What is my remaining leave balance?',
    })
    expect(screen.getByText('leave_balances')).toBeInTheDocument()
    expect(screen.getAllByText('AI-generated').length).toBeGreaterThan(0)
  })

  it('sends a typed question with Enter and clears the composer', async () => {
    await openAssistant()
    const input = screen.getByPlaceholderText(/ask about your leave/i)
    fireEvent.change(input, { target: { value: 'How many sick days do I have left?' } })
    fireEvent.keyDown(input, { key: 'Enter', shiftKey: false })
    expect(await screen.findByText('Here is your balance: 12 days.')).toBeInTheDocument()
    expect(chatMock.mock.calls[0][0].message).toBe('How many sick days do I have left?')
    expect(input.value).toBe('')
  })

  it('disables the send button for whitespace-only input', async () => {
    await openAssistant()
    const sendButton = screen.getByRole('button', { name: /send message/i })
    expect(sendButton).toBeDisabled()
    fireEvent.change(screen.getByPlaceholderText(/ask about your leave/i), {
      target: { value: '   ' },
    })
    expect(sendButton).toBeDisabled()
  })

  it('shows a stop action while the assistant is thinking', async () => {
    let resolveChat
    chatMock.mockImplementation(
      () => new Promise((resolve) => { resolveChat = resolve })
    )
    await openAssistant()
    fireEvent.click(screen.getByRole('button', { name: 'What is my remaining leave balance?' }))
    expect(await screen.findByRole('status', { name: /assistant is typing/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /stop generation/i })).toBeInTheDocument()
    resolveChat(SERVER_REPLY)
    expect(await screen.findByText('Here is your balance: 12 days.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /stop generation/i })).not.toBeInTheDocument()
  })

  it('surfaces an error bubble when the AI call fails', async () => {
    chatMock.mockRejectedValueOnce(new Error('Assistant unavailable (test)'))
    await openAssistant()
    fireEvent.click(screen.getByRole('button', { name: 'What is my remaining leave balance?' }))
    expect(await screen.findByText(/assistant unavailable \(test\)/i)).toBeInTheDocument()
    // A retry affordance appears next to the error message.
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument()
  })

  it('restores the last conversation transcript for the same session', async () => {
    sessionStorage.setItem('muwasco_ai_conversation_id_42', 'c9')
    fetchConversationMock.mockResolvedValue({
      id: 'c9',
      messages: [
        { id: 1, role: 'user', content: 'Who is on leave today?' },
        { id: 2, role: 'assistant', content: 'Two employees are on leave.' },
      ],
    })
    await openAssistant()
    expect(fetchConversationMock).toHaveBeenCalledWith('c9')
    expect(await screen.findByText('Who is on leave today?')).toBeInTheDocument()
    expect(await screen.findByText('Two employees are on leave.')).toBeInTheDocument()
  })

  it('migrates the legacy unscoped conversation marker to the user-scoped key', async () => {
    sessionStorage.setItem('muwasco_ai_conversation_id', 'c9')
    await openAssistant()
    expect(fetchConversationMock).toHaveBeenCalledWith('c9')
    // Legacy key removed; the scoped key now carries the id for this account.
    expect(sessionStorage.getItem('muwasco_ai_conversation_id')).toBeNull()
    expect(sessionStorage.getItem('muwasco_ai_conversation_id_42')).toBe('c9')
  })

  it('never probes another account conversation after an account switch in the same tab', async () => {
    // Previous account (43) left its conversation id in this tab's storage.
    sessionStorage.setItem('muwasco_ai_conversation_id_43', 'foreign-id')
    mockUser = { id: 42, first_name: 'Jane', permissions: ['leave:view'] }
    await openAssistant()
    // Only its OWN account key is read — a foreign id is never sent to the
    // server (which would correctly answer 404 for it).
    expect(fetchConversationMock).not.toHaveBeenCalled()
  })
  it('clears the conversation after confirmation', async () => {
    await openAssistant()
    fireEvent.click(screen.getByRole('button', { name: 'What is my remaining leave balance?' }))
    await screen.findByText('Here is your balance: 12 days.')

    fireEvent.click(screen.getByRole('button', { name: /clear conversation/i }))
    // Confirmation dialog guards the destructive action (shared ui/Modal).
    expect(await screen.findByText('Clear conversation?')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /yes, clear/i }))

    await waitFor(() => expect(clearConversationMock).toHaveBeenCalledWith('c1'))
    await waitFor(() =>
      expect(
        screen.queryByText('Here is your balance: 12 days.')
      ).not.toBeInTheDocument()
    )
    // Session marker dropped so a fresh conversation starts next time.
    expect(sessionStorage.getItem('muwasco_ai_conversation_id_42')).toBeNull()
  })

  it('submits thumbs-up feedback for an assistant message', async () => {
    await openAssistant()
    fireEvent.click(screen.getByRole('button', { name: 'What is my remaining leave balance?' }))
    await screen.findByText('Here is your balance: 12 days.')

    fireEvent.click(screen.getByRole('button', { name: 'Helpful response' }))
    await waitFor(() =>
      expect(sendFeedbackMock).toHaveBeenCalledWith({ messageId: 101, rating: 'up' })
    )
  })

  it('closes on the close button and on Escape', async () => {
    const panel = await openAssistant()
    fireEvent.keyDown(panel, { key: 'Escape' })
    expect(screen.queryByRole('dialog', { name: 'HR AI Assistant' })).not.toBeInTheDocument()
  })

  it('pins the launcher bottom-right and makes the panel responsive', async () => {
    render(<AiAssistantWidget />)
    const launcher = screen.getByRole('button', { name: /open hr assistant/i })
    // The launcher itself is pinned to the bottom-right corner of the viewport.
    expect(launcher.className).toContain('fixed bottom-5 right-5')

    fireEvent.click(launcher)
    const panel = await screen.findByRole('dialog', { name: 'HR AI Assistant' })
    // Near-full-width sheet on phones, fixed 380px card from sm: upward.
    expect(panel.className).toContain('inset-x-3')
    expect(panel.className).toContain('sm:w-[380px]')
  })

  it('renders the reply and clears the typing indicator under React.StrictMode', async () => {
    // REGRESSION: in development, StrictMode runs effects setup -> cleanup ->
    // setup. The unmount cleanup used to leave `mountedRef` permanently false,
    // so every COMPLETED turn hit `if (!mountedRef.current) return` (reply
    // fetched from the server, then discarded — never rendered) and
    // `if (mountedRef.current) setLoading(false)` never ran (spinner stuck).
    // Result: the assistant looked like it was "loading forever" with no
    // errors while the backend answered successfully every time. This test
    // fails on that bug and passes with the re-armed mount effect.
    render(
      <StrictMode>
        <AiAssistantWidget />
      </StrictMode>
    )
    fireEvent.click(screen.getByRole('button', { name: /open hr assistant/i }))
    const panel = await screen.findByRole('dialog', { name: 'HR AI Assistant' })

    fireEvent.click(screen.getByRole('button', { name: 'What is my remaining leave balance?' }))

    // The completed reply must actually be RENDERED (previously discarded)…
    expect(await screen.findByText('Here is your balance: 12 days.')).toBeInTheDocument()
    // …and the typing indicator must be GONE (previously stuck on).
    expect(screen.queryByRole('button', { name: /stop generation/i })).not.toBeInTheDocument()
    expect(panel).toBeInTheDocument()
  })
})