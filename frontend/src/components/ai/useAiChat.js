/**
 * useAiChat.js — state machine for the AI assistant conversation (Phase 6).
 *
 * Owns: messages, in-flight request, stop-generation, retry-after-error,
 * conversation restore, clear-conversation and per-message feedback.
 *
 * SECURITY: message bodies live in React state (memory) only. Only the
 * opaque conversation id is persisted (sessionStorage, cleared with the
 * tab) so a shared/borrowed machine never leaks HR content from storage.
 * Every answer's authorization is enforced server-side; this hook is UX only.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useAuth } from '../../context/AuthContext'
import {
  sendChatMessage,
  fetchConversation,
  clearConversation,
  sendFeedback,
  describeChatError,
} from '../../api/aiAssistant'
import { buildSuggestedQuestions } from './suggestedQuestions'

// sessionStorage is per-TAB, not per-account: an account switch in the same
// tab must never probe the PREVIOUS account's conversation id. The server
// would correctly answer 404 for a foreign id (owner scoping — ids cannot be
// enumerated), but the client should not send it at all. Keys are therefore
// scoped by user id; the unscoped legacy key is migrated once in initialize()
// and then removed.
const storageKey = (userId) => `muwasco_ai_conversation_id_${Number(userId) || 'anon'}`
const LEGACY_STORAGE_KEY = 'muwasco_ai_conversation_id'

const WELCOME_MESSAGE = {
  id: 'welcome',
  serverId: null,
  role: 'assistant',
  content:
    'Hello! I am the MUWASCO HR assistant. I can answer questions about your leave, ' +
    'attendance, approvals and HR policies using only information you are authorised to see.\n\n' +
    'AI-generated answers may contain mistakes — verify important information before acting on it.',
  sources: [],
  stopped: false,
  isWelcome: true,
}

/** Normalise the server's sources array (strings or {type,label} objects). */
const normalizeSources = (raw) => {
  if (!Array.isArray(raw)) return []
  return raw
    .map((source) => {
      if (typeof source === 'string') return { type: 'data', label: source }
      if (source && typeof source === 'object') {
        return {
          type: String(source.type ?? 'data'),
          label: String(source.label ?? source.name ?? source.title ?? 'source'),
        }
      }
      return null
    })
    .filter(Boolean)
}

/** Normalise a server message (history or live reply) into view state. */
const normalizeHistoryMessage = (raw) => {
  if (!raw || typeof raw !== 'object') return null
  const content = String(raw.content ?? '').trim()
  if (!content) return null
  const role = raw.role === 'user' ? 'user' : 'assistant'
  return {
    id: raw.id != null ? `srv-${raw.id}` : `srv-${Math.random().toString(36).slice(2)}`,
    serverId: raw.id ?? null,
    role,
    content,
    sources: role === 'assistant' ? normalizeSources(raw.sources) : [],
    stopped: false,
    isWelcome: false,
  }
}

