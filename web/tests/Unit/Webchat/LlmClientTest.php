<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\Agent\WebchatLlmClient;
use App\Services\Webchat\WebchatConfig;
use Tests\TestCase;

class LlmClientTest extends TestCase
{
    public function test_normalize_parameters_makes_optional_fields_nullable_and_required()
    {
        config(['webchat.llm_api' => 'chat']);
        $client = new WebchatLlmClient(WebchatConfig::load());

        $normalized = $client->normalizeParameters([
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'domain' => ['type' => 'string'],
                'top_k' => ['type' => 'integer'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ]);

        $this->assertSame(['query', 'domain', 'top_k'], $normalized['required']);
        $this->assertSame('string', $normalized['properties']['query']['type']);
        $this->assertSame(['string', 'null'], $normalized['properties']['domain']['type']);
        $this->assertSame(['integer', 'null'], $normalized['properties']['top_k']['type']);
    }

    public function test_to_responses_request_maps_chat_history_and_tools()
    {
        config([
            'webchat.llm_api' => 'responses',
            'webchat.llm_model' => 'qwen/qwen3-32b',
        ]);
        $client = new WebchatLlmClient(WebchatConfig::load());

        $body = $client->toResponsesRequest([
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'developer', 'content' => 'dev'],
            ['role' => 'user', 'content' => 'cari voucher'],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_docs',
                        'arguments' => '{"query":"voucher","domain":null,"top_k":null}',
                    ],
                ]],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_1',
                'content' => '{"ok":true}',
            ],
        ], [[
            'type' => 'function',
            'function' => [
                'name' => 'search_docs',
                'description' => 'Search docs',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'domain' => ['type' => 'string'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ]]);

        $this->assertSame('qwen/qwen3-32b', $body['model']);
        $this->assertSame("sys\n\ndev", $body['instructions']);
        $this->assertSame('user', $body['input'][0]['role']);
        $this->assertSame('function_call', $body['input'][1]['type']);
        $this->assertSame('call_1', $body['input'][1]['call_id']);
        $this->assertSame('function_call_output', $body['input'][2]['type']);
        $this->assertSame('search_docs', $body['tools'][0]['name']);
        $this->assertSame(['query', 'domain'], $body['tools'][0]['parameters']['required']);
    }

    public function test_normalize_chat_tools_disables_strict()
    {
        config(['webchat.llm_api' => 'chat']);
        $client = new WebchatLlmClient(WebchatConfig::load());

        $tools = $client->normalizeChatTools([[
            'type' => 'function',
            'function' => [
                'name' => 'search_docs',
                'description' => 'x',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'domain' => ['type' => 'string'],
                    ],
                    'required' => ['query'],
                ],
                'strict' => true,
            ],
        ]]);

        $this->assertFalse($tools[0]['function']['strict']);
        $this->assertSame(['query', 'domain'], $tools[0]['function']['parameters']['required']);
    }
}
