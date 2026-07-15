<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\WebchatConfig;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfig = config('webchat');
    }

    protected function tearDown(): void
    {
        config(['webchat' => $this->originalConfig]);
        parent::tearDown();
    }

    public function test_it_loads_provider_ids_and_selects_active_provider(): void
    {
        config([
            'webchat.llm_strategy' => 'failover',
            'webchat.llm_active_provider' => 'GROQ',
            'webchat.llm_providers' => [
                'OPENROUTER' => [
                    'id' => 'OPENROUTER', 'base_url' => 'https://openrouter.ai/api/v1',
                    'api_key' => 'key-a', 'model' => 'model-a', 'api' => 'chat',
                ],
                'GROQ' => [
                    'id' => 'GROQ', 'base_url' => 'https://api.groq.com/openai/v1',
                    'api_key' => 'key-b', 'model' => 'model-b', 'api' => 'responses',
                    'timeout_sec' => 30, 'max_attempts' => 2, 'weight' => 2,
                ],
            ],
        ]);

        $cfg = WebchatConfig::load();

        $this->assertSame(['OPENROUTER', 'GROQ'], array_keys($cfg->llmProviders));
        $this->assertSame('GROQ', $cfg->llmActiveProvider);
        $this->assertSame('https://api.groq.com/openai/v1', $cfg->llmBaseUrl);
        $this->assertSame('model-b', $cfg->llmModel);
        $this->assertSame('responses', $cfg->llmApi);
        $this->assertSame(30, $cfg->llmTimeoutSec);
        $this->assertSame(131072, $cfg->llmProviders['GROQ']['context_window']);
        $this->assertSame(4096, $cfg->llmProviders['GROQ']['max_output_tokens']);
        $this->assertSame(12000, $cfg->llmInputBudget());
    }

    public function test_it_rejects_invalid_provider_id(): void
    {
        config(['webchat.llm_providers' => [
            'bad-provider' => ['id' => 'bad-provider', 'base_url' => 'x', 'model' => 'm'],
        ]]);

        $this->expectException(\RuntimeException::class);
        WebchatConfig::load();
    }
}
