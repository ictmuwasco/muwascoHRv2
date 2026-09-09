<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Contracts\AiProviderInterface;
use App\Services\AI\Contracts\MessageInterface;
use App\Services\AI\Providers\LocalProvider;
use App\Services\AI\Providers\NvidiaNimProvider;
use App\Services\AI\Providers\OpenAiCompatibleProvider;

/**
 * AiProviderManager — resolves the active AI provider from configuration,
 * applies the config-guarded fallback policy, caches health probes, and
 * records usage / audit entries for every completion attempt.
 *
 * Orchestration invariants:
 *   - The provider driver is selected EXCLUSIVELY by config('ai.provider');
 *     callers can never pick a provider. Fallback only ever happens for the
 *     config-listed retryable statuses (and only when enabled) — a
 *     PERMANENT_FAILURE or client-side authorization denial never triggers a
 *     fallback.
 *   - Provider API keys live in server-side env/config; never exposed here.
 *   - Usage logging is best-effort and failure-isolated: monitoring must
 *     never break the chat path. A full ai_usage_logs writer lands with the
 *     Phase 4 database foundation; until then usage goes to the structured
 *     application log.
 */
class AiProviderManager
{
    /** Sentinel written over api_key values in any diagnostic/log output. */
    public const KEY_REDACTION = '***';

    private const DRIVER_CLASSES = [
        'local'              => LocalProvider::class,
        'nvidia_nim'         => NvidiaNimProvider::class,
        'openai_compatible'  => OpenAiCompatibleProvider::class,
    ];

    private static ?AiProviderManager $instance = null;

    /** @var array<string, array{healthy: bool, checked_at: int}> */
    private array $healthCache = [];

    /** @var array<string, AiProviderInterface> */
    private array $instances = [];

    protected function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * The active provider resolved from config. Throws AiProviderException for
     * programming errors only (unknown driver / missing class).
     */
    public function provider(): AiProviderInterface
    {
        $driver = (string) $this->configValue('ai.provider', 'local');
        return $this->driver($driver);
    }

    /**
     * Run one chat completion with the active provider, applying the
     * config-guarded fallback policy on retryable failures when enabled.
     *
     * @param MessageInterface[] $messages
     * @param array|null         $tools
     */
    public function chat(array $messages, ?array $tools = null, array $options = []): AiCompletionResult
    {
        $activeDriver   = (string) $this->configValue('ai.provider', 'local');
        $activeProvider = $this->provider();
        $result         = $this->attemptWithDriver($activeProvider, $messages, $tools, $options);
        $driverLabel    = $activeDriver;
        $usedProvider   = $activeProvider;

        if (!$result->isSuccess() && $result->isRetryable()) {
            foreach ($this->fallbackDrivers() as $driver) {
                if ($driver === $activeDriver) {
                    continue;
                }
                if (!$this->isHealthy($driver)) {
                    \logger()->info('AI fallback skipped (unhealthy provider)', ['driver' => $driver]);
                    continue;
                }
                $fallbackProvider = $this->driver($driver);
                $result           = $this->attemptWithDriver($fallbackProvider, $messages, $tools, $options);
                $driverLabel     .= '->' . $driver;
                $usedProvider     = $fallbackProvider;
                if ($result->isSuccess() || !$result->isRetryable()) {
                    break;
                }
            }
        }

        $this->logUsage($driverLabel, $usedProvider, $result);
        return $result;
    }

    /**
     * Liveness probe cached for config('ai.health.cached_seconds'). Never throws.
     */
    public function isHealthy(string $driver = ''): bool
    {
        $driver = $driver === '' ? (string) $this->configValue('ai.provider', 'local') : $driver;
        $now    = time();
        $cached = $this->healthCache[$driver] ?? null;
        if ($cached !== null && ($now - $cached['checked_at']) < (int) $this->configValue('ai.health.cached_seconds', 30)) {
            return $cached['healthy'];
        }

        try {
            $healthy = $this->driver($driver)->isHealthy();
        } catch (\Throwable $e) {
            $healthy = false;
        }

        $this->healthCache[$driver] = ['healthy' => $healthy, 'checked_at' => $now];
        return $healthy;
    }

