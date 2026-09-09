<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\AI\AiProviderManager;
use App\Services\AI\ChatMessage;
use App\Services\AI\AiCompletionResult;
use App\Services\AuditService;

/**
 * SecurityAiAnalysisService — AI-assisted threat detection and incident
 * correlation using the EXISTING NVIDIA AI infrastructure (AiProviderManager).
 *
 * This service reuses the same provider/client/HTTP/retry/logging stack as the
 * HR chatbot, but with:
 *   - Security-specific system prompt
 *   - Sanitized, minimized telemetry only (NO PII, no credentials)
 *   - Structured JSON output validated against a server-side schema
 *   - No destructive actions — AI detects and recommends, Laravel enforces
 *
 * Place: backend/app/Services/Security/SecurityAiAnalysisService.php
 */
final class SecurityAiAnalysisService
{
    private const AUDIT_MODULE = 'SecurityAI';

    private static ?SecurityAiAnalysisService $instance = null;

    private SecurityEventService $eventService;
    private SecurityIncidentService $incidentService;

    private function __construct()
    {
        $this->eventService   = SecurityEventService::getInstance();
        $this->incidentService = SecurityIncidentService::getInstance();
    }

        public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Analyze a specific incident using NVIDIA AI.
     * Reuses the existing AiProviderManager — no new AI architecture.
     */
    public function analyzeIncident(?int $incidentId, array $eventIds = []): array
    {
        if ($incidentId !== null && $incidentId > 0) {
            $incident = $this->incidentService->getIncidentById($incidentId);
            $events = $incident['related_events'] ?? [];
        } else {
            $events = [];
            foreach ($eventIds as $eid) {
                $event = $this->eventService->getEventById((int)$eid);
                if ($event) {
                    $events[] = $event;
                }
            }
        }

        if (empty($events)) {
            return [
                'classification' => 'NORMAL',
                'confidence' => 0.0,
                'reasoning_summary' => 'No security events available for analysis.',
                'risk_score' => 0,
                'recommended_action' => 'NO_ACTION_REQUIRED',
                'related_event_types' => [],
            ];
        }

        $sanitizedEvents = $this->sanitizeEvents($events);
        $prompt = $this->buildIncidentAnalysisPrompt($sanitizedEvents);

        $result = $this->sendToProvider($prompt);

        if (!$result->isSuccess()) {
            return [
                'classification' => 'UNKNOWN',
                'confidence' => 0.0,
                'reasoning_summary' => 'AI analysis unavailable — provider error.',
                'risk_score' => 0,
                'recommended_action' => 'INVESTIGATE_MANUALLY',
                'related_event_types' => [],
            ];
        }

        $parsed = $this->parseAndValidateResponse($result->getContent());

        AuditService::getInstance()->log(self::AUDIT_MODULE, 'AI_INCIDENT_ANALYSIS', 'AI analyzed incident', [
            'metadata' => [
                'incident_id' => $incidentId,
                'event_count' => count($sanitizedEvents),
                'classification' => $parsed['classification'] ?? 'UNKNOWN',
            ],
        ]);

        return $parsed;
    }

    /**
     * Analyze recent security events for threats.
     * Returns classified threats that may lead to incidents.
     */
    public function analyzeThreats(int $limit = 50): array
    {
        $events = $this->eventService->getRecentEvents($limit);
        if (empty($events)) {
            return ['threats' => [], 'analyzed_at' => date('Y-m-d H:i:s')];
        }

        $sanitizedEvents = $this->sanitizeEvents($events);
        $prompt = $this->buildThreatAnalysisPrompt($sanitizedEvents);

        $result = $this->sendToProvider($prompt);

        if (!$result->isSuccess()) {
            return [
                'threats' => [],
                'error' => 'AI analysis unavailable',
                'analyzed_at' => date('Y-m-d H:i:s'),
            ];
        }

        $parsed = $this->parseAndValidateResponse($result->getContent());

        // Update incidents with AI analysis if threats found
        foreach ($parsed['threats'] ?? [] as $threat) {
            if (isset($threat['incident_id']) && is_numeric($threat['incident_id'])) {
                $this->incidentService->updateAiAnalysis((int)$threat['incident_id'], [
                    'classification' => $threat['classification'] ?? null,
                    'confidence'     => $threat['confidence'] ?? 0,
                    'reasoning'      => $threat['reasoning_summary'] ?? null,
                    'risk_score'     => $threat['risk_score_recommendation'] ?? 0,
                    'recommended_action' => $threat['recommended_action'] ?? null,
                ]);
            }
        }

        AuditService::getInstance()->log(self::AUDIT_MODULE, 'AI_THREAT_ANALYSIS', 'AI analyzed threats', [
            'metadata' => ['event_count' => count($sanitizedEvents), 'threat_count' => count($parsed['threats'] ?? [])],
        ]);

        return $parsed;
    }

