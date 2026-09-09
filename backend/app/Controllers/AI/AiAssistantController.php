<?php

declare(strict_types=1);

namespace App\Controllers\AI;

use App\Controllers\BaseController;
use App\Services\AI\AiConversationService;
use App\Services\AI\AiRequestException;

/**
 * AiAssistantController — HTTP boundary for the Phase 6 AI assistant widget.
 *
 * Deliberately thin: resolve the authenticated user, validate input SHAPE,
 * delegate all business logic / authorization / persistence to
 * AiConversationService, and translate typed exceptions into the standardized
 * ApiResponse envelope. Error messages are user-safe — never provider names,
 * model details or stack traces. Route methods accept variadic arguments so
 * they remain correct regardless of how the router forwards {id} placeholders.
 *
 * Routes (api.php):
 *   POST /ai/chat                      -> chat()
 *   GET  /ai/conversations/{id}        -> show()
 *   POST /ai/conversations/{id}/clear  -> clear()
 *   POST /ai/feedback                  -> feedback()
 */
final class AiAssistantController extends BaseController
{
    /** POST /ai/chat — one assistant turn. Body: { message, conversation_id? } */
    public function chat(...$routeArgs): void
    {
        $userId = $this->getUserId();
        if ($userId <= 0) {
            $this->unauthorized('Authentication required');
        }

        $body = $this->getJsonBody() ?? [];
        $message = trim((string) ($body['message'] ?? ''));
        $conversationId = trim((string) ($body['conversation_id'] ?? ''));

        if ($conversationId !== '' && preg_match('/^[0-9a-fA-F-]{1,36}$/', $conversationId) !== 1) {
            $this->error('Invalid conversation id.', 422, 'VALIDATION_ERROR');
        }

        try {
            $data = AiConversationService::getInstance()->ask($userId, $message, $conversationId);
            $this->success($data, 'OK');
        } catch (AiRequestException $e) {
            $this->mapRequestException($e);
        } catch (\Throwable $e) {
            \logger()->error('AI chat turn failed', ['error' => $e->getMessage()]);
            $this->error(
                'The AI assistant is unavailable right now. Please try again shortly.',
                503,
                'AI_UNAVAILABLE'
            );
        }
    }

    /** GET /ai/conversations/{id} — restore transcript (owner-scoped). */
    public function show(...$routeArgs): void
    {
        $userId = $this->getUserId();
        if ($userId <= 0) {
            $this->unauthorized('Authentication required');
        }

        $conversationId = $this->routeId($routeArgs);
        if ($conversationId === '') {
            $this->notFound('Conversation not found');
        }

        try {
            $data = AiConversationService::getInstance()->history($userId, $conversationId);
            $this->success($data, 'OK');
        } catch (AiRequestException $e) {
            $this->mapRequestException($e);
        } catch (\Throwable $e) {
            \logger()->error('AI conversation restore failed', ['error' => $e->getMessage()]);
            $this->error('Could not load the conversation. Please try again.', 500, 'AI_HISTORY_ERROR');
        }
    }

    /** POST /ai/conversations/{id}/clear — delete the transcript (owner-scoped). */
    public function clear(...$routeArgs): void
    {
        $userId = $this->getUserId();
        if ($userId <= 0) {
            $this->unauthorized('Authentication required');
        }

        $conversationId = $this->routeId($routeArgs);
        if ($conversationId === '') {
            $this->notFound('Conversation not found');
        }

        try {
            AiConversationService::getInstance()->clear($userId, $conversationId);
            $this->success([], 'Conversation cleared');
        } catch (AiRequestException $e) {
            $this->mapRequestException($e);
        } catch (\Throwable $e) {
            \logger()->error('AI conversation clear failed', ['error' => $e->getMessage()]);
            $this->error('Could not clear the conversation. Please try again.', 500, 'AI_CLEAR_ERROR');
        }
    }

    /** POST /ai/feedback — helpful / not-helpful on one of MY messages. */
    public function feedback(...$routeArgs): void
    {
        $userId = $this->getUserId();
        if ($userId <= 0) {
            $this->unauthorized('Authentication required');
        }

        $body = $this->getJsonBody() ?? [];
        $messageId = (int) ($body['message_id'] ?? 0);
        $rating = (string) ($body['rating'] ?? '');
        $comment = array_key_exists('comment', $body) && $body['comment'] !== null
            ? (string) $body['comment']
            : null;

        if ($messageId <= 0 || !in_array($rating, ['up', 'down'], true)) {
            $this->error('A valid message_id and rating (up|down) are required.', 422, 'VALIDATION_ERROR');
        }

        try {
            AiConversationService::getInstance()->feedback(
                $userId,
                $messageId,
                $rating === 'up' ? 'helpful' : 'not_helpful',
                $comment
            );
            $this->success([], 'Feedback recorded');
        } catch (AiRequestException $e) {
            $this->mapRequestException($e);
        } catch (\Throwable $e) {
            \logger()->error('AI feedback failed', ['error' => $e->getMessage()]);
            $this->error('Could not record feedback. Please try again.', 500, 'AI_FEEDBACK_ERROR');
        }
    }

    /**
     * Typed exception -> HTTP envelope. Each branch emits the exception's
     * fixed, user-safe message; error()/notFound() terminate the request.
     */
    private function mapRequestException(AiRequestException $e): void
    {
        switch ($e->getReason()) {
            case AiRequestException::REASON_EMPTY_MESSAGE:
                $this->error($e->getMessage(), 422, 'VALIDATION_ERROR');
                // no break — error() exits
            case AiRequestException::REASON_SAFETY_FLAGGED:
                $this->error($e->getMessage(), 422, 'SAFETY_FLAGGED');
            case AiRequestException::REASON_CONVERSATION_NOT_FOUND:
                $this->notFound($e->getMessage());
            case AiRequestException::REASON_PROVIDER_UNAVAILABLE:
                $this->error($e->getMessage(), 503, 'AI_UNAVAILABLE');
            default:
                $this->error('The assistant could not process that request.', 400, 'AI_REQUEST_ERROR');
        }
    }

    /**
     * Extract the {id} route parameter regardless of forwarding convention:
     * a bare string, a positional list, or an assoc map with an 'id' key.
     */
    private function routeId(array $routeArgs): string
    {
        foreach ($routeArgs as $arg) {
            if (is_string($arg) && $arg !== '') {
                return $arg;
            }
            if (is_array($arg)) {
                if (isset($arg['id']) && is_scalar($arg['id']) && (string) $arg['id'] !== '') {
                    return (string) $arg['id'];
                }
                foreach ($arg as $value) {
                    if (is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            }
        }
        return '';
    }
}
