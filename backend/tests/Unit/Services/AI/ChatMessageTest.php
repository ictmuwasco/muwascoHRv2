<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\ChatMessage;
use Tests\TestCase;

/**
 * ChatMessage — immutability + role/format contract for the canonical message
 * value object used by every AI provider driver.
 *
 * Place: backend/tests/Unit/Services/AI/ChatMessageTest.php
 */
class ChatMessageTest extends TestCase
{
    public function testSystemUserAssistantFactories(): void
    {
        $this->assertSame('system', ChatMessage::system('policy')->role());
        $this->assertSame('user', ChatMessage::user('hi')->role());
        $this->assertSame('assistant', ChatMessage::assistant('hello')->role());
    }

    public function testContentIsPreserved(): void
    {
        $m = ChatMessage::user('What is my leave balance?');
        $this->assertSame('What is my leave balance?', $m->content());
    }

    public function testToolCallRoundTrip(): void
    {
        $m = ChatMessage::assistantWithToolCall('', 'getMyLeaveBalance', '{"year":2026}', 'call_1');
        $this->assertSame('assistant', $m->role());
        $this->assertSame([
            'id'        => 'call_1',
            'name'      => 'getMyLeaveBalance',
            'arguments' => '{"year":2026}',
        ], $m->toolCall());
    }

    public function testToolResultCarriesToolCallId(): void
    {
        $m = ChatMessage::tool('call_1', '{"days":21}');
        $this->assertSame('tool', $m->role());
        $this->assertSame('call_1', $m->toolCallId());
        $this->assertSame('{"days":21}', $m->content());
        $this->assertNull($m->toolCall());
    }

    public function testToArrayShape(): void
    {
        $m = ChatMessage::user('my message');
        $this->assertSame([
            'role'        => 'user',
            'content'     => 'my message',
            'tool_call'   => null,
            'tool_call_id'=> null,
        ], $m->toArray());
    }
}