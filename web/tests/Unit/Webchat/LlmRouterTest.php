<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\Agent\WebchatLlmClient;
use App\Services\Webchat\Agent\WebchatLlmException;
use App\Services\Webchat\Agent\WebchatLlmRouter;
use App\Services\Webchat\WebchatConfig;
use Tests\TestCase;

class LlmRouterTest extends TestCase
{
    public function test_http_413_is_transient_and_fails_over_to_the_next_provider(): void
    {
        $storageRoot = sys_get_temp_dir() . '/aipedia-router-' . uniqid('', true);
        config([
            'webchat.storage_root' => $storageRoot,
            'webchat.llm_strategy' => 'failover',
            'webchat.llm_active_provider' => 'GROQ',
            'webchat.llm_total_attempt_budget' => 4,
            'webchat.llm_circuit_failure_threshold' => 3,
            'webchat.llm_retry_statuses' => [],
            'webchat.llm_providers' => [
                'GROQ' => [
                    'id' => 'GROQ', 'base_url' => 'https://groq.test/v1',
                    'api_key' => 'groq-key', 'model' => 'groq-model', 'api' => 'responses',
                ],
                'OPENROUTER' => [
                    'id' => 'OPENROUTER', 'base_url' => 'https://openrouter.test/v1',
                    'api_key' => 'openrouter-key', 'model' => 'openrouter-model', 'api' => 'chat',
                ],
            ],
        ]);

        $cfg = WebchatConfig::load();
        $client = new class($cfg) extends WebchatLlmClient {
            /** @var list<string> */
            public array $calls = [];

            public function statusIsTransient(int $status): bool
            {
                return $this->isTransientStatus($status);
            }

            public function chatWithProvider(string $providerId, array $messages, array $tools = []): array
            {
                $this->calls[] = $providerId;
                if ($providerId === 'GROQ') {
                    throw new WebchatLlmException('request too large', $providerId, 413, true);
                }

                return ['model' => ['provider' => $providerId, 'id' => 'openrouter-model', 'api' => 'chat']];
            }
        };

        $this->assertTrue($client->statusIsTransient(413));
        $response = (new WebchatLlmRouter($cfg, $client))->chat([['role' => 'user', 'content' => 'hello']]);

        $this->assertSame(['GROQ', 'OPENROUTER'], $client->calls);
        $this->assertSame('OPENROUTER', $response['model']['provider']);

        @unlink($storageRoot . '/llm/provider_state.json');
        @rmdir($storageRoot . '/llm');
        @rmdir($storageRoot);
    }
}
