<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\AuditService;

/**
 * AiToolExecutor — the single gateway through which every model-proposed
 * tool call runs. Responsibilities:
 *
 *   1. whitelist check (the name must be a REGISTERED tool),
 *   2. argument sanity (size cap, JSON round-trip),
 *   3. permission RE-CHECK at execution time (defense in depth — the
 *      registry already filtered definitions, but never trust the model
 *      to call only what it was shown),
 *   4. execution with latency measurement and total failure containment
 *      (a broken tool must degrade the answer, never crash the turn),
 *   5. structured result for the ai_tool_calls audit row (the conversation
 *      service persists it once the assistant message id exists).
 *
 * The executor NEVER returns raw exceptions or provider internals to the
 * model — errors are generic, denials are structured and quotable.
 */
final class AiToolExecutor
{
    private const MAX_ARGUMENTS_JSON_CHARS = 2000;

    private static ?AiToolExecutor $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * @param string $toolName   Model-proposed tool name.
     * @param string $arguments  Model-proposed arguments as a JSON string.
     * @return array{
     *   status: 'ok'|'denied'|'error'|'invalid',
     *   payload: array<string, mixed>,
     *   summary: string,
     *   latency_ms: int,
     *   arguments_json: string
     * }
     */
    public function run(AiToolContext $ctx, string $toolName, string $arguments): array
    {
        $startedAt = microtime(true);
        $toolName  = trim($toolName);
        $args      = $this->decodeArguments($arguments);

        $finish = function (string $status, array $payload, string $summary) use ($ctx, $toolName, $args, $startedAt): array {
            return [
                'status'        => $status,
                'payload'       => $payload,
                'summary'       => mb_substr($summary, 0, 500),
                'latency_ms'    => (int) round((microtime(true) - $startedAt) * 1000),
                'arguments_json' => $args === null ? '{}' : json_encode(
                    $args,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ];
        };

        // 1) Name whitelist + format guard (never free-form anything).
        if ($toolName === '' || strlen($toolName) > 64
            || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,63}$/', $toolName) !== 1) {
            \logger()->warning('AI tool call rejected: invalid name', ['user_id' => $ctx->userId()]);
            return $finish('invalid', [
                'error' => 'Unknown data request. Please rephrase your question.',
            ], 'invalid tool name');
        }

        $tool = AiToolRegistry::getInstance()->byName($toolName);
        if ($tool === null) {
            \logger()->warning('AI tool call rejected: not registered', [
                'user_id' => $ctx->userId(),
            ]);
            return $finish('invalid', [
                'error' => 'Unknown data request. Please rephrase your question.',
            ], 'unregistered tool');
        }

        // 2) Permission re-check at execution time.
        $perm = $tool->requiredPermission();
        if ($perm !== '') {
            [$module, $action] = array_pad(explode(':', $perm, 2), 2, 'view');
            if (!$ctx->can($module, $action)) {
                AuditService::getInstance()->log(
                    AuditService::MODULE_AI,
                    AuditService::ACTION_AI_TOOL_DENIED,
                    'AI tool call denied by permission check',
                    [
                        'user_id'  => $ctx->userId(),
                        'metadata' => ['tool' => $toolName, 'permission' => $perm],
                        'status'   => AuditService::STATUS_DENIED,
                    ]
                );
                return $finish('denied', [
                    'error' => 'You are not authorised to view this data.',
                ], 'denied: ' . $perm);
            }
        } elseif (!$ctx->hasEmployee()) {
            return $finish('denied', [
                'error' => 'No employee record is linked to your account, so no HR data can be shown.',
            ], 'no employee record');
        }

        // 3) Execute with total containment.
        try {
            $payload = $tool->execute($ctx, is_array($args) ? $args : []);
            $status  = 'ok';
            $summary = $tool->summarize(is_array($payload) ? $payload : []);
        } catch (\Throwable $e) {
            \logger()->error('AI tool execution failed', [
                'tool'    => $toolName,
                'user_id' => $ctx->userId(),
                'error'   => $e->getMessage(),
            ]);
            return $finish('error', [
                'error' => 'That data could not be retrieved right now. Please try again shortly.',
            ], 'error: ' . substr($e->getMessage(), 0, 120));
        }

        return $finish(
            $status,
            is_array($payload) ? $payload : [],
            $summary !== '' ? $summary : 'ok'
        );
    }

    /** Decode + cap model-supplied arguments. Returns null when unusable. */
    private function decodeArguments(string $arguments): ?array
    {
        $arguments = trim($arguments);
        if ($arguments === '') {
            return [];
        }
        if (strlen($arguments) > self::MAX_ARGUMENTS_JSON_CHARS) {
            return null;
        }
        $decoded = json_decode($arguments, true);
        return is_array($decoded) ? $decoded : null;
    }
}
