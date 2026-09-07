<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * AiToolInterface — contract for a CONTROLLED HR data tool (Phase 5).
 *
 * A tool is the ONLY way the AI layer touches HR data. Tools are:
 *   - registered (never free-form SQL; the model can only NAME a tool),
 *   - permission-gated (requiredPermission is re-checked at execution),
 *   - owner/department-scoped INSIDE the tool (the user id comes from the
 *     server-side context, never from model-supplied arguments),
 *   - sensitive-field filtered (implementations return only fields the
 *     caller is entitled to and the conversation needs),
 *   - required to return JSON-encodable arrays only.
 *
 * The model NEVER sees raw rows beyond what execute() returns, and never
 * receives authorization decisions — a denied call simply returns a
 * structured denial the model must relay verbatim.
 */
interface AiToolInterface
{
    /** Registered tool name the model may call, e.g. 'getMyLeaveBalance'. */
    public function name(): string;

    /** One-paragraph description for the provider's tool schema. */
    public function description(): string;

    /**
     * JSON-schema "parameters" object (type/properties/required) for the
     * OpenAI wire format. Keep arguments minimal — every argument is an
     * injection surface; prefer zero-argument tools for "my X" queries.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Effective permission required to EXECUTE this tool, as 'module:action'
     * (checked against the hybrid authorization system). Empty string means
     * any authenticated user with an employee record may call it.
     */
    public function requiredPermission(): string;

    /**
     * Execute the tool. Implementations MUST scope every query by the
     * server-side context (ctx->employeeId() / ctx->userId) and MUST NOT
     * trust model-supplied ids for authorization. Throw nothing: return a
     * structured result instead (the executor still catches as a safety net).
     *
     * @param array<string, mixed> $args Model-supplied arguments (validated here).
     * @return array<string, mixed> JSON-encodable, sensitive-field-free payload.
     */
    public function execute(AiToolContext $ctx, array $args): array;

    /** Non-sensitive one-line summary for ai_tool_calls.result_summary. */
    public function summarize(array $payload): string;
}
