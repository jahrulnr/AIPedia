<?php

return [
    'docs_root' => env('WEBCHAT_DOCS_ROOT', base_path('docs/webchat')),
    'storage_root' => env('WEBCHAT_STORAGE_ROOT', storage_path('app/webchat')),
    'write_enabled' => false,
    'llm_stub' => env('WEBCHAT_LLM_STUB', true),
    'llm_base_url' => env('WEBCHAT_LLM_BASE_URL', 'https://openrouter.ai/api/v1'),
    'llm_api_key' => env('WEBCHAT_LLM_API_KEY', ''),
    'llm_model' => env('WEBCHAT_LLM_MODEL', ''),
    // chat = /chat/completions (contract default); responses = /responses (Codex-like, Groq beta OK)
    'llm_api' => env('WEBCHAT_LLM_API', 'chat'),
    'max_tool_rounds' => (int) env('WEBCHAT_MAX_TOOL_ROUNDS', 8),
    'phase' => (int) env('WEBCHAT_PHASE', 2),
    'prompts_root' => resource_path('webchat/prompts'),
    'tools_root' => resource_path('webchat/tools'),
    'docs_top_k' => 5,
    'llm_timeout_sec' => (int) env('WEBCHAT_LLM_TIMEOUT_SEC', 60),
    'turn_job_timeout_sec' => (int) env('WEBCHAT_TURN_JOB_TIMEOUT_SEC', 120),
    'turn_rate_limit_per_min' => (int) env('WEBCHAT_TURN_RATE_LIMIT_PER_MIN', 10),
    'speak_floor_ttl_sec' => (int) env('WEBCHAT_SPEAK_FLOOR_TTL_SEC', 600),
];
