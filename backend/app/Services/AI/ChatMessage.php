<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Contracts\MessageInterface;

/**
 * ChatMessage — immutable canonical chat message value object.
 *
 * Immutable by design: message history is appended, never mutated, so the
 * conversation loop (Phase 6) and providers always see a consistent snapshot.
 * Content is EXPECTED to be plain, already-sanitized text; no HTML is ever
 * rendered from it client-side.
 */
final class ChatMessage implements MessageInterface
{
    private function __construct(
        private string $role,
        private string $content,
        private ?array $toolCall = null,
        private ?string $toolCallId = null
    ) {
    }

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    /** Assistant message carrying a provider/plan-level tool call proposal. */
    public static function assistantWithToolCall(string $content, string $name, string $arguments, string $id = ''): self
    {
        return new self('assistant', $content, [
            'id'        => $id,
            'name'      => $name,
            'arguments' => $arguments,
        ]);
    }

    /** Result of a tool invocation the loop feeds back to the provider. */
    public static function tool(string $toolCallId, string $content): self
    {
        return new self('tool', $content, null, $toolCallId);
    }

    public function role(): string
    {
        return $this->role;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function toolCall(): ?array
    {
        return $this->toolCall;
    }

    public function toolCallId(): ?string
    {
        return $this->toolCallId;
    }

    public function toArray(): array
    {
        return [
            'role'    => $this->role,
            'content' => $this->content,
            'tool_call'    => $this->toolCall,
            'tool_call_id' => $this->toolCallId,
        ];
    }
}
