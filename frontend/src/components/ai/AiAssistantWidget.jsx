/**
 * AiAssistantWidget.jsx — floating AI assistant entry point (Phase 6).
 *
 * A launcher button pinned to the bottom-right of every authenticated page
 * (mounted once from Layout) that toggles the chat popup. Owns the
 * clear-conversation confirmation (reuses the shared ui/Modal design-system
 * primitive) and Escape-to-close; all chat behaviour lives in useAiChat().
 *
 * The assistant is READ-ONLY: it can answer and explain, never act. When
 * action preparation arrives (Phase 10) it MUST pass through this same
 * confirmation-dialog pattern and the existing backend workflow.
 */
import { useEffect, useRef, useState } from 'react'
import { MessageCircle, X } from 'lucide-react'
import toast from 'react-hot-toast'
import Modal from '../ui/Modal'
import Button from '../ui/Button'
import { useAuth } from '../../context/AuthContext'
import useAiChat from './useAiChat'
import AiChatPanel from './AiChatPanel'

const AiAssistantWidget = () => {
  const { user } = useAuth()
  const chat = useAiChat()
  const [open, setOpen] = useState(false)
  const [confirmClear, setConfirmClear] = useState(false)
  const inputRef = useRef(null)

  // Escape closes the panel (but not while the confirmation modal is open —
  // the modal owns Escape then).
  useEffect(() => {
    if (!open) return undefined
    const onKeyDown = (event) => {
      if (event.key === 'Escape' && !confirmClear) setOpen(false)
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [open, confirmClear])

  // Focus the composer when the panel opens.
  useEffect(() => {
    if (open) inputRef.current?.focus()
  }, [open])

  if (!user) return null

  const toggle = () => {
    const next = !open
    setOpen(next)
    if (next) chat.initialize()
  }

  const handleConfirmClear = async () => {
    setConfirmClear(false)
    await chat.reset()
    toast.success('Conversation cleared')
  }

  return (
    <>
      <button
        type="button"
        onClick={toggle}
        aria-expanded={open}
        aria-controls="ai-assistant-panel"
        aria-label={open ? 'Close HR Assistant' : 'Open HR Assistant'}
        className="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-primary-600 text-white shadow-lg transition-colors hover:bg-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
      >
        {open ? (
          <X className="h-6 w-6" aria-hidden="true" />
        ) : (
          <MessageCircle className="h-6 w-6" aria-hidden="true" />
        )}
        {!open && (
          <span
            className="absolute right-1 top-1 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-400"
            aria-hidden="true"
          />
        )}
      </button>

      {open && (
        <AiChatPanel
          chat={chat}
          inputRef={inputRef}
          onClose={() => setOpen(false)}
          onRequestClear={() => setConfirmClear(true)}
        />
      )}

      <Modal
        isOpen={confirmClear}
        onClose={() => setConfirmClear(false)}
        title="Clear conversation?"
        size="sm"
      >
        <p className="text-sm text-gray-600 dark:text-gray-300">
          This clears the current conversation from your assistant view. Server-side history is
          handled according to the AI data-retention policy.
        </p>
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setConfirmClear(false)}>
            Cancel
          </Button>
          <Button variant="danger" onClick={handleConfirmClear}>
            Yes, clear it
          </Button>
        </div>
      </Modal>
    </>
  )
}

export default AiAssistantWidget