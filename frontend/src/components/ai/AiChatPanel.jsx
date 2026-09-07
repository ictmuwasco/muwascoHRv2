/**
 * AiChatPanel.jsx — the AI assistant popup (Phase 6).
 *
 * Presentational shell over useAiChat(): header with the AI-generated
 * notice, scrolling conversation log, typing indicator, permission-aware
 * suggested questions, error banner with retry, composer with stop
 * generation, and the data-source footnote. Responsive: near-full-width
 * sheet on phones, fixed 380px card on larger screens; dark-mode aware.
 */
import { useState } from 'react'
import {
  Bot,
  X,
  Trash2,
  Send,
  Square,
  RotateCcw,
  ShieldCheck,
  AlertCircle,
} from 'lucide-react'
import AiMessageBubble from './AiMessageBubble'
import { MAX_CHAT_MESSAGE_LENGTH } from '../../api/aiAssistant'

const AiChatPanel = ({ chat, inputRef, onClose, onRequestClear }) => {
  const [input, setInput] = useState('')
  const { messages, loading, error, suggestions } = chat

  const showSuggestions = messages.length === 1 && !loading && !error

  const submit = () => {
    const text = input.trim()
    if (!text || loading) return
    setInput('')
    chat.send(text)
  }

  const handleKeyDown = (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault()
      submit()
    }
  }

  return (
    <div
      id="ai-assistant-panel"
      role="dialog"
      aria-label="HR AI Assistant"
      className="fixed inset-x-3 bottom-20 z-40 flex h-[min(72vh,600px)] flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900 sm:inset-x-auto sm:right-6 sm:bottom-24 sm:h-[560px] sm:w-[380px]"
    >
      {/* Header */}
      <div className="flex items-center gap-2 bg-primary-600 px-4 py-3 text-white">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/15">
          <Bot className="h-5 w-5" aria-hidden="true" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold leading-tight">HR Assistant</p>
          <p className="truncate text-[11px] leading-tight text-white/80">
            AI-generated answers — verify important information
          </p>
        </div>
        <button
          type="button"
          onClick={onRequestClear}
          disabled={messages.length <= 1}
          aria-label="Clear conversation"
          title="Clear conversation"
          className="rounded-md p-1.5 text-white/80 transition-colors hover:bg-white/10 hover:text-white disabled:pointer-events-none disabled:opacity-40"
        >
          <Trash2 className="h-4 w-4" aria-hidden="true" />
        </button>
        <button
          type="button"
          onClick={onClose}
          aria-label="Close assistant"
          title="Close"
          className="rounded-md p-1.5 text-white/80 transition-colors hover:bg-white/10 hover:text-white"
        >
          <X className="h-5 w-5" aria-hidden="true" />
        </button>
      </div>

      {/* Conversation log */}
      <div
        className="flex-1 space-y-3 overflow-y-auto bg-gray-50 px-3 py-3 dark:bg-slate-950/40"
        role="log"
        aria-live="polite"
        aria-label="Conversation"
      >
        {messages.map((message) => (
          <AiMessageBubble
            key={message.id}
            message={message}
            feedbackRating={chat.getFeedbackRating(message)}
            onFeedback={chat.submitFeedback}
          />
        ))}
        {loading && (
          <div className="flex justify-start" role="status" aria-label="Assistant is typing">
            <div className="rounded-2xl rounded-bl-sm border border-gray-200 bg-white px-3 py-2.5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
              <span className="flex items-center gap-1">
                {[0, 150, 300].map((delay) => (
                  <span
                    key={delay}
                    className="h-1.5 w-1.5 animate-bounce rounded-full bg-primary-500"
                    style={{ animationDelay: `${delay}ms` }}
                  />
                ))}
              </span>
            </div>
          </div>
        )}
      </div>

      {/* Error banner with retry */}
      {error && (
        <div
          className="mx-3 mb-2 flex items-start gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300"
          role="alert"
        >
          <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          <p className="flex-1 break-words">{error}</p>
          {chat.canRetry && (
            <button
              type="button"
              onClick={chat.retry}
              className="inline-flex items-center gap-1 rounded-md border border-red-300 px-2 py-0.5 font-medium transition-colors hover:bg-red-100 dark:border-red-500/40 dark:hover:bg-red-500/20"
            >
              <RotateCcw className="h-3 w-3" aria-hidden="true" />
              Retry
            </button>
          )}
          <button
            type="button"
            onClick={chat.clearError}
            aria-label="Dismiss error"
            className="rounded p-0.5 transition-colors hover:bg-red-100 dark:hover:bg-red-500/20"
          >
            <X className="h-3.5 w-3.5" aria-hidden="true" />
          </button>
        </div>
      )}

      {/* Permission-aware suggested questions (before the first user question) */}
      {showSuggestions && suggestions.length > 0 && (
        <div className="px-3 pb-2">
          <p className="mb-1.5 text-[11px] font-medium text-gray-500 dark:text-gray-400">
            Try asking
          </p>
          <div className="flex flex-wrap gap-1.5">
            {suggestions.map((question) => (
              <button
                key={question}
                type="button"
                onClick={() => chat.send(question)}
                className="rounded-full border border-gray-300 bg-white px-2.5 py-1 text-[11px] text-gray-700 transition-colors hover:border-primary-500 hover:text-primary-700 dark:border-slate-600 dark:bg-slate-800 dark:text-gray-200 dark:hover:text-primary-600"
              >
                {question}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Composer */}
      <div className="border-t border-gray-200 p-2 dark:border-slate-700">
        <div className="flex items-end gap-2">
          <div className="flex-1">
            <label htmlFor="ai-assistant-input" className="sr-only">
              Ask the HR assistant
            </label>
            <input
              id="ai-assistant-input"
              ref={inputRef}
              type="text"
              value={input}
              maxLength={MAX_CHAT_MESSAGE_LENGTH}
              onChange={(event) => setInput(event.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="Ask about your leave, attendance or HR policy…"
              className="input h-9 text-sm"
              enterKeyHint="send"
              autoComplete="off"
            />
          </div>
          {loading ? (
            <button
              type="button"
              onClick={chat.stop}
              aria-label="Stop generation"
              title="Stop generation"
              className="btn h-9 w-9 shrink-0 rounded-full bg-gray-800 text-white hover:bg-gray-700 dark:bg-slate-200 dark:text-slate-900"
            >
              <Square className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          ) : (
            <button
              type="button"
              onClick={submit}
              disabled={!input.trim()}
              aria-label="Send message"
              title="Send message"
              className="btn h-9 w-9 shrink-0 rounded-full bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-40"
            >
              <Send className="h-4 w-4" aria-hidden="true" />
            </button>
          )}
        </div>
        {input.length > MAX_CHAT_MESSAGE_LENGTH * 0.8 && (
          <p className="mt-1 text-right text-[10px] text-gray-400">
            {input.length}/{MAX_CHAT_MESSAGE_LENGTH}
          </p>
        )}
        <p className="mt-1 flex items-center gap-1 text-[10px] text-gray-400 dark:text-gray-500">
          <ShieldCheck className="h-3 w-3 shrink-0" aria-hidden="true" />
          Uses only your authorised HR data and approved HR policies. Read-only — it cannot change records.
        </p>
      </div>
    </div>
  )
}

export default AiChatPanel