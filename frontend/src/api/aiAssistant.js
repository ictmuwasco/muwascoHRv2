/**
 * api/aiAssistant.js — AI assistant API service (Phase 6).
 *
 * Wraps the shared fetch client (utils/api) which already provides:
 * httpOnly-cookie credentials, silent /auth/refresh + replay on 401,
 * X-Request-ID correlation and network-failure reporting. AI endpoints do
 * NOT exist yet (Phase 6 backend pending) — until then every call resolves
 * to the describeChatError() paths and the widget shows a friendly
 * "unavailable" state. The backend independently authorises every answer;
 * this layer is transport only and never decides permissions.
 *
 * Endpoint contract (agreed for Phase 6 backend):
 *   POST /ai/chat                       { conversation_id?, message }
 *                                       → { conversation_id, message{ id, role, content, sources, tools_used } }
 *   GET  /ai/conversations/{id}         → { id, messages: [...] }
 *   POST /ai/conversations/{id}/clear   → {}
 *   POST /ai/feedback                   { message_id, rating: 'up'|'down', comment? }
 */
import api from '../utils/api'

/** AI generations can legitimately take longer than ordinary pages. */
const CHAT_TIMEOUT_MS = 60000

/** Mirrors the server-side AI_MAX_REQUEST_CHARS guard (config/ai.php). */
export const MAX_CHAT_MESSAGE_LENGTH = 2000

const unwrap = (response) => response?.data?.data ?? response?.data ?? {}

export async function sendChatMessage({ conversationId, message, signal }) {
  const payload = { message: String(message ?? '').trim() }
  if (conversationId) payload.conversation_id = conversationId
  const response = await api.post('/ai/chat', payload, {
    signal,
    timeout: CHAT_TIMEOUT_MS,
  })
  const data = unwrap(response)
  return {
    conversationId: data?.conversation_id ?? null,
    message: data?.message ?? null,
  }
}

export async function fetchConversation(conversationId) {
  const response = await api.get(`/ai/conversations/${encodeURIComponent(conversationId)}`)
  return unwrap(response)
}

export async function clearConversation(conversationId) {
  const response = await api.post(`/ai/conversations/${encodeURIComponent(conversationId)}/clear`)
  return unwrap(response)
}

export async function sendFeedback({ messageId, rating, comment = null }) {
  const response = await api.post('/ai/feedback', {
    message_id: messageId,
    rating,
    comment,
  })
  return unwrap(response)
}

/**
 * Map any thrown error from the calls above into safe, user-facing copy.
 * Never leaks provider names, keys, stack traces or server internals.
 */
export function describeChatError(error) {
  const status = error?.response?.status
  if (error?.isAuthError || status === 401) {
    return 'Your session has expired. Please sign in again.'
  }
  if (status === 403) {
    return 'You are not authorised to use the AI assistant. Contact HR if you believe this is a mistake.'
  }
  if (status === 404) {
    return 'The AI assistant is not available yet on this deployment.'
  }
  if (status === 422) {
    return 'That question could not be processed. Please try rephrasing it.'
  }
  if (status === 429) {
    return 'You are sending questions too quickly. Please wait a moment and try again.'
  }
  if (status === 503 || status === 502 || status === 504) {
    return 'The AI assistant is unavailable right now. Please try again shortly.'
  }
  if (error?.isTimeout) {
    return 'The assistant took too long to respond. Please try again.'
  }
  if (!error?.response) {
    return 'Cannot reach the server. Check your connection and try again.'
  }
  const serverMessage = error?.response?.data?.error || error?.response?.data?.message
  return typeof serverMessage === 'string' && serverMessage
    ? serverMessage
    : 'Something went wrong while contacting the assistant. Please try again.'
}