const useAiChat = () => {
  const { user, can } = useAuth()
  const [messages, setMessages] = useState([WELCOME_MESSAGE])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [lastFailedQuestion, setLastFailedQuestion] = useState(null)
  const [feedback, setFeedback] = useState({})

  const conversationIdRef = useRef(null)
  const abortRef = useRef(null)
  const stopRequestedRef = useRef(false)
  const initializedRef = useRef(false)
  const loadingRef = useRef(false)
  const mountedRef = useRef(true)

  // Track mount state. IMPORTANT: the setup must RE-ARM `mountedRef` — under
  // React StrictMode (development) effects run setup -> cleanup -> setup, and
  // without re-arming, the flag stayed false for the component's whole life:
  // completed AI replies were discarded (`if (!mountedRef.current) return`)
  // and the typing indicator never cleared (`if (mountedRef.current)
  // setLoading(false)`), which looked exactly like "the assistant is loading
  // forever" while the backend answered perfectly every time.
  useEffect(() => {
    mountedRef.current = true
    return () => {
      mountedRef.current = false
      abortRef.current?.abort()
    }
  }, [])

  /** Restore an existing conversation (id from sessionStorage) once. */
  const initialize = useCallback(async () => {
    if (initializedRef.current) return
    initializedRef.current = true
    const key = storageKey(user?.id)
    let storedId = null
    try {
      storedId = sessionStorage.getItem(key)
      if (!storedId) {
        // One-time migration from the pre-user-scoped key. A foreign-owner id
        // is harmless here: the 404 recovery below starts a fresh chat.
        storedId = sessionStorage.getItem(LEGACY_STORAGE_KEY)
        if (storedId) sessionStorage.setItem(key, storedId)
      }
      sessionStorage.removeItem(LEGACY_STORAGE_KEY)
    } catch {
      storedId = null
    }
    if (!storedId) return
    conversationIdRef.current = storedId
    try {
      const conversation = await fetchConversation(storedId)
      if (!mountedRef.current) return
      const history = (Array.isArray(conversation?.messages) ? conversation.messages : [])
        .map(normalizeHistoryMessage)
        .filter(Boolean)
      if (history.length > 0) setMessages([WELCOME_MESSAGE, ...history])
    } catch (err) {
      if (err?.response?.status === 404) {
        // Server no longer knows this conversation (expired/retention/other
        // account) — start fresh.
        conversationIdRef.current = null
        try {
          sessionStorage.removeItem(key)
        } catch {
          /* storage unavailable */
        }
      }
      // Other failures start a fresh view silently; server retention applies.
    }
  }, [user?.id])

  const send = useCallback(async (rawText) => {
    const text = String(rawText ?? '').trim()
    if (!text || loadingRef.current) return
    if (text.length > 2000) return // server-side guard mirror; input is capped anyway
    loadingRef.current = true
    setError(null)
    setLastFailedQuestion(null)
    setMessages((prev) => [
      ...prev,
      {
        id: `local-u-${Date.now()}`,
        serverId: null,
        role: 'user',
        content: text,
        sources: [],
        stopped: false,
        isWelcome: false,
      },
    ])
    setLoading(true)
    stopRequestedRef.current = false
    const controller = new AbortController()
    abortRef.current = controller
    try {
      const result = await sendChatMessage({
        conversationId: conversationIdRef.current,
        message: text,
        signal: controller.signal,
      })
      if (!mountedRef.current) return
      if (result?.conversationId) {
        conversationIdRef.current = result.conversationId
        try {
          sessionStorage.setItem(storageKey(user?.id), String(result.conversationId))
        } catch {
          /* storage unavailable — id stays in memory for this tab only */
        }
      }
      const reply =
        normalizeHistoryMessage(result?.message) ?? {
          id: `local-a-${Date.now()}`,
          serverId: null,
          role: 'assistant',
          content: 'The assistant returned an empty response. Please try again.',
          sources: [],
          stopped: false,
          isWelcome: false,
        }
      setMessages((prev) => [...prev, reply])
    } catch (err) {
      if (!mountedRef.current) return
      if (stopRequestedRef.current) {
        setMessages((prev) => [
          ...prev,
          {
            id: `local-s-${Date.now()}`,
            serverId: null,
            role: 'assistant',
            content: 'Generation stopped. Ask another question or try again.',
            sources: [],
            stopped: true,
            isWelcome: false,
          },
        ])
      } else {
        setError(describeChatError(err))
        setLastFailedQuestion(text)
      }
    } finally {
      loadingRef.current = false
      abortRef.current = null
      if (mountedRef.current) setLoading(false)
    }
  }, [user?.id])

  const stop = useCallback(() => {
    if (!abortRef.current) return
    stopRequestedRef.current = true
    abortRef.current.abort()
  }, [])

  const retry = useCallback(() => {
    if (lastFailedQuestion) send(lastFailedQuestion)
  }, [lastFailedQuestion, send])

  const clearError = useCallback(() => {
    setError(null)
    setLastFailedQuestion(null)
  }, [])

  /** Clear the conversation locally (always) and server-side (best effort). */
  const reset = useCallback(async () => {
    const previousId = conversationIdRef.current
    conversationIdRef.current = null
    try {
      sessionStorage.removeItem(storageKey(user?.id))
    } catch {
      /* storage unavailable */
    }
    stopRequestedRef.current = false
    setError(null)
    setLastFailedQuestion(null)
    setFeedback({})
    setMessages([WELCOME_MESSAGE])
    if (previousId) {
      try {
        await clearConversation(previousId)
      } catch {
        /* local view already reset; server history follows retention policy */
      }
    }
  }, [user?.id])

  /** Optimistic thumbs feedback on an assistant message; reverts on failure. */
  const submitFeedback = useCallback(async (message, rating) => {
    if (!message || message.serverId == null) return false
    if (rating !== 'up' && rating !== 'down') return false
    setFeedback((prev) => ({ ...prev, [message.id]: rating }))
    try {
      await sendFeedback({ messageId: message.serverId, rating })
      return true
    } catch {
      if (!mountedRef.current) return false
      setFeedback((prev) => {
        const next = { ...prev }
        delete next[message.id]
        return next
      })
      return false
    }
  }, [])

  const getFeedbackRating = useCallback(
    (message) => feedback[message?.id] ?? null,
    [feedback]
  )

  const suggestions = useMemo(() => buildSuggestedQuestions(user, can), [user, can])

  return {
    messages,
    loading,
    error,
    suggestions,
    canRetry: Boolean(lastFailedQuestion),
    initialize,
    send,
    stop,
    retry,
    reset,
    clearError,
    submitFeedback,
    getFeedbackRating,
  }
}

export default useAiChat
