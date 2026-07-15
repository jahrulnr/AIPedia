<?php

namespace App\Services\Webchat\Agent;

use App\Services\Webchat\WebchatConfig;

class WebchatLlmProviderState
{
    public function __construct(private WebchatConfig $cfg)
    {
    }

    /** @return array<string, array{failures:int, opened_at:float|null}> */
    public function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function record(string $providerId, bool $success): void
    {
        $path = $this->path();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('provider state lock failed');
        }
        $raw = stream_get_contents($handle);
        $state = json_decode($raw ?: '{}', true);
        $state = is_array($state) ? $state : [];
        $current = is_array($state[$providerId] ?? null) ? $state[$providerId] : [];
        $state[$providerId] = $success
            ? ['failures' => 0, 'opened_at' => null]
            : [
                'failures' => (int) ($current['failures'] ?? 0) + 1,
                'opened_at' => (int) ($current['failures'] ?? 0) + 1 >= $this->cfg->llmCircuitFailureThreshold
                    ? microtime(true)
                    : ($current['opened_at'] ?? null),
            ];
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES) . "\n");
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function isAvailable(string $providerId, array $state, float $now): bool
    {
        $openedAt = (float) ($state[$providerId]['opened_at'] ?? 0);
        return $openedAt <= 0 || ($now - $openedAt) >= $this->cfg->llmCircuitCooldownSec;
    }

    private function path(): string
    {
        return $this->cfg->storageRoot . '/llm/provider_state.json';
    }
}
