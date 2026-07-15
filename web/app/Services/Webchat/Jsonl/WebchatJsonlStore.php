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

    /** Legacy thin index line — prefer appendConversationMeta. */
    public function appendSessionIndex(string $threadId, int $adminUserId, ?string $title = null): void
    {
        $now = microtime(true);
        $this->appendConversationMeta([
            'thread_id' => $threadId,
            'created_by_admin_user_id' => $adminUserId,
            'admin_user_id' => $adminUserId,
            'title' => $title,
            'title_source' => $title ? 'manual' : 'pending',
            'status' => 'active',
            'path' => $this->threadPath($threadId),
            'updated_at' => $now,
            'last_activity_at' => $now,
            'floor_holder_admin_id' => null,
            'floor_last_turn_at' => null,
            'active_turn_id' => null,
            'active_turn_initiator_admin_id' => null,
        ]);
    }

    public function appendConversationMeta(array $row): void
    {
        if (empty($row['thread_id'])) {
            throw new \InvalidArgumentException('thread_id required');
        }

        $path = $this->cfg->storageRoot . '/session_index.jsonl';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $now = microtime(true);
        $meta = array_merge([
            'title' => null,
            'title_source' => 'pending',
            'status' => 'active',
            'path' => $this->threadPath($row['thread_id']),
            'updated_at' => $now,
            'last_activity_at' => $now,
            'floor_holder_admin_id' => null,
            'floor_last_turn_at' => null,
            'active_turn_id' => null,
            'active_turn_initiator_admin_id' => null,
        ], $row);
        $meta['updated_at'] = $now;

        $bytes = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($path, $bytes, FILE_APPEND | LOCK_EX);
    }

    public function resolveConversation(string $threadId): ?array
    {
        $path = $this->cfg->storageRoot . '/session_index.jsonl';
        if (!file_exists($path)) {
            return null;
        }

        $found = null;
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
            if (($meta['thread_id'] ?? '') === $threadId) {
                $found = $meta;
            }
        }
        fclose($handle);

        return $found;
    }

    /** Shared M-rw: any authenticated admin may access non-deleted rooms. */
    public function canAccessConversation(string $threadId, int $adminUserId): bool
    {
        if ($adminUserId <= 0) {
            return false;
        }
        $row = $this->resolveConversation($threadId);
        if ($row === null) {
            return false;
        }
        return ($row['status'] ?? 'active') !== 'deleted';
    }

    /** @deprecated Prefer canAccessConversation (M-rw alias). */
    public function ownsThread(string $threadId, int $adminUserId): bool
    {
        return $this->canAccessConversation($threadId, $adminUserId);
    }

    /** adminUserId=0 → all conversations (shared list). */
    public function listThreadsForAdmin(int $adminUserId): array
    {
        $path = $this->cfg->storageRoot . '/session_index.jsonl';
        if (!file_exists($path)) {
            return [];
        }

        $seen = [];
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
            $match = $adminUserId === 0
                || (int) ($meta['admin_user_id'] ?? 0) === $adminUserId
                || (int) ($meta['created_by_admin_user_id'] ?? 0) === $adminUserId;
            if ($match) {
                $seen[$meta['thread_id']] = $meta;
            }
        }
        fclose($handle);

        $out = array_values($seen);
        usort($out, static function ($a, $b) {
            $aa = (float) ($a['last_activity_at'] ?? $a['updated_at'] ?? 0);
            $bb = (float) ($b['last_activity_at'] ?? $b['updated_at'] ?? 0);
            return $bb <=> $aa;
        });

        return $out;
    }

    public function listActiveConversations(): array
    {
        $rows = $this->listThreadsForAdmin(0);
        return array_values(array_filter($rows, static function ($r) {
            return ($r['status'] ?? 'active') !== 'deleted';
        }));
    }

    public function clearActiveTurn(string $threadId): void
    {
        $prev = $this->resolveConversation($threadId);
        if ($prev === null) {
            return;
        }
        $now = microtime(true);
        $this->appendConversationMeta(array_merge($prev, [
            'updated_at' => $now,
            'last_activity_at' => $now,
            'active_turn_id' => null,
            'active_turn_initiator_admin_id' => null,
        ]));
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
        if ($line->admin_user_id !== null) {
            $arr['admin_user_id'] = $line->admin_user_id;
        }
        if ($line->admin_display_name !== null) {
            $arr['admin_display_name'] = $line->admin_display_name;
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
