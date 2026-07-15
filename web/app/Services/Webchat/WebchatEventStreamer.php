<?php

namespace App\Services\Webchat;

use App\Services\Webchat\Jsonl\WebchatJsonlStore;

class WebchatEventStreamer
{
    private WebchatConfig $cfg;
    private WebchatJsonlStore $store;

    public function __construct(WebchatConfig $cfg, WebchatJsonlStore $store)
    {
        $this->cfg = $cfg;
        $this->store = $store;
    }

    public function stream(string $threadId, int $afterSeq, callable $emit, ?int $maxIterations = null): void
    {
        $cursor = $afterSeq;
        $lastPing = microtime(true);
        $iterations = 0;

        while (!connection_aborted()) {
            $lines = $this->store->readThread($threadId);

            foreach ($lines as $line) {
                if (!isset($line['seq']) || $line['seq'] <= $cursor) {
                    continue;
                }
                $ev = $this->mapLine($line);
                $emit('event: ' . $ev['event'] . "\n");
                $emit('id: ' . $line['seq'] . "\n");
                $emit('data: ' . json_encode($ev['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
                $cursor = $line['seq'];
            }

            if (microtime(true) - $lastPing > 15) {
                $emit(": ping\n\n");
                $lastPing = microtime(true);
            }

            if ($maxIterations !== null && ++$iterations >= $maxIterations) {
                break;
            }

            if (connection_aborted()) {
                break;
            }

            usleep(500000);
        }
    }

    public function mapLine(array $line): array
    {
        $type = $line['type'] ?? 'unknown';

        if (in_array($type, ['turn.started', 'turn_started'], true)) {
            return ['event' => 'turn.started', 'data' => $line];
        }
        if (in_array($type, ['turn.completed', 'turn_completed'], true)) {
            return ['event' => 'turn.completed', 'data' => $line];
        }
        if (in_array($type, ['turn.failed', 'turn_failed'], true)) {
            return ['event' => 'turn.failed', 'data' => $line];
        }
        if (in_array($type, ['thread.started', 'thread_started'], true)) {
            return ['event' => 'thread.started', 'data' => $line];
        }
        if ($type === 'agent_message_delta') {
            return ['event' => 'item.updated', 'data' => $this->wrapItem($line)];
        }
        if (in_array($type, ['user_message', 'agent_message', 'tool_call', 'tool_result'], true)) {
            return ['event' => 'item.completed', 'data' => $this->wrapItem($line)];
        }

        return ['event' => 'item.updated', 'data' => $line];
    }

    private function wrapItem(array $line): array
    {
        return [
            'type' => $line['type'] ?? null,
            'seq' => $line['seq'] ?? null,
            'thread_id' => $line['thread_id'] ?? null,
            'turn_id' => $line['turn_id'] ?? null,
            'ts' => $line['ts'] ?? null,
            'item' => $line,
        ];
    }
}
