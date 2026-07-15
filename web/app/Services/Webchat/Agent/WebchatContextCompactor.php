<?php

namespace App\Services\Webchat\Agent;

use App\Services\Webchat\WebchatConfig;

class WebchatContextCompactor
{
    public function __construct(private WebchatConfig $cfg)
    {
    }

    /** @return array{summary:string,compacted_through_seq:int}|null */
    public function compact(array $lines): ?array
    {
        $threshold = max(1000, $this->cfg->llmInputBudget() - $this->cfg->contextReserveTokens);
        if (!$this->cfg->contextCompactionEnabled || $this->estimate($lines) <= $threshold) {
            return null;
        }
        $turns = [];
        foreach ($lines as $line) {
            $turn = (string) ($line['turn_id'] ?? '');
            if ($turn !== '' && !in_array($turn, $turns, true)) {
                $turns[] = $turn;
            }
        }
        $keepFrom = max(0, count($turns) - $this->cfg->contextRecentTurns);
        $oldTurnIds = array_slice($turns, 0, $keepFrom);
        if ($oldTurnIds === []) {
            return null;
        }
        $old = array_filter($lines, static fn (array $line): bool => in_array((string) ($line['turn_id'] ?? ''), $oldTurnIds, true));
        $parts = [];
        foreach ($old as $line) {
            $type = (string) ($line['type'] ?? '');
            if ($type === 'context_compacted') {
                $parts[] = (string) ($line['text'] ?? '');
                continue;
            }
            if (in_array($type, ['user_message', 'agent_message'], true)) {
                $label = $type === 'user_message' ? 'User' : 'Assistant';
                $parts[] = $label . ': ' . trim((string) ($line['text'] ?? ''));
            } elseif ($type === 'tool_call') {
                $parts[] = 'Tool call: ' . (string) ($line['name'] ?? 'unknown');
            } elseif ($type === 'tool_result') {
                $parts[] = 'Tool result: ' . $this->shorten(json_encode($line['envelope'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        $summary = trim(implode("\n", $parts));
        if ($summary === '') {
            return null;
        }
        return [
            'summary' => $this->shorten($summary),
            'compacted_through_seq' => (int) max(array_map(static fn (array $line): int => (int) ($line['seq'] ?? 0), $old)),
        ];
    }

    private function estimate(array $lines): int
    {
        $chars = 0;
        foreach ($lines as $line) {
            $chars += strlen((string) ($line['text'] ?? ''));
            $chars += strlen((string) json_encode($line['arguments'] ?? [], JSON_UNESCAPED_UNICODE));
            $chars += strlen((string) json_encode($line['envelope'] ?? [], JSON_UNESCAPED_UNICODE));
        }
        return (int) ceil($chars / 4);
    }

    private function shorten(string $text): string
    {
        return mb_substr($text, 0, $this->cfg->contextSummaryMaxChars);
    }
}