    /**
     * Redacted view of the AI config for logs / diagnostics: every api_key
     * value is replaced with '***' and never leaves the server.
     */
    public function safeConfig(): array
    {
        $config    = (array) $this->configValue('ai', []);
        $providers = $config['providers'] ?? [];
        if (!is_array($providers)) {
            return $config;
        }

        foreach ($providers as $key => $provider) {
            if (!is_array($provider)) {
                continue;
            }
            foreach ($provider as $k => $v) {
                if ($k === 'api_key' && $v !== null && $v !== '') {
                    $config['providers'][$key][$k] = self::KEY_REDACTION;
                }
            }
        }
        return $config;
    }

    /** @return array<int, string> */
    private function fallbackDrivers(): array
    {
        if (!(bool) $this->configValue('ai.fallback.enabled', false)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn ($d) => (string) $d,
            (array) $this->configValue('ai.fallback.providers', [])
        )));
    }

    private function driver(string $driver): AiProviderInterface
    {
        if (isset($this->instances[$driver])) {
            return $this->instances[$driver];
        }

        if (!isset(self::DRIVER_CLASSES[$driver])) {
            throw AiProviderException::unknownDriver($driver);
        }

        $config   = $this->configValue('ai.providers.' . $driver, []);
        $instance = $this->newProvider($driver, is_array($config) ? $config : []);
        $this->instances[$driver] = $instance;
        return $instance;
    }

    /**
     * Config seam — resolves one config key. Overridden by tests to exercise
     * fallback / provider selection deterministically without touching the
     * shared config cache. Production always reads via \config().
     */
    protected function configValue(string $key, mixed $default = null): mixed
    {
        return \config($key, $default);
    }

    /**
     * Provider factory seam — constructs a driver from its config. Overridden
     * by tests to inject fakes; production instantiates the driver class.
     */
    protected function newProvider(string $driver, array $config): AiProviderInterface
    {
        $class = self::DRIVER_CLASSES[$driver] ?? null;
        if ($class === null) {
            throw AiProviderException::unknownDriver($driver);
        }
        if (!class_exists($class)) {
            throw AiProviderException::missingDriverClass($driver, $class);
        }
        return new $class($config);
    }

    private function attemptWithDriver(
        AiProviderInterface $provider,
        array $messages,
        ?array $tools,
        array $options
    ): AiCompletionResult {
        $start = microtime(true);
        try {
            return $provider->chat($messages, $tools, $options);
        } catch (\Throwable $e) {
            \logger()->error('AI provider chat raised an exception', [
                'provider' => $provider->name(),
                'error'    => $e->getMessage(),
            ]);
            return AiCompletionResult::failure(
                AiCompletionResult::STATUS_PROVIDER_ERROR,
                'AI provider failed unexpectedly (see server logs).'
            );
        } finally {
            // Phase 2: provider latency + attempt count (metadata only — the
            // conversation content, tools and keys are never captured).
            \App\Helpers\PerfTiming::accumulate('ai_provider', (microtime(true) - $start) * 1000.0);
            \App\Helpers\PerfTiming::count('ai_provider_calls');
        }
    }

    /**
     * Best-effort structured usage log (per-completion). A DB-backed writer
     * (ai_usage_logs) is added in Phase 4; failures here are swallowed so
     * monitoring never breaks the chat path.
     */
    private function logUsage(string $driverLabel, AiProviderInterface $provider, AiCompletionResult $result): void
    {
        if (!(bool) $this->configValue('ai.logging.usage_enabled', true)) {
            return;
        }

        try {
            \logger()->info('AI completion', [
                'provider'    => $driverLabel,
                'model'       => $provider->model(),
                'status'      => $result->getStatus(),
                'usage'       => $result->getUsage(),
                'attempts'    => $result->getAttempts(),
                'http_status' => $result->getHttpStatus(),
                'request_id'  => \App\Services\ErrorTracking\RequestIdService::current(),
            ]);
        } catch (\Throwable $e) {
            \error_log('[AiProviderManager] usage log failed: ' . $e->getMessage());
        }
    }

    private function __clone(): void
    {
    }

    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}