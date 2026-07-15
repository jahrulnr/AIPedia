<?php

namespace App\Services\Webchat;

class WebchatConfig
{
    public string $docsRoot;
    public string $storageRoot;
    public bool $writeEnabled;
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
    public int $turnJobTimeoutSec;
    public int $turnRateLimitPerMin;
    public int $speakFloorTtlSec;

    public static function load(): self
    {
        $cfg = config('webchat');
        if (!is_array($cfg)) {
            throw new \RuntimeException('config webchat missing — ensure config/webchat.php is loaded');
        }

        $api = strtolower((string) ($cfg['llm_api'] ?? 'chat'));
        if (!in_array($api, ['chat', 'responses'], true)) {
            $api = 'chat';
        }

        $self = new self();
        $self->docsRoot = (string) $cfg['docs_root'];
        $self->storageRoot = (string) $cfg['storage_root'];
        $self->writeEnabled = (bool) $cfg['write_enabled'];
        $self->llmBaseUrl = (string) $cfg['llm_base_url'];
        $self->llmApiKey = (string) $cfg['llm_api_key'];
        $self->llmModel = (string) $cfg['llm_model'];
        $self->llmApi = $api;
        $self->maxToolRounds = (int) $cfg['max_tool_rounds'];
        $self->phase = (int) $cfg['phase'];
        $self->llmStub = (bool) $cfg['llm_stub'];
        $self->promptsRoot = (string) $cfg['prompts_root'];
        $self->toolsRoot = (string) $cfg['tools_root'];
        $self->docsTopK = (int) $cfg['docs_top_k'];
        $self->llmTimeoutSec = (int) $cfg['llm_timeout_sec'];
        $self->turnJobTimeoutSec = (int) $cfg['turn_job_timeout_sec'];
        $self->turnRateLimitPerMin = (int) ($cfg['turn_rate_limit_per_min'] ?? 10);
        $self->speakFloorTtlSec = (int) ($cfg['speak_floor_ttl_sec'] ?? 600);
        return $self;
    }
}
