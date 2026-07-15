<?php

namespace App\Jobs;

use App\Services\Webchat\Agent\WebchatAllowlist;
use App\Services\Webchat\Agent\WebchatInvariants;
use App\Services\Webchat\Agent\WebchatLlmClient;
use App\Services\Webchat\Agent\WebchatPromptBuilder;
use App\Services\Webchat\Jsonl\JsonlLine;
use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\Jsonl\WebchatThreadLock;
use App\Services\Webchat\Tools\SearchDocsTool;
use App\Services\Webchat\Tools\WebchatToolRegistry;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatTitleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessChatTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $threadId;
    public string $turnId;
    public string $userMessage;
    public array $admin;
    public string $lockToken;
    /** When true, continue a failed turn without appending a new user_message. */
    public bool $resume;
    public int $timeout = 300;

    public function __construct(
        string $threadId,
        string $turnId,
        string $userMessage,
        array $admin,
        string $lockToken,
        bool $resume = false
    ) {
        $this->threadId = $threadId;
        $this->turnId = $turnId;
        $this->userMessage = $userMessage;
        $this->admin = $admin;
        $this->lockToken = $lockToken;
        $this->resume = $resume;
    }

    public function handle(): void
    {
        $cfg = WebchatConfig::load();
        $this->timeout = max(30, $cfg->turnJobTimeoutSec);
        $store = new WebchatJsonlStore($cfg);
        $lock = new WebchatThreadLock($cfg);

        Log::info($this->resume ? 'webchat.turn_resume' : 'webchat.turn_start', [
            'thread_id' => $this->threadId,
            'turn_id' => $this->turnId,
            'phase' => $cfg->phase,
            'llm_stub' => $cfg->llmStub,
            'llm_api' => $cfg->llmApi,
            'llm_model' => $cfg->llmModel,
            'resume' => $this->resume,
        ]);

        try {
            if ($this->resume) {
                $userText = $this->findTurnUserText($store);
                if ($userText === null) {
                    $this->appendLine($store, $cfg, [
                        'thread_id' => $this->threadId,
                        'turn_id' => $this->turnId,
                        'type' => 'turn.failed',
                        'id' => 'itm_' . $this->newUlid(),
                        'error' => [
                            'code' => 'not_found',
                            'message' => 'turn missing user_message',
                        ],
                    ]);
                    return;
                }
                $this->userMessage = $userText;
                $this->appendLine($store, $cfg, [
                    'thread_id' => $this->threadId,
                    'turn_id' => $this->turnId,
                    'type' => 'turn.resumed',
                    'id' => 'itm_' . $this->newUlid(),
                ]);
            } else {
                $this->appendLine($store, $cfg, [
                    'thread_id' => $this->threadId,
                    'turn_id' => $this->turnId,
                    'type' => 'user_message',
                    'id' => 'itm_' . $this->newUlid(),
                    'text' => $this->userMessage,
                    'admin_user_id' => (int) ($this->admin['admin_user_id'] ?? 0),
                    'admin_display_name' => (string) ($this->admin['admin_display_name'] ?? 'Admin'),
                ]);

                $this->appendLine($store, $cfg, [
                    'thread_id' => $this->threadId,
                    'turn_id' => $this->turnId,
                    'type' => 'turn.started',
                    'id' => 'itm_' . $this->newUlid(),
                ]);
            }

            $prev = $store->resolveConversation($this->threadId);
            $now = microtime(true);
            $store->appendConversationMeta(array_merge($prev ?? [], [
                'thread_id' => $this->threadId,
                'created_by_admin_user_id' => (int) ($prev['created_by_admin_user_id'] ?? $this->admin['admin_user_id'] ?? 0),
                'admin_user_id' => (int) ($prev['admin_user_id'] ?? $this->admin['admin_user_id'] ?? 0),
                'status' => 'active',
                'last_activity_at' => $now,
                'floor_holder_admin_id' => $prev['floor_holder_admin_id'] ?? ($this->admin['admin_user_id'] ?? null),
                'floor_last_turn_at' => $prev['floor_last_turn_at'] ?? $now,
                'active_turn_id' => $this->turnId,
                'active_turn_initiator_admin_id' => (int) ($this->admin['admin_user_id'] ?? 0),
            ]));

            if ($cfg->phase <= 1 || $cfg->llmStub) {
                $this->runStub($store, $cfg);
            } else {
                $this->runAgent($store, $cfg);
            }

            if ($this->turnCompleted($store)) {
                (new WebchatTitleService($cfg, $store))->scheduleIfNeeded(
                    $this->threadId,
                    (int) ($this->admin['admin_user_id'] ?? 0),
                    (string) ($this->admin['locale'] ?? 'id')
                );
            }

            Log::info('webchat.turn_completed', [
                'thread_id' => $this->threadId,
                'turn_id' => $this->turnId,
            ]);
        } catch (\Throwable $e) {
            $errorMessage = mb_substr($e->getMessage(), 0, 1000);
            Log::error('webchat.turn_failed', [
                'thread_id' => $this->threadId,
                'turn_id' => $this->turnId,
                'exception' => get_class($e),
                'message' => $errorMessage,
                'file' => $e->getFile() . ':' . $e->getLine(),
                'llm_api' => $cfg->llmApi,
                'llm_model' => $cfg->llmModel,
            ]);

            $this->appendLine($store, $cfg, [
                'thread_id' => $this->threadId,
                'turn_id' => $this->turnId,
                'type' => 'turn.failed',
                'id' => 'itm_' . $this->newUlid(),
                'error' => [
                    'code' => 'job_error',
                    'message' => $errorMessage,
                    'exception' => get_class($e),
                ],
            ]);
        } finally {
            try {
                $store->clearActiveTurn($this->threadId);
            } catch (\Throwable $e) {
                // best-effort
            }
            $lock->release($this->threadId, $this->lockToken);
        }
    }

    private function runStub(WebchatJsonlStore $store, WebchatConfig $cfg): void
    {
        $text = '(stub) received: ' . $this->userMessage;
        $this->appendLine($store, $cfg, [
            'thread_id' => $this->threadId,
            'turn_id' => $this->turnId,
            'type' => 'agent_message',
            'id' => 'itm_' . $this->newUlid(),
            'text' => $text,
        ]);

        $this->appendLine($store, $cfg, [
            'thread_id' => $this->threadId,
            'turn_id' => $this->turnId,
            'type' => 'turn.completed',
            'id' => 'itm_' . $this->newUlid(),
            'usage' => [
                'input_tokens' => 0,
                'cached_input_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_output_tokens' => 0,
            ],
        ]);
    }

    private function runAgent(WebchatJsonlStore $store, WebchatConfig $cfg): void
    {
        $allowlist = new WebchatAllowlist($cfg);
        $invariants = new WebchatInvariants($cfg, $allowlist);
        $prompts = new WebchatPromptBuilder($cfg);
        $searchDocs = new SearchDocsTool($cfg);
        $registry = new WebchatToolRegistry($cfg, $allowlist, $searchDocs);

        $invariants->assert();

        $lines = $store->readThread($this->threadId);
        $messages = $this->buildMessages($lines);
        $messages = $prompts->build($messages, $this->admin);

        $tools = $registry->schemas();
        $toolNames = [];
        foreach ($tools as $t) {
            if (!empty($t['function']['name'])) {
                $toolNames[] = $t['function']['name'];
            }
        }
        $invariants->assert($toolNames);

        $llm = new WebchatLlmClient($cfg);

        $rounds = 0;
        $lastToolOnly = false;
        $resp = [
            'usage' => [
                'input_tokens' => 0,
                'cached_input_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_output_tokens' => 0,
            ],
        ];
        while ($rounds < $cfg->maxToolRounds) {
            $rounds++;

            if ($this->isInterrupted($cfg)) {
                $this->appendLine($store, $cfg, [
                    'thread_id' => $this->threadId,
                    'turn_id' => $this->turnId,
                    'type' => 'turn.failed',
                    'id' => 'itm_' . $this->newUlid(),
                    'error' => ['code' => 'interrupted', 'message' => 'Stopped by user'],
                ]);
                return;
            }

            $resp = $llm->chat($messages, $tools);

            $reasoning = trim((string) ($resp['reasoning_text'] ?? ''));
            if ($reasoning !== '') {
                $this->appendLine($store, $cfg, [
                    'thread_id' => $this->threadId,
                    'turn_id' => $this->turnId,
                    'type' => 'reasoning',
                    'id' => 'itm_' . $this->newUlid(),
                    'text' => mb_substr($reasoning, 0, 12000),
                ]);
            }

            if ($resp['has_tool_calls']) {
                if (is_array($resp['assistant_message'] ?? null)) {
                    $messages[] = $resp['assistant_message'];
                }
                $lastToolOnly = true;

                foreach ($resp['tool_calls'] as $tc) {
                    $callId = $tc['id'] ?? ('call_' . $this->newUlid());

                    $this->appendLine($store, $cfg, [
                        'thread_id' => $this->threadId,
                        'turn_id' => $this->turnId,
                        'type' => 'tool_call',
                        'id' => 'itm_' . $this->newUlid(),
                        'call_id' => $callId,
                        'name' => $tc['name'],
                        'arguments' => $tc['arguments'],
                    ]);

                    Log::info('webchat.tool_call', [
                        'thread_id' => $this->threadId,
                        'turn_id' => $this->turnId,
                        'tool' => $tc['name'],
                        'call_id' => $callId,
                    ]);

                    $envelope = $registry->execute($tc['name'], $tc['arguments'], $this->admin);

                    $this->appendLine($store, $cfg, [
                        'thread_id' => $this->threadId,
                        'turn_id' => $this->turnId,
                        'type' => 'tool_result',
                        'id' => 'itm_' . $this->newUlid(),
                        'call_id' => $callId,
                        'envelope' => $envelope,
                    ]);

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $callId,
                        'content' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }

                continue;
            }

            $this->appendLine($store, $cfg, [
                'thread_id' => $this->threadId,
                'turn_id' => $this->turnId,
                'type' => 'agent_message',
                'id' => 'itm_' . $this->newUlid(),
                'text' => $resp['assistant_text'] !== '' ? $resp['assistant_text'] : '(empty model response)',
            ]);
            $lastToolOnly = false;
            break;
        }

        if ($rounds >= $cfg->maxToolRounds && $lastToolOnly) {
            $this->appendLine($store, $cfg, [
                'thread_id' => $this->threadId,
                'turn_id' => $this->turnId,
                'type' => 'agent_message',
                'id' => 'itm_' . $this->newUlid(),
                'text' => 'Stopped: max tool rounds reached. Please refine the question.',
            ]);
        }

        $this->appendLine($store, $cfg, [
            'thread_id' => $this->threadId,
            'turn_id' => $this->turnId,
            'type' => 'turn.completed',
            'id' => 'itm_' . $this->newUlid(),
            'usage' => $resp['usage'] ?? [
                'input_tokens' => 0,
                'cached_input_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_output_tokens' => 0,
            ],
        ]);
    }

    private function buildMessages(array $lines): array
    {
        $msgs = [];
        foreach ($lines as $line) {
            $type = $line['type'] ?? '';
            if ($type === 'user_message') {
                $msgs[] = ['role' => 'user', 'content' => $line['text'] ?? ''];
            } elseif ($type === 'agent_message') {
                $msgs[] = ['role' => 'assistant', 'content' => $line['text'] ?? ''];
            } elseif ($type === 'tool_call') {
                $msgs[] = [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [
                        [
                            'id' => $line['call_id'] ?? '',
                            'type' => 'function',
                            'function' => [
                                'name' => $line['name'] ?? '',
                                'arguments' => json_encode($line['arguments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                ];
            } elseif ($type === 'tool_result') {
                $msgs[] = [
                    'role' => 'tool',
                    'tool_call_id' => $line['call_id'] ?? '',
                    'content' => json_encode($line['envelope'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }
        return $msgs;
    }

    private function appendLine(WebchatJsonlStore $store, WebchatConfig $cfg, array $data): void
    {
        $line = new \App\Services\Webchat\Jsonl\JsonlLine($data);
        $line->ts = $line->ts ?? microtime(true);
        $store->appendThreadLine($line);
    }

    private function isInterrupted(WebchatConfig $cfg): bool
    {
        $candidates = [
            $cfg->storageRoot . '/interrupt/' . $this->threadId . '/' . $this->turnId . '.flag',
            storage_path('app/webchat/interrupt/' . $this->threadId . '/' . $this->turnId . '.flag'),
        ];
        foreach ($candidates as $flagPath) {
            if (file_exists($flagPath)) {
                @unlink($flagPath);
                return true;
            }
        }
        return false;
    }

    private function turnCompleted(WebchatJsonlStore $store): bool
    {
        $lines = $store->readThread($this->threadId);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if (($line['turn_id'] ?? '') !== $this->turnId) {
                continue;
            }
            if (($line['type'] ?? '') === 'turn.completed') {
                return true;
            }
            if (($line['type'] ?? '') === 'turn.failed') {
                return false;
            }
        }
        return false;
    }

    private function findTurnUserText(WebchatJsonlStore $store): ?string
    {
        foreach ($store->readThread($this->threadId) as $line) {
            if (($line['turn_id'] ?? '') !== $this->turnId) {
                continue;
            }
            if (($line['type'] ?? '') === 'user_message') {
                return (string) ($line['text'] ?? '');
            }
        }
        return null;
    }

    private function newUlid(): string
    {
        return strtolower(uniqid('ulid_', true));
    }
}
