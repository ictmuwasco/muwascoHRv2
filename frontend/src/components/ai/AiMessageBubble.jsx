/**
 * AiMessageBubble.jsx — one chat message in the AI assistant panel (Phase 6).
 *
 * User messages render right-aligned; assistant messages render a card with
 * the mandatory "AI-generated" indicator, optional source chips (so users can
 * see whether an answer came from HR data or an approved policy document) and
 * thumbs feedback once the message has a server-side id.
 */
import {
  Bot,
  User,
  ThumbsUp,
  ThumbsDown,
  Database,
  BookOpen,
  Info,
} from 'lucide-react'

const SOURCE_ICONS = { data: Database, policy: BookOpen }

const AiMessageBubble = ({ message, feedbackRating = null, onFeedback }) => {
  if (message.role === 'user') {
    return (
      <div className="flex justify-start" data-role="user">
        <div className="ml-auto max-w-[85%] rounded-2xl rounded-br-sm bg-primary-600 px-3 py-2 text-sm text-white shadow-sm">
          <p className="whitespace-pre-wrap break-words">{message.content}</p>
        </div>
      </div>
    )
  }

  const showFeedback =
    !message.isWelcome && message.serverId != null && typeof onFeedback === 'function'

  return (
    <div className="flex justify-start" data-role="assistant">
      <div className="w-[92%] max-w-full rounded-2xl rounded-bl-sm border border-gray-200 bg-white px-3 py-2 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <div className="mb-1 flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
          <Bot className="h-3 w-3" aria-hidden="true" />
          <span>AI-generated</span>
          {message.stopped && (
            <span className="ml-auto normal-case text-amber-600 dark:text-amber-400">stopped</span>
          )}
        </div>

        <p className="whitespace-pre-wrap break-words text-sm text-gray-800 dark:text-gray-100">
          {message.content}
        </p>

        {Array.isArray(message.sources) && message.sources.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-1" aria-label="Data sources">
            {message.sources.map((source, index) => {
              const Icon = SOURCE_ICONS[source.type] ?? Info
              return (
                <span
                  key={`${source.label}-${index}`}
                  className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-600 dark:bg-slate-700 dark:text-gray-300"
                >
                  <Icon className="h-3 w-3" aria-hidden="true" />
                  {source.label}
                </span>
              )
            })}
          </div>
        )}

        {showFeedback && (
          <div className="mt-2 flex items-center gap-1 border-t border-gray-100 pt-1.5 dark:border-slate-700">
            <span className="mr-1 flex items-center gap-1 text-[10px] text-gray-400 dark:text-gray-500">
              <User className="h-3 w-3" aria-hidden="true" />
              Was this helpful?
            </span>
            <button
              type="button"
              onClick={() => onFeedback(message, 'up')}
              aria-label="Helpful response"
              aria-pressed={feedbackRating === 'up'}
              className={`rounded-md p-1 transition-colors hover:bg-gray-100 dark:hover:bg-slate-700 ${
                feedbackRating === 'up'
                  ? 'text-primary-600 dark:text-primary-600'
                  : 'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300'
              }`}
            >
              <ThumbsUp className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button
              type="button"
              onClick={() => onFeedback(message, 'down')}
              aria-label="Not helpful response"
              aria-pressed={feedbackRating === 'down'}
              className={`rounded-md p-1 transition-colors hover:bg-gray-100 dark:hover:bg-slate-700 ${
                feedbackRating === 'down'
                  ? 'text-red-600 dark:text-red-400'
                  : 'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300'
              }`}
            >
              <ThumbsDown className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

export default AiMessageBubble