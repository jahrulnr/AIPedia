<?php

return [
    'docs_root' => env('WEBCHAT_DOCS_ROOT', base_path('docs/webchat')),
    'docs_app_id' => env('WEBCHAT_DOCS_APP_ID', 'aipedia'),
    'storage_root' => env('WEBCHAT_STORAGE_ROOT', storage_path('app/webchat')),
    'write_enabled' => false,
    'llm_stub' => false,
    'llm_strategy' => env('WEBCHAT_LLM_STRATEGY', 'failover'),
    'llm_active_provider' => env('WEBCHAT_LLM_ACTIVE_PROVIDER', ''),
    'llm_total_attempt_budget' => (int) env('WEBCHAT_LLM_TOTAL_ATTEMPT_BUDGET', 4),
    'llm_circuit_failure_threshold' => (int) env('WEBCHAT_LLM_CIRCUIT_FAILURE_THRESHOLD', 3),
    'llm_circuit_cooldown_sec' => (int) env('WEBCHAT_LLM_CIRCUIT_COOLDOWN_SEC', 60),
    'llm_retry_statuses' => array_values(array_filter(array_map(
        static fn ($status) => (int) trim($status),
        explode(',', (string) env('WEBCHAT_LLM_RETRY_STATUSES', '408,409,413,425,429,500,502,503,504'))
    ), static fn (int $status): bool => $status > 0)),
    'llm_providers' => (function (): array {
        $raw = (string) env('WEBCHAT_LLM_PROVIDERS', 'OPENROUTER');
        $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($id) => $id !== ''));
        $providers = [];

        foreach ($ids as $rawId) {
            $id = strtoupper($rawId);
            $prefix = 'WEBCHAT_LLM_' . $id . '_';
            $providers[$id] = [
                'id' => $id,
                'base_url' => env($prefix . 'BASE_URL', ''),
                'api_key' => env($prefix . 'API_KEY', ''),
                'model' => env($prefix . 'MODEL', ''),
                'api' => env($prefix . 'API', 'responses'),
                'timeout_sec' => (int) env($prefix . 'TIMEOUT_SEC', env('WEBCHAT_LLM_TIMEOUT_SEC', 60)),
                'max_attempts' => (int) env($prefix . 'MAX_ATTEMPTS', 1),
                'weight' => (int) env($prefix . 'WEIGHT', 1),
                'context_window' => (int) env($prefix . 'CONTEXT_WINDOW', env('WEBCHAT_LLM_CONTEXT_WINDOW', 131072)),
                'max_output_tokens' => (int) env($prefix . 'MAX_OUTPUT_TOKENS', env('WEBCHAT_LLM_MAX_OUTPUT_TOKENS', 4096)),
                'max_input_tokens' => (int) env($prefix . 'MAX_INPUT_TOKENS', env('WEBCHAT_CONTEXT_MAX_INPUT_TOKENS', 12000)),
                'enabled' => filter_var(env($prefix . 'ENABLED', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
            ];
        }

        return $providers;
    })(),
    'max_tool_rounds' => (int) env('WEBCHAT_MAX_TOOL_ROUNDS', 8),
    'phase' => (int) env('WEBCHAT_PHASE', 2),
    'prompts_root' => resource_path('webchat/prompts'),
    'tools_root' => resource_path('webchat/tools'),
    'docs_top_k' => (int) env('WEBCHAT_DOCS_TOP_K', 5),
    'docs_fuzzy_enabled' => filter_var(env('WEBCHAT_DOCS_FUZZY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'docs_min_score' => (float) env('WEBCHAT_DOCS_MIN_SCORE', 0.5),
    'llm_timeout_sec' => (int) env('WEBCHAT_LLM_TIMEOUT_SEC', 60),
    'context_compaction_enabled' => filter_var(env('WEBCHAT_CONTEXT_COMPACTION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'context_max_input_tokens' => (int) env('WEBCHAT_CONTEXT_MAX_INPUT_TOKENS', 12000),
    'context_reserve_tokens' => (int) env('WEBCHAT_CONTEXT_RESERVE_TOKENS', 3000),
    'context_recent_turns' => (int) env('WEBCHAT_CONTEXT_RECENT_TURNS', 4),
    'context_summary_max_chars' => (int) env('WEBCHAT_CONTEXT_SUMMARY_MAX_CHARS', 12000),
    'turn_job_timeout_sec' => (int) env('WEBCHAT_TURN_JOB_TIMEOUT_SEC', 120),
    'turn_rate_limit_per_min' => (int) env('WEBCHAT_TURN_RATE_LIMIT_PER_MIN', 10),
    'speak_floor_ttl_sec' => (int) env('WEBCHAT_SPEAK_FLOOR_TTL_SEC', 600),
    'conversation_idle_ttl_days' => (int) env('WEBCHAT_CONVERSATION_IDLE_TTL_DAYS', 7),
];
