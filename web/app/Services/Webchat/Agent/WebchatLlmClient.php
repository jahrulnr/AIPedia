<?php

namespace App\Services\Webchat\Agent;

use App\Services\Webchat\WebchatConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class WebchatLlmClient
{
    private WebchatConfig $cfg;
    /** @var array<string, mixed>|null */
    private ?array $provider = null;

    public function __construct(WebchatConfig $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * @param  list<array<string, mixed>>  $messages  Chat-completions shaped history
     * @param  list<array<string, mixed>>  $tools     Chat-completions shaped tools (type=function + function{})
     * @return array{
     *   has_tool_calls: bool,
     *   tool_calls: list<array{id:string,name:string,arguments:array}>,
     *   assistant_text: string,
     *   reasoning_text: string,
     *   assistant_message: array<string, mixed>|null,
     *   usage: array<string, int>,
     *   model: array{provider:string,id:string,api:string}
     * }
     */
    public function chat(array $messages, array $tools = []): array
    {
        return $this->chatWithProvider($this->cfg->llmActiveProvider, $messages, $tools);
    }

    /** @return array<string, mixed> */
    public function chatWithProvider(string $providerId, array $messages, array $tools = []): array
    {
        $provider = $this->cfg->llmProviders[$providerId] ?? null;
        if (!is_array($provider)) {
            throw new WebchatLlmException('LLM provider missing: ' . $providerId, $providerId);
        }
        if ((string) ($provider['api_key'] ?? '') === '') {
            throw new WebchatLlmException('LLM API key missing', $providerId);
        }
        $this->provider = $provider;

        if ($provider['api'] === 'responses') {
            return $this->chatViaResponses($messages, $tools);
        }

        return $this->chatViaCompletions($messages, $tools);
    }

    private function chatViaCompletions(array $messages, array $tools): array
    {
        $body = [
            'model' => $this->provider['model'],
            'messages' => $messages,
            'tool_choice' => 'auto',
            'max_tokens' => (int) $this->provider['max_output_tokens'],
        ];

        if ($tools !== []) {
            $body['tools'] = $this->normalizeChatTools($tools);
        }

        $payload = $this->postJson('chat/completions', $body);
        $choice = $payload['choices'][0]['message'] ?? [];
        $usage = $payload['usage'] ?? [];

        $toolCalls = [];
        $hasToolCalls = false;
        if (!empty($choice['tool_calls']) && is_array($choice['tool_calls'])) {
            $hasToolCalls = true;
            foreach ($choice['tool_calls'] as $tc) {
                $toolCalls[] = [
                    'id' => $tc['id'] ?? ('call_' . $this->newUlid()),
                    'name' => $tc['function']['name'] ?? '',
                    'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
                ];
            }
        }

        return [
            'has_tool_calls' => $hasToolCalls,
            'tool_calls' => $toolCalls,
            'assistant_text' => is_string($choice['content'] ?? null) ? $choice['content'] : '',
            'reasoning_text' => $this->extractReasoningFromChatMessage(is_array($choice) ? $choice : []),
            'assistant_message' => $choice,
            'usage' => $this->mapUsage($usage),
            'model' => $this->modelMetadata(),
        ];
    }

    private function chatViaResponses(array $messages, array $tools): array
    {
        $converted = $this->toResponsesRequest($messages, $tools);
        $payload = $this->postJson('responses', $converted);
        $output = is_array($payload['output'] ?? null) ? $payload['output'] : [];

        $toolCalls = [];
        $assistantText = '';
        foreach ($output as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'function_call') {
                $args = $item['arguments'] ?? '{}';
                if (is_array($args)) {
                    $args = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $toolCalls[] = [
                    'id' => (string) ($item['call_id'] ?? $item['id'] ?? ('call_' . $this->newUlid())),
                    'name' => (string) ($item['name'] ?? ''),
                    'arguments' => json_decode((string) $args, true) ?: [],
                ];
            }
            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'output_text') {
                        $assistantText .= (string) ($block['text'] ?? '');
                    }
                }
            }
        }

        if ($assistantText === '' && is_string($payload['output_text'] ?? null)) {
            $assistantText = $payload['output_text'];
        }

        $hasToolCalls = $toolCalls !== [];
        $assistantMessage = null;
        if ($hasToolCalls) {
            $assistantMessage = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => array_map(static function (array $tc): array {
                    return [
                        'id' => $tc['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'],
                            'arguments' => json_encode($tc['arguments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ];
                }, $toolCalls),
            ];
        }

        return [
            'has_tool_calls' => $hasToolCalls,
            'tool_calls' => $toolCalls,
            'assistant_text' => $assistantText,
            'reasoning_text' => $this->extractReasoningFromResponsesOutput($output),
            'assistant_message' => $assistantMessage,
            'usage' => $this->mapUsage($payload['usage'] ?? []),
            'model' => $this->modelMetadata(),
        ];
    }

    /**
     * Pull provider reasoning/thinking text from Responses API output items.
     *
     * @param  list<array<string, mixed>>  $output
     */
    public function extractReasoningFromResponsesOutput(array $output): string
    {
        $parts = [];
        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'reasoning') {
                continue;
            }
            foreach ($item['summary'] ?? [] as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $text = trim((string) ($block['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            foreach ($item['content'] ?? [] as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $type = (string) ($block['type'] ?? '');
                if (!in_array($type, ['reasoning_text', 'summary_text', 'output_text', 'text'], true)) {
                    continue;
                }
                $text = trim((string) ($block['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            if (is_string($item['text'] ?? null) && trim($item['text']) !== '') {
                $parts[] = trim($item['text']);
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Pull reasoning from chat-completions assistant message variants.
     *
     * @param  array<string, mixed>  $choice
     */
    public function extractReasoningFromChatMessage(array $choice): string
    {
        foreach (['reasoning_content', 'reasoning', 'thinking'] as $key) {
            $val = $choice[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
            if (is_array($val)) {
                $chunks = [];
                foreach ($val as $part) {
                    if (is_string($part) && trim($part) !== '') {
                        $chunks[] = trim($part);
                    } elseif (is_array($part) && is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                        $chunks[] = trim($part['text']);
                    }
                }
                if ($chunks !== []) {
                    return implode("\n\n", $chunks);
                }
            }
        }

        return '';
    }

    /**
     * Convert chat-completions history into a Responses API request body.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    public function toResponsesRequest(array $messages, array $tools = []): array
    {
        $instructions = [];
        $input = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? null;

            if ($role === 'system' || $role === 'developer') {
                $content = $msg['content'] ?? '';
                if (is_string($content) && $content !== '') {
                    $instructions[] = $content;
                }
                continue;
            }

            if ($role === 'user') {
                $input[] = ['role' => 'user', 'content' => (string) ($msg['content'] ?? '')];
                continue;
            }

            if ($role === 'assistant') {
                if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                    foreach ($msg['tool_calls'] as $tc) {
                        $input[] = [
                            'type' => 'function_call',
                            'call_id' => (string) ($tc['id'] ?? ''),
                            'name' => (string) ($tc['function']['name'] ?? ''),
                            'arguments' => (string) ($tc['function']['arguments'] ?? '{}'),
                        ];
                    }
                } elseif (($msg['content'] ?? null) !== null) {
                    $input[] = ['role' => 'assistant', 'content' => (string) $msg['content']];
                }
                continue;
            }

            if ($role === 'tool') {
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => (string) ($msg['tool_call_id'] ?? ''),
                    'output' => (string) ($msg['content'] ?? ''),
                ];
            }
        }

        $body = [
            'model' => $this->provider['model'] ?? $this->cfg->llmModel,
            'input' => $input,
            'tool_choice' => 'auto',
            'max_output_tokens' => (int) ($this->provider['max_output_tokens'] ?? 4096),
        ];

        if ($instructions !== []) {
            $body['instructions'] = implode("\n\n", $instructions);
        }

        if ($tools !== []) {
            $body['tools'] = $this->toResponsesTools($tools);
        }

        return $body;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    public function normalizeChatTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? [];
            $params = $this->normalizeParameters($fn['parameters'] ?? ['type' => 'object', 'properties' => []]);
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $fn['name'] ?? '',
                    'description' => $fn['description'] ?? '',
                    'parameters' => $params,
                    // Groq/OpenAI strict mode requires every property in required;
                    // keep strict off so optional fields stay optional for chat API.
                    'strict' => false,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    public function toResponsesTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? $tool;
            $params = $this->normalizeParameters($fn['parameters'] ?? ['type' => 'object', 'properties' => []]);
            $out[] = [
                'type' => 'function',
                'name' => $fn['name'] ?? '',
                'description' => $fn['description'] ?? '',
                'parameters' => $params,
            ];
        }

        return $out;
    }

    /**
     * Make JSON Schema provider-safe: optional props become nullable and listed in required.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function normalizeParameters(array $parameters): array
    {
        $properties = $parameters['properties'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        $required = $parameters['required'] ?? [];
        if (!is_array($required)) {
            $required = [];
        }

        $normalizedProps = [];
        $allKeys = [];
        foreach ($properties as $key => $schema) {
            $allKeys[] = $key;
            if (!is_array($schema)) {
                $normalizedProps[$key] = $schema;
                continue;
            }
            if (!in_array($key, $required, true)) {
                $type = $schema['type'] ?? null;
                if (is_string($type)) {
                    $schema['type'] = $this->tolerantOptionalTypes($type);
                } elseif (is_array($type) && !in_array('null', $type, true)) {
                    $schema['type'] = $this->tolerantOptionalTypes($type);
                }
            }
            $normalizedProps[$key] = $schema;
        }

        $parameters['properties'] = $normalizedProps;
        $parameters['required'] = array_values(array_unique(array_merge($required, $allKeys)));
        $parameters['additionalProperties'] = false;
        $parameters['type'] = $parameters['type'] ?? 'object';

        return $parameters;
    }

    /** @return list<string> */
    private function tolerantOptionalTypes(string|array $types): array
    {
        $types = is_array($types) ? $types : [$types];
        if (array_intersect($types, ['integer', 'number', 'boolean']) !== []) {
            $types[] = 'string';
        }
        $types[] = 'null';
        return array_values(array_unique($types));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $client = new Client([
            'base_uri' => rtrim((string) ($this->provider['base_url'] ?? $this->cfg->llmBaseUrl), '/') . '/',
            'timeout' => (int) ($this->provider['timeout_sec'] ?? $this->cfg->llmTimeoutSec),
            'headers' => [
                'Authorization' => 'Bearer ' . ($this->provider['api_key'] ?? $this->cfg->llmApiKey),
                'Content-Type' => 'application/json',
            ],
        ]);

        try {
            $response = $client->post($path, ['json' => $body]);
        } catch (ConnectException $e) {
            throw new WebchatLlmException(
                'TIMEOUT/CONNECT: ' . $e->getMessage(),
                (string) ($this->provider['id'] ?? $this->cfg->llmActiveProvider),
                0,
                true,
                $e
            );
        } catch (RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $respBody = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
            $snippet = mb_substr(trim($respBody), 0, 800);
            // Providers use 413 for an oversized/token-limited request. It is
            // safe to try the next configured provider even when an older env
            // value omitted 413 from WEBCHAT_LLM_RETRY_STATUSES.
            $transient = $this->isTransientStatus($status);

            Log::warning('webchat.llm_http_error', [
                'api' => $this->provider['api'] ?? $this->cfg->llmApi,
                'path' => $path,
                'status' => $status,
                'model' => $this->provider['model'] ?? $this->cfg->llmModel,
                'body_snippet' => $snippet,
            ]);

            throw new WebchatLlmException(
                ($transient ? 'TRANSIENT' : 'NON_RETRYABLE') . ': status=' . $status . ' ' . ($snippet !== '' ? $snippet : $e->getMessage()),
                (string) ($this->provider['id'] ?? $this->cfg->llmActiveProvider),
                $status,
                $transient,
                $e
            );
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            throw new WebchatLlmException(
                'BAD_BODY',
                (string) ($this->provider['id'] ?? $this->cfg->llmActiveProvider)
            );
        }

        return $payload;
    }

    protected function isTransientStatus(int $status): bool
    {
        return $status === 0
            || $status === 413
            || in_array($status, $this->cfg->llmRetryStatuses, true);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, int>
     */
    private function mapUsage(array $usage): array
    {
        return [
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
            'cached_input_tokens' => (int) (
                $usage['prompt_tokens_details']['cached_tokens']
                ?? $usage['input_tokens_details']['cached_tokens']
                ?? 0
            ),
            'cache_write_tokens' => (int) (
                $usage['prompt_tokens_details']['cache_write_tokens']
                ?? $usage['input_tokens_details']['cache_write_tokens']
                ?? 0
            ),
            'reasoning_output_tokens' => (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
        ];
    }

    private function newUlid(): string
    {
        return strtolower(uniqid('ulid_', true));
    }

    /** @return array{provider:string,id:string,api:string} */
    private function modelMetadata(): array
    {
        return [
            'provider' => (string) ($this->provider['id'] ?? $this->cfg->llmActiveProvider),
            'id' => (string) ($this->provider['model'] ?? $this->cfg->llmModel),
            'api' => (string) ($this->provider['api'] ?? $this->cfg->llmApi),
        ];
    }
}
