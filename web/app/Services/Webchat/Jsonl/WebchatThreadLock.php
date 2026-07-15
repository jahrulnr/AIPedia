<?php

namespace App\Services\Webchat\Jsonl;

use App\Services\Webchat\WebchatConfig;

class WebchatThreadLock
{
    private WebchatConfig $cfg;

    public function __construct(WebchatConfig $cfg)
    {
        $this->cfg = $cfg;
    }

    public function tryAcquire(string $threadId, int $ttlSec = 300): array
    {
        $path = $this->lockPath($threadId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'c+');
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new WebchatBusyException();
        }

        $token = $this->newUlid();
        $record = [
            'thread_id' => $threadId,
            'token' => $token,
            'expires_at' => time() + $ttlSec,
            'acquired_at' => time(),
        ];

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $record;
    }

    public function release(string $threadId, string $token): void
    {
        $path = $this->lockPath($threadId);
        if (!file_exists($path)) {
            return;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return;
        }

        $record = json_decode($raw, true);
        if ($record === null || $record['token'] !== $token) {
            error_log('lock_token_mismatch: ' . $threadId);
            return;
        }

        @unlink($path);
    }

    public function isBusy(string $threadId): bool
    {
        $path = $this->lockPath($threadId);
        if (!file_exists($path)) {
            return false;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return false;
        }

        $record = json_decode($raw, true);
        if ($record === null) {
            return false;
        }

        if (time() > (int) $record['expires_at']) {
            @unlink($path);
            return false;
        }

        return true;
    }

    private function lockPath(string $threadId): string
    {
        return $this->cfg->storageRoot . '/threads/' . $threadId . '.lock';
    }

    private function newUlid(): string
    {
        return strtolower(uniqid('ulid_', true));
    }
}
