<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * AiToolRegistry — the whitelist of controlled HR tools available to the AI.
 *
 * The model can only call tools that are registered here AND that the caller
 * is permitted to use. Registration is explicit code — adding a tool is a
 * reviewed change, never a runtime decision.
 */
final class AiToolRegistry
{
    /** @var AiToolInterface[] */
    private $tools = [];

    private static ?AiToolRegistry $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->register(new GetMyLeaveBalanceTool());
        $this->register(new GetMyLeaveApplicationsTool());
        $this->register(new GetMyEmployeeProfileTool());
        $this->register(new GetMyAttendanceTool());
    }

    public function register(AiToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** Look up one tool by its registered name. */
    public function byName(string $name): ?AiToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** All registered tool names (diagnostics only). */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Tools the given context may USE — definitions filtered by each tool's
     * requiredPermission against the hybrid authorization system. The model
     * never even sees tools the caller cannot execute (nothing to leak,
     * nothing to mis-call).
     *
     * @return AiToolInterface[]
     */
    public function forContext(AiToolContext $ctx): array
    {
        $allowed = [];
        foreach ($this->tools as $tool) {
            $perm = $tool->requiredPermission();
            if ($perm === '') {
                // Any authenticated user with an employee record.
                if ($ctx->hasEmployee()) {
                    $allowed[] = $tool;
                }
                continue;
            }
            [$module, $action] = array_pad(explode(':', $perm, 2), 2, 'view');
            if ($ctx->can($module, $action)) {
                $allowed[] = $tool;
            }
        }
        return $allowed;
    }

    /**
     * OpenAI wire-format tool definitions for the caller's allowed tools.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitionsForContext(AiToolContext $ctx): array
    {
        $definitions = [];
        foreach ($this->forContext($ctx) as $tool) {
            $definitions[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool->name(),
                    'description' => $tool->description(),
                    'parameters'  => $tool->parameters(),
                ],
            ];
        }
        return $definitions;
    }
}