    /**
     * AI Security Copilot — answer an administrator's security question.
     *
     * Uses the EXISTING NVIDIA provider stack. The model may request a
     * controlled server-side tool (max 2 rounds); it never receives raw
     * SQL access, credentials, or authorization authority. Output is
     * sanitized plain text.
     */
    public function copilotQuery(string $question, array $tools = []): array
    {
        $cleanQuestion = $this->sanitizeUserMessage($question);

        $injection = \App\Services\AI\AiSanitizer::detectPromptInjection($cleanQuestion);
        if ($injection !== null) {
            AuditService::getInstance()->log(self::AUDIT_MODULE, 'AI_PROMPT_INJECTION_BLOCKED', 'Copilot prompt injection blocked', [
                'metadata' => ['label' => $injection],
            ]);
            return [
                'reply' => 'This request was blocked by input safety checks. Please rephrase your question.',
                'blocked' => true,
                'tool_results' => [],
            ];
        }

        // Only allow pre-approved tool names to reach the prompt.
        $allowedToolNames = ['get_security_events', 'get_security_incident', 'get_user_security_activity', 'get_endpoint_security_activity', 'get_authentication_activity'];
        $requestedTools = array_values(array_intersect($tools, $allowedToolNames));

        $systemPrompt = $this->buildCopilotSystemPrompt($requestedTools);
        $conversation = [
            ChatMessage::system($systemPrompt),
            ChatMessage::user($cleanQuestion),
        ];

        $toolResults = [];
        $maxRounds = 2;

        for ($round = 0; $round < $maxRounds; $round++) {
            $result = AiProviderManager::getInstance()->chat($conversation);

            if (!$result->isSuccess()) {
                return [
                    'reply' => 'AI copilot is currently unavailable. Please try again later.',
                    'blocked' => false,
                    'tool_results' => $toolResults,
                ];
            }

            $content = trim($result->getContent());
            $toolCall = $this->extractToolCall($content);

            if ($toolCall === null) {
                // Direct answer — sanitize and return.
                $reply = \App\Services\AI\AiSanitizer::sanitizeAssistantContent($content, 4000);
                AuditService::getInstance()->log(self::AUDIT_MODULE, 'AI_COPILOT_QUERY', 'Copilot answered a security question', [
                    'metadata' => ['tools_requested' => count($requestedTools), 'tools_executed' => count($toolResults)],
                ]);
                return ['reply' => $reply, 'blocked' => false, 'tool_results' => $toolResults];
            }

            // Model requested a tool — execute server-side and feed back.
            $toolResults = array_merge($toolResults, $this->executeTools([$toolCall]));
            $conversation[] = ChatMessage::assistant($content);
            $conversation[] = ChatMessage::user('Tool result (sanitized): ' . json_encode($toolResults));
        }

        return [
            'reply' => 'The question required more data gathering than allowed in one request. Please narrow your question and try again.',
            'blocked' => false,
            'tool_results' => $toolResults,
        ];
    }

