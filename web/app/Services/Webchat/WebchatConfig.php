<?php

namespace App\Services\Webchat;

class WebchatConfig
{
    public string $docsRoot;
    public string $storageRoot;
    public string $docsAppId;
    public bool $writeEnabled;
    public string $llmStrategy;
    public string $llmActiveProvider;
    public int $llmTotalAttemptBudget;
    public int $llmCircuitFailureThreshold;
    public int $llmCircuitCooldownSec;
    /** @var list<int> */
    public array $llmRetryStatuses;
    /** @var array<string, array<string, mixed>> */
    public array $llmProviders;
    public string $llmBaseUrl;
    public string $llmApiKey;
    public string $llmModel;
    /** @var string chat|responses */
    public string $llmApi;
    public int $maxToolRounds;
    public int $phase;
    public bool $llmStub;
    public string $promptsRoot;
    public string $toolsRoot;
    public int $docsTopK;
    public int $llmTimeoutSec;
    public bool $contextCompactionEnabled;
    public int $contextMaxInputTokens;
    public int $contextReserveTokens;
    public int $contextRecentTurns;
    public int $contextSummaryMaxChars;
    public int $turnJobTimeoutSec;
    public int $turnRateLimitPerMin;
    public int $speakFloorTtlSec;
    public int $conversationIdleTtlDays;

    public function llmInputBudget(): int
    {
        if ($this->llmStrategy === 'switch') {
            return (int) $this->llmProviders[$this->llmActiveProvider]['max_input_tokens'];
        }
        $enabled = array_values(array_filter(
            $this->llmProviders,
            static fn (array $provider): bool => $provider['enabled'] === true
        ));
        if ($enabled === []) {
            return $this->contextMaxInputTokens;
        }
        return min(array_map(
            static fn (array $provider): int => (int) $provider['max_input_tokens'],
            $enabled
        ));
    }

    public static function load(): self
    {
        $cfg = config('webchat');
        if (!is_array($cfg)) {
            throw new \RuntimeException('config webchat missing — ensure config/webchat.php is loaded');
        }

        $self = new self();
        $self->docsRoot = (string) $cfg['docs_root'];
        $self->storageRoot = (string) $cfg['storage_root'];
        $self->docsAppId = trim((string) ($cfg['docs_app_id'] ?? 'aipedia')) ?: 'aipedia';
        $self->writeEnabled = (bool) $cfg['write_enabled'];
        $self->llmStrategy = strtolower((string) ($cfg['llm_strategy'] ?? 'failover'));
        if (!in_array($self->llmStrategy, ['switch', 'failover', 'round_robin'], true)) {
            throw new \RuntimeException('invalid WEBCHAT_LLM_STRATEGY: ' . $self->llmStrategy);
        }
        $self->llmProviders = self::normalizeProviders($cfg['llm_providers'] ?? []);
        $self->llmTotalAttemptBudget = max(1, (int) ($cfg['llm_total_attempt_budget'] ?? 4));
        $self->llmCircuitFailureThreshold = max(1, (int) ($cfg['llm_circuit_failure_threshold'] ?? 3));
        $self->llmCircuitCooldownSec = max(1, (int) ($cfg['llm_circuit_cooldown_sec'] ?? 60));
        $self->llmRetryStatuses = array_values(array_filter(
            array_map('intval', is_array($cfg['llm_retry_statuses'] ?? null) ? $cfg['llm_retry_statuses'] : []),
            static fn (int $status): bool => $status > 0
        ));
        $self->contextCompactionEnabled = (bool) ($cfg['context_compaction_enabled'] ?? true);
        $self->contextMaxInputTokens = max(1000, (int) ($cfg['context_max_input_tokens'] ?? 12000));
        $self->contextReserveTokens = max(0, (int) ($cfg['context_reserve_tokens'] ?? 3000));
        $self->contextRecentTurns = max(1, (int) ($cfg['context_recent_turns'] ?? 4));
        $self->contextSummaryMaxChars = max(1000, (int) ($cfg['context_summary_max_chars'] ?? 12000));
        $self->llmActiveProvider = strtoupper((string) ($cfg['llm_active_provider'] ?? ''));
        if ($self->llmActiveProvider !== '') {
            if (!isset($self->llmProviders[$self->llmActiveProvider]) || !$self->llmProviders[$self->llmActiveProvider]['enabled']) {
                throw new \RuntimeException('invalid or disabled WEBCHAT_LLM_ACTIVE_PROVIDER: ' . $self->llmActiveProvider);
            }
        } else {
            $enabled = array_filter(
                $self->llmProviders,
                static fn (array $provider): bool => $provider['enabled'] === true
            );
            $self->llmActiveProvider = (string) array_key_first($enabled);
        }
        if ($self->llmActiveProvider === '') {
            throw new \RuntimeException('no enabled LLM provider configured');
        }
        $active = $self->llmProviders[$self->llmActiveProvider];
        if (!in_array($active['api'], ['chat', 'responses'], true)) {
            throw new \RuntimeException('invalid LLM API for active provider: ' . $self->llmActiveProvider);
        }
        $self->llmBaseUrl = $active['base_url'];
        $self->llmApiKey = $active['api_key'];
        $self->llmModel = $active['model'];
        $self->llmApi = $active['api'];
        $self->llmTimeoutSec = $active['timeout_sec'];
        $self->maxToolRounds = (int) $cfg['max_tool_rounds'];
        $self->phase = (int) $cfg['phase'];
        $self->llmStub = (bool) $cfg['llm_stub'];
        $self->promptsRoot = (string) $cfg['prompts_root'];
        $self->toolsRoot = (string) $cfg['tools_root'];
        $self->docsTopK = (int) $cfg['docs_top_k'];
        $self->turnJobTimeoutSec = (int) $cfg['turn_job_timeout_sec'];
        $self->turnRateLimitPerMin = (int) ($cfg['turn_rate_limit_per_min'] ?? 10);
        $self->speakFloorTtlSec = (int) ($cfg['speak_floor_ttl_sec'] ?? 600);
        $self->conversationIdleTtlDays = (int) ($cfg['conversation_idle_ttl_days'] ?? 7);
        return $self;
    }

