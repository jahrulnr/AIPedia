<?php

namespace App\Jobs;

use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatTitleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessThreadTitleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $threadId;
    public int $adminUserId;
    public string $userExcerpt;
    public string $agentExcerpt;
    public string $locale;

    public function __construct(
        string $threadId,
        int $adminUserId,
        string $userExcerpt,
        string $agentExcerpt,
        string $locale = 'id'
    ) {
        $this->threadId = $threadId;
        $this->adminUserId = $adminUserId;
        $this->userExcerpt = $userExcerpt;
        $this->agentExcerpt = $agentExcerpt;
        $this->locale = $locale;
    }

    public function handle(): void
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $titles = new WebchatTitleService($cfg, $store);

        try {
            $title = $titles->generate($this->userExcerpt, $this->agentExcerpt, $this->locale);
        } catch (\Throwable $e) {
            $title = $titles->fallbackTitle($this->userExcerpt);
            Log::warning('webchat.title_fallback', [
                'thread_id' => $this->threadId,
                'message' => $e->getMessage(),
                'title' => $title,
            ]);
        }

        try {
            $titles->apply($this->threadId, $this->adminUserId, $title, 'auto');
            Log::info('webchat.title_applied', [
                'thread_id' => $this->threadId,
                'title' => $title,
            ]);
        } catch (\RuntimeException $e) {
            if (in_array($e->getMessage(), ['manual_locked', 'deleted', 'not_found'], true)) {
                Log::info('webchat.title_skip', [
                    'thread_id' => $this->threadId,
                    'reason' => $e->getMessage(),
                ]);
                return;
            }
            Log::warning('webchat.title_apply_failed', [
                'thread_id' => $this->threadId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
