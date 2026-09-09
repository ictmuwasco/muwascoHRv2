<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

/**
 * MessageInterface — a single canonical chat message exchanged with an AI
 * provider. Providers depend on this interface only, so swapping the model
 * backend never changes how conversation history is represented.
 */
interface MessageInterface
{
    /** Role: 'system' | 'user' | 'assistant' | 'tool'. */
    public function role(): string;

    /** Text content of the message (plain text, already sanitized upstream). */
    public function content(): string;

    /**
     * Optional provider-returned tool call the assistant produced. Keyed by
     * name + arguments-so-far; used by the tool-calling loop (Phase 5/6) and
     * passed through verbatim to providers that round-trip tool calls.
     *
     * @return array{id?: string, name: string, arguments: string}|null
     */
    public function toolCall(): ?array;

    /** Optional identifier of the tool result this message carries (role 'tool'). */
    public function toolCallId(): ?string;

    /** Immutable copy of this message as a ready-to-serialize array. */
    public function toArray(): array;
}