    /** @return array<string, array<string, mixed>> */
    private static function normalizeProviders(mixed $providers): array
    {
        if (!is_array($providers) || $providers === []) {
            throw new \RuntimeException('WEBCHAT_LLM_PROVIDERS must contain at least one provider');
        }

        $normalized = [];
        foreach ($providers as $key => $provider) {
            if (!is_array($provider)) {
                throw new \RuntimeException('invalid LLM provider config: ' . (string) $key);
            }
            $id = strtoupper(trim((string) ($provider['id'] ?? $key)));
            if ($id === '' || preg_match('/^[A-Z0-9_]+$/', $id) !== 1 || isset($normalized[$id])) {
                throw new \RuntimeException('invalid or duplicate LLM provider ID: ' . $id);
            }
            $api = strtolower((string) ($provider['api'] ?? 'chat'));
            if (!in_array($api, ['chat', 'responses'], true)) {
                throw new \RuntimeException('invalid LLM API for provider: ' . $id);
            }
            $normalized[$id] = [
                'id' => $id,
                'base_url' => rtrim((string) ($provider['base_url'] ?? ''), '/'),
                'api_key' => (string) ($provider['api_key'] ?? ''),
                'model' => (string) ($provider['model'] ?? ''),
                'api' => $api,
                'timeout_sec' => max(1, (int) ($provider['timeout_sec'] ?? 60)),
                'max_attempts' => max(1, (int) ($provider['max_attempts'] ?? 1)),
                'weight' => max(1, (int) ($provider['weight'] ?? 1)),
                'context_window' => max(1024, (int) ($provider['context_window'] ?? 131072)),
                'max_output_tokens' => max(1, (int) ($provider['max_output_tokens'] ?? 4096)),
                'max_input_tokens' => max(1000, (int) ($provider['max_input_tokens'] ?? 12000)),
                'enabled' => (bool) ($provider['enabled'] ?? true),
            ];
            $normalized[$id]['max_input_tokens'] = min(
                $normalized[$id]['max_input_tokens'],
                max(1000, $normalized[$id]['context_window'] - $normalized[$id]['max_output_tokens'] - 512)
            );
        }

        return $normalized;
    }
}
