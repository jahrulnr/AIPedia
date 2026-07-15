<?php

namespace App\Services\Webchat;

use App\Services\Webchat\Jsonl\WebchatJsonlStore;

class WebchatFloorLockedException extends \RuntimeException
{
    public int $holderAdminUserId;
    public int $remainingSec;

    public function __construct(int $holderAdminUserId, int $remainingSec)
    {
        parent::__construct('floor_locked');
        $this->holderAdminUserId = $holderAdminUserId;
        $this->remainingSec = $remainingSec;
    }
}

class WebchatSpeakFloor
{
    private WebchatConfig $cfg;
    private WebchatJsonlStore $store;

    public function __construct(WebchatConfig $cfg, WebchatJsonlStore $store)
    {
        $this->cfg = $cfg;
        $this->store = $store;
    }

    /** @return array{remaining_sec: int, holder_admin_user_id: int|null} */
    public function assert(string $threadId, int $adminUserId): array
    {
        $row = $this->store->resolveConversation($threadId);
        if ($row === null || (($row['status'] ?? 'active') === 'deleted')) {
            throw new \RuntimeException('not_found');
        }

        $ttl = max(1, $this->cfg->speakFloorTtlSec);
        $holder = isset($row['floor_holder_admin_id']) ? (int) $row['floor_holder_admin_id'] : null;

        if ($holder === null || $holder === $adminUserId) {
            return ['remaining_sec' => 0, 'holder_admin_user_id' => $holder];
        }

        $last = (float) ($row['floor_last_turn_at'] ?? 0);
        $elapsed = microtime(true) - $last;
        $remaining = (int) max(0, ceil($ttl - $elapsed));

        if ($remaining === 0) {
            return ['remaining_sec' => 0, 'holder_admin_user_id' => $holder];
        }

        throw new WebchatFloorLockedException($holder, $remaining);
    }

    public function acquire(string $threadId, int $adminUserId, string $turnId): void
    {
        $prev = $this->store->resolveConversation($threadId);
        if ($prev === null) {
            throw new \RuntimeException('not_found');
        }

        $now = microtime(true);
        $this->store->appendConversationMeta([
            'thread_id' => $threadId,
            'created_by_admin_user_id' => (int) ($prev['created_by_admin_user_id'] ?? $prev['admin_user_id'] ?? $adminUserId),
            'admin_user_id' => (int) ($prev['admin_user_id'] ?? $adminUserId),
            'title' => $prev['title'] ?? null,
            'title_source' => $prev['title_source'] ?? 'pending',
            'status' => 'active',
            'path' => $prev['path'] ?? ($this->cfg->storageRoot . '/threads/' . $threadId . '.jsonl'),
            'updated_at' => $now,
            'last_activity_at' => $now,
            'floor_holder_admin_id' => $adminUserId,
            'floor_last_turn_at' => $now,
            'active_turn_id' => $turnId,
            'active_turn_initiator_admin_id' => $adminUserId,
        ]);
    }

    public function floorRemainingSec(?array $row, int $callerAdminId): int
    {
        if ($row === null) {
            return 0;
        }
        $holder = isset($row['floor_holder_admin_id']) ? (int) $row['floor_holder_admin_id'] : null;
        if ($holder === null || $holder === $callerAdminId) {
            return 0;
        }
        $ttl = max(1, $this->cfg->speakFloorTtlSec);
        $last = (float) ($row['floor_last_turn_at'] ?? 0);
        return (int) max(0, ceil($ttl - (microtime(true) - $last)));
    }
}
