<?php

namespace App\Services\Webchat;

use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use Illuminate\Support\Facades\Log;

class WebchatConversationCleanup
{
    private WebchatConfig $cfg;
    private WebchatJsonlStore $store;

    public function __construct(WebchatConfig $cfg, WebchatJsonlStore $store)
    {
        $this->cfg = $cfg;
        $this->store = $store;
    }

    /**
     * Soft-delete conversations idle longer than configured TTL.
     *
     * @return array{deleted:int,skipped_busy:int}
     */
    public function housekeep(?float $now = null): array
    {
        $now = $now ?? microtime(true);
        $ttlDays = max(1, $this->cfg->conversationIdleTtlDays);
        $cutoff = $now - ($ttlDays * 24 * 60 * 60);
        $deleted = 0;
        $skippedBusy = 0;

        foreach ($this->store->listActiveConversations() as $meta) {
            $last = (float) ($meta['last_activity_at'] ?? $meta['updated_at'] ?? 0);
            if ($last > $cutoff) {
                continue;
            }
            $tid = (string) ($meta['thread_id'] ?? '');
            if ($tid === '') {
                continue;
            }
            try {
                $this->store->deleteConversation(
                    $tid,
                    (int) ($meta['admin_user_id'] ?? 0),
                    false
                );
                $deleted++;
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'busy') {
                    $skippedBusy++;
                    continue;
                }
                Log::warning('webchat.hk_delete_failed', [
                    'thread_id' => $tid,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        Log::info('webchat.hk_done', [
            'deleted' => $deleted,
            'skipped_busy' => $skippedBusy,
        ]);

        return [
            'deleted' => $deleted,
            'skipped_busy' => $skippedBusy,
        ];
    }
}
