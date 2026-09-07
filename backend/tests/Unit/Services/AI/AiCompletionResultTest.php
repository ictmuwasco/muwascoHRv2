<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\AiCompletionResult;
use Tests\TestCase;

/**
 * AiCompletionResult — status semantics + retryability classification for the
 * typed completion result value object.
 *
 * Place: backend/tests/Unit/Services/AI/AiCompletionResultTest.php
 */
class AiCompletionResultTest extends TestCase
{
    public function testSuccessHasContentAndUsageAndIsNotRetryable(): void
    {
        $r = AiCompletionResult::success('42 days', 'stop', ['total_tokens' => 3], 'llama3');
        $this->assertTrue($r->isSuccess());
        $this->assertSame('42 days', $r->getContent());
        $this->assertSame('stop', $r->getFinishReason());
        $this->assertSame(['total_tokens' => 3], $r->getUsage());
        $this->assertSame('llama3', $r->getProviderModel());
        $this->assertFalse($r->isRetryable());
        $this->assertFalse($r->isToolCall());
    }

    public function testToolCallResult(): void
    {
        $r = AiCompletionResult::toolCalls([
            ['name' => 'getMyLeaveBalance', 'arguments' => '{}', 'id' => 'call_1'],
        ], 'llama3');
        $this->assertTrue($r->isToolCall());
        $this->assertSame('TOOL_CALL_SELECTED', $r->getStatus());
        $this->assertCount(1, $r->getToolCalls());
        $this->assertFalse($r->isSuccess());
    }

    public function testRetryableStatuses(): void
    {
        foreach ([
            AiCompletionResult::STATUS_TEMPORARY_FAILURE,
            AiCompletionResult::STATUS_RATE_LIMITED,
            AiCompletionResult::STATUS_TIMEOUT,
            AiCompletionResult::STATUS_PROVIDER_ERROR,
        ] as $status) {
            $r = AiCompletionResult::failure($status, 'reason');
            $this->assertTrue($r->isRetryable(), "{$status} must be retryable");
            $this->assertFalse($r->isSuccess());
        }
    }

    public function testPermanentFailureIsNotRetryable(): void
    {
        $r = AiCompletionResult::failure(AiCompletionResult::STATUS_PERMANENT_FAILURE, 'bad request');
        $this->assertFalse($r->isRetryable());
        $this->assertSame('bad request', $r->getFailureReason());
    }

    public function testWithAttemptsIsImmutable(): void
    {
        $r = AiCompletionResult::failure(AiCompletionResult::STATUS_TIMEOUT, 't');
        $copy = $r->withAttempts(3);
        $this->assertSame(1, $r->getAttempts());
        $this->assertSame(3, $copy->getAttempts());
    }
}