<?php

namespace App\Services\Webchat\Jsonl;

use App\Services\Webchat\WebchatConfig;

class WebchatJsonlStore
{
    private WebchatConfig $cfg;

    public function __construct(WebchatConfig $cfg)
    {
        $this->cfg = $cfg;
    }

    public function appendThreadLine(JsonlLine $line): JsonlLine
    {
        if (empty($line->thread_id) || empty($line->type) || empty($line->id)) {
            throw new \InvalidArgumentException('thread_id, type, id required');
        }

        $line->seq = $this->nextSeq($line->thread_id);
        $line->ts = $line->ts ?? microtime(true);

        $path = $this->threadPath($line->thread_id);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $bytes = json_encode($this->cleanLine($line), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($path, $bytes, FILE_APPEND | LOCK_EX);

        return $line;
    }

    public function readThread(string $threadId): array
    {
        $path = $this->threadPath($threadId);
        if (!file_exists($path)) {
            return [];
        }

        $lines = [];
        $handle = fopen($path, 'r');
        while (($row = fgets($handle)) !== false) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }
            $obj = json_decode($row, true);
            if ($obj === null) {
                error_log('webchat jsonl corrupt line: ' . $threadId);
                continue;
            }
            $lines[] = $obj;
        }
        fclose($handle);
        return $lines;
    }

    public function appendSessionIndex(string $threadId, int $adminUserId, ?string $title = null): void
    {
        $path = $this->cfg->storageRoot . '/session_index.jsonl';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $meta = [
            'thread_id' => $threadId,
            'title' => $title,
            'admin_user_id' => $adminUserId,
            'path' => $this->threadPath($threadId),
            'updated_at' => microtime(true),
        ];

        $bytes = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($path, $bytes, FILE_APPEND | LOCK_EX);
    }

    public function listThreadsForAdmin(int $adminUserId): array
    {
        $path = $this->cfg->storageRoot . '/session_index.jsonl';
        if (!file_exists($path)) {
            return [];
        }

        $seen = [];
        $out = [];
        $handle = fopen($path, 'r');
        while (($row = fgets($handle)) !== false) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }
            $meta = json_decode($row, true);
            if ($meta === null) {
                continue;
            }
            if ((int) $meta['admin_user_id'] === $adminUserId) {
                $seen[$meta['thread_id']] = $meta;
            }
        }
        fclose($handle);

        foreach ($seen as $meta) {
            $out[] = $meta;
        }
        return $out;
    }

    public function ownsThread(string $threadId, int $adminUserId): bool
    {
        $path = $this->cfg->storageRoot . '/session_index.jsonl';
        if (!file_exists($path)) {
            return false;
        }

        $handle = fopen($path, 'r');
        $found = null;
        while (($row = fgets($handle)) !== false) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }
            $meta = json_decode($row, true);
            if ($meta === null) {
                continue;
            }
            if ($meta['thread_id'] === $threadId) {
                $found = $meta;
            }
        }
        fclose($handle);

        if ($found === null) {
            return false;
        }

        return (int) $found['admin_user_id'] === $adminUserId;
    }

    private function threadPath(string $threadId): string
    {
        return $this->cfg->storageRoot . '/threads/' . $threadId . '.jsonl';
    }

    private function nextSeq(string $threadId): int
    {
        $path = $this->cfg->storageRoot . '/threads/' . $threadId . '.seq';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'c+');
        if (!flock($handle, LOCK_EX)) {
            throw new \RuntimeException('seq lock failed');
        }

        $last = 0;
        $content = stream_get_contents($handle, -1, 0);
        if ($content !== false && $content !== '') {
            $last = (int) trim($content);
        }

        $next = $last + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) $next);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $next;
    }

    private function cleanLine(JsonlLine $line): array
    {
        $arr = [
            'seq' => $line->seq,
            'ts' => $line->ts,
            'thread_id' => $line->thread_id,
            'type' => $line->type,
            'id' => $line->id,
        ];

        if ($line->turn_id !== null) {
            $arr['turn_id'] = $line->turn_id;
        }
        if ($line->text !== null) {
            $arr['text'] = $line->text;
        }
        if ($line->call_id !== null) {
            $arr['call_id'] = $line->call_id;
        }
        if ($line->name !== null) {
            $arr['name'] = $line->name;
        }
        if ($line->arguments !== null) {
            $arr['arguments'] = $line->arguments;
        }
        if ($line->envelope !== null) {
            $arr['envelope'] = $line->envelope;
        }
        if ($line->usage !== null) {
            $arr['usage'] = $line->usage;
        }
        if ($line->error !== null) {
            $arr['error'] = $line->error;
        }

        return $arr;
    }
}