    /**
     * Extract a single tool call from a copilot response, or null when the
     * content is a direct answer. Accepts {"tool":"name","args":{...}} JSON.
     */
    private function extractToolCall(string $content): ?array
    {
        $json = $this->extractJson($content);
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['tool']) || !is_string($data['tool'])) {
            return null;
        }

        return [
            'name' => $data['tool'],
            'arguments' => json_encode(is_array($data['args'] ?? null) ? $data['args'] : []),
        ];
    }

        /**
     * Sanitize events — strip PII, credentials, sensitive metadata.
     */
    private function sanitizeEvents(array $events): array
    {
        $sanitized = [];
        foreach ($events as $event) {
            $sanitized[] = [
                'event_type'      => $event['event_type'] ?? null,
                'severity'        => $event['severity'] ?? null,
                'risk_score'      => $event['risk_score'] ?? null,
                'user_id'         => $event['user_id'] ?? null,
                'ip_address'     => $this->maskIp($event['ip_address'] ?? null),
                'http_method'     => $event['http_method'] ?? null,
                'route'           => $event['route'] ?? null,
                'resource_type'   => $event['resource_type'] ?? null,
                'resource_id'     => $event['resource_id'] ?? null,
                'response_status' => $event['response_status'] ?? null,
                'action_taken'    => $event['action_taken'] ?? null,
                'detected_at'     => $event['detected_at'] ?? null,
            ];
        }
        return $sanitized;
    }

    private function maskIp(?string $ip): ?string
    {
        if (!$ip) return null;
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[2] = 'x';
            $parts[3] = 'x';
            return implode('.', $parts);
        }
        return '[REDACTED]';
    }

    private function sanitizeUserMessage(string $msg): string
    {
        return \App\Services\AI\AiSanitizer::sanitizeUserMessage($msg, 2000);
    }

    /**
     * Build the system prompt for threat analysis.
     */
    private function buildThreatAnalysisPrompt(array $events): ChatMessage
    {
        $systemPrompt = "You are a Security Operations AI Analyst. Analyze sanitized security telemetry and classify threats. Respond ONLY with valid JSON:\n"
            . '{"threats":[{"classification":"NORMAL|SUSPICIOUS|LIKELY_ATTACK|HIGH_CONFIDENCE_ATTACK|CRITICAL","confidence":0.0,"reasoning_summary":"...","risk_score_recommendation":0,"recommended_action":"...","related_event_types":[],"incident_id":null}],"analyzed_at":"YYYY-MM-DD HH:MM:SS"}';

        $telemetry = json_encode($events, JSON_PRETTY_PRINT);
        $userPrompt = "Analyze the following sanitized security events:\n\n" . $telemetry;

        return ChatMessage::user($systemPrompt . "\n---\n" . $userPrompt);
    }

    /**
     * Build the prompt for incident analysis.
     */
    private function buildIncidentAnalysisPrompt(array $events): ChatMessage
    {
        $systemPrompt = "You are a Security Incident AI Analyst. Analyze events and produce structured JSON:\n"
            . '{"classification":"NORMAL|SUSPICIOUS|LIKELY_ATTACK|HIGH_CONFIDENCE_ATTACK|CRITICAL","confidence":0.0,"reasoning_summary":"Evidence:... Inference:... Confidence:... Unknown:...","risk_score":0,"recommended_action":"...","related_event_types":[]}';

        $telemetry = json_encode($events, JSON_PRETTY_PRINT);
        $userPrompt = "Analyze the following sanitized security events for this incident:\n\n" . $telemetry;

        return ChatMessage::user($systemPrompt . "\n---\n" . $userPrompt);
    }

    /**
     * Build the system prompt for the AI Security Copilot.
     */
    private function buildCopilotSystemPrompt(array $toolNames): string
    {
        $toolsSection = '';
        if (!empty($toolNames)) {
            $toolsSection = "\n\nAvailable tools (call ONLY if the user question requires them):\n";
            foreach ($toolNames as $name) {
                $toolsSection .= "- {$name}\n";
            }
            $toolsSection .= "\nIf a tool is needed, respond with JSON: {\"tool\":\"TOOL_NAME\",\"args\":{\"key\":\"value\"}}\nIf answering directly, respond with plain text only.";
        }

        return "You are an AI Security Copilot for a Security Operations Center. You analyze sanitized security telemetry and answer administrator questions. You NEVER: access raw databases or execute SQL, reveal credentials, tokens, or secrets, fabricate events or evidence, make authorization decisions, or execute destructive actions autonomously.\n"
            . "All data comes from controlled server-side functions. Clearly distinguish evidence from inference." . $toolsSection;
    }

    /**
     * Send a message to the EXISTING NVIDIA AI provider via AiProviderManager.
     */
        private function sendToProvider(ChatMessage $message): AiCompletionResult
    {
        return AiProviderManager::getInstance()->chat([$message]);
    }

    /**
     * Parse and validate AI response against a server-side schema.
     */
    private function parseAndValidateResponse(string $content): array
    {
        $json = $this->extractJson($content);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return $this->defaultErrorResponse();
        }

        if (isset($data['threats'])) {
            return $this->validateThreatsResponse($data);
        }

        return $this->validateIncidentResponse($data);
    }

    private function extractJson(string $content): string
    {
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $m)) {
            return $m[1];
        }
        if (preg_match('/```\s*(.*?)\s*```/s', $content, $m)) {
            return $m[1];
        }
        return $content;
    }

    private function validateThreatsResponse(array $data): array
    {
        $validated = ['threats' => [], 'analyzed_at' => $data['analyzed_at'] ?? date('Y-m-d H:i:s')];
        $validClassifications = ['NORMAL','SUSPICIOUS','LIKELY_ATTACK','HIGH_CONFIDENCE_ATTACK','CRITICAL'];
        $validActions = ['RATE_LIMIT','TERMINATE_SESSION','REVOKE_TOKEN','INVESTIGATE_USER','INVESTIGATE_IP','TEMPORARILY_RESTRICT_ACCOUNT','NO_ACTION_REQUIRED'];

        foreach ($data['threats'] ?? [] as $threat) {
            if (!is_array($threat)) continue;
            $validated['threats'][] = [
                'classification' => in_array($threat['classification'] ?? null, $validClassifications, true) ? $threat['classification'] : 'SUSPICIOUS',
                'confidence' => max(0.0, min(1.0, (float)($threat['confidence'] ?? 0))),
                'reasoning_summary' => is_string($threat['reasoning_summary'] ?? null) ? substr($threat['reasoning_summary'], 0, 2000) : '',
                'risk_score_recommendation' => max(0, min(100, (int)($threat['risk_score_recommendation'] ?? 0))),
                'recommended_action' => in_array($threat['recommended_action'] ?? null, $validActions, true) ? $threat['recommended_action'] : 'INVESTIGATE_MANUALLY',
                'related_event_types' => is_array($threat['related_event_types'] ?? null) ? array_slice($threat['related_event_types'], 0, 20) : [],
                'incident_id' => isset($threat['incident_id']) && is_numeric($threat['incident_id']) ? (int)$threat['incident_id'] : null,
            ];
        }
        return $validated;
    }

    private function validateIncidentResponse(array $data): array
    {
        $validClassifications = ['NORMAL','SUSPICIOUS','LIKELY_ATTACK','HIGH_CONFIDENCE_ATTACK','CRITICAL'];
        $validActions = ['RATE_LIMIT','TERMINATE_SESSION','REVOKE_TOKEN','INVESTIGATE_USER','INVESTIGATE_IP','TEMPORARILY_RESTRICT_ACCOUNT','NO_ACTION_REQUIRED'];

        return [
            'classification' => in_array($data['classification'] ?? null, $validClassifications, true) ? $data['classification'] : 'SUSPICIOUS',
            'confidence' => max(0.0, min(1.0, (float)($data['confidence'] ?? 0))),
            'reasoning_summary' => is_string($data['reasoning_summary'] ?? null) ? substr($data['reasoning_summary'], 0, 2000) : 'No analysis available.',
            'risk_score' => max(0, min(100, (int)($data['risk_score'] ?? 0))),
            'recommended_action' => in_array($data['recommended_action'] ?? null, $validActions, true) ? $data['recommended_action'] : 'INVESTIGATE_MANUALLY',
            'related_event_types' => is_array($data['related_event_types'] ?? null) ? array_slice($data['related_event_types'], 0, 20) : [],
        ];
    }

    private function defaultErrorResponse(): array
    {
        return [
            'classification' => 'SUSPICIOUS',
            'confidence' => 0.0,
            'reasoning_summary' => 'AI returned an invalid response. Manual investigation required.',
            'risk_score' => 50,
            'recommended_action' => 'INVESTIGATE_MANUALLY',
            'related_event_types' => [],
            'threats' => [],
            'analyzed_at' => date('Y-m-d H:i:s'),
        ];
    }

        /**
     * Execute controlled backend tools requested by AI copilot.
     * Each tool maps to a pre-approved server-side function — never raw SQL.
     */
    private function executeTools(array $toolCalls): array
    {
        $results = [];
        $allowedTools = [
            'get_security_events' => fn() => $this->eventService->getEvents([], 1, 20),
            'get_security_incident' => fn($id) => $this->incidentService->getIncidentById((int)$id),
            'get_user_security_activity' => fn($userId) => $this->eventService->getEvents(['user_id' => (int)$userId], 1, 50),
            'get_endpoint_security_activity' => fn($route) => $this->eventService->getEvents(['route' => (string)$route], 1, 50),
            'get_authentication_activity' => fn() => $this->eventService->getEvents(['event_type' => SecurityEventService::FAILED_LOGIN], 1, 50),
        ];

        foreach ($toolCalls as $call) {
            $name = $call['name'] ?? '';
            $args = json_decode($call['arguments'] ?? '{}', true) ?? [];

            if (!isset($allowedTools[$name])) {
                $results[] = ['tool' => $name, 'error' => 'Tool not available'];
                continue;
            }

            try {
                $result = $allowedTools[$name](...array_values($args));
                $result = $this->sanitizeEvents(is_array($result) && isset($result['data']) ? $result['data'] : (array)$result);
                $results[] = ['tool' => $name, 'result' => $result];
            } catch (\Throwable $e) {
                $results[] = ['tool' => $name, 'error' => 'Tool execution failed'];
            }
        }

        return $results;
    }

    private function __clone(): void {}
    public function __wakeup() { throw new \RuntimeException('Cannot unserialize singleton'); }
}