<?php

namespace App\Services\Webchat;

use App\Jobs\ProcessThreadTitleJob;
use App\Services\Webchat\Agent\WebchatLlmClient;
use App\Services\Webchat\Jsonl\WebchatJsonlStore;

class WebchatTitleService
{
    private WebchatConfig $cfg;
    private WebchatJsonlStore $store;

    public function __construct(WebchatConfig $cfg, WebchatJsonlStore $store)
    {
        $this->cfg = $cfg;
        $this->store = $store;
    }

    public function scheduleIfNeeded(string $threadId, int $adminUserId, string $locale = 'id'): void
    {
        $meta = $this->store->resolveConversation($threadId);
        if ($meta === null || ($meta['status'] ?? '') === 'deleted') {
            return;
        }
        $source = $meta['title_source'] ?? 'pending';
        if ($source === 'manual' || ($source === 'auto' && !empty($meta['title']))) {
            return;
        }

        $lines = $this->store->readThread($threadId);
        $userExcerpt = '';
        $agentExcerpt = '';
        foreach ($lines as $line) {
            if ($userExcerpt === '' && ($line['type'] ?? '') === 'user_message') {
                $userExcerpt = (string) ($line['text'] ?? '');
            }
            if ($agentExcerpt === '' && ($line['type'] ?? '') === 'agent_message') {
                $agentExcerpt = (string) ($line['text'] ?? '');
            }
            if ($userExcerpt !== '' && $agentExcerpt !== '') {
                break;
            }
        }
        if ($userExcerpt === '' || $agentExcerpt === '') {
            return;
        }

        ProcessThreadTitleJob::dispatch($threadId, $adminUserId, $userExcerpt, $agentExcerpt, $locale);
    }

    public function generate(string $userExcerpt, string $agentExcerpt, string $locale = 'id'): string
    {
        $userExcerpt = trim($userExcerpt);
        if ($userExcerpt === '') {
            throw new \InvalidArgumentException('empty_input');
        }
        $userClip = mb_substr($userExcerpt, 0, 400);
        $agentClip = mb_substr(trim($agentExcerpt), 0, 200);

        if ($this->cfg->llmStub) {
            $title = mb_substr($userClip, 0, 60);
        } else {
            $llm = new WebchatLlmClient($this->cfg);
            $resp = $llm->chat([
                [
                    'role' => 'system',
                    'content' => 'Generate a short chat title. Locale=' . $locale . '. Max 60 chars. No quotes. No tools.',
                ],
                [
                    'role' => 'user',
                    'content' => "User: {$userClip}\nAssistant: {$agentClip}\nTitle:",
                ],
            ], []);
            $title = trim((string) ($resp['assistant_text'] ?? ''));
        }

        $title = preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $title));
        $title = mb_substr(trim($title), 0, 60);
        if ($title === '') {
            throw new \RuntimeException('bad_title');
        }
        return $title;
    }

    /**
     * Deterministic title used when the optional title LLM is unavailable.
     * Title generation must never leave a completed conversation pending.
     */
    public function fallbackTitle(string $userExcerpt): string
    {
        $title = preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', trim($userExcerpt)));
        $title = mb_substr(trim((string) $title), 0, 60);

        return $title !== '' ? $title : 'New chat';
    }

    public function apply(string $threadId, int $adminUserId, string $title, string $source): void
    {
        $title = mb_substr(trim($title), 0, 60);
        if ($title === '' || !in_array($source, ['auto', 'manual'], true)) {
            throw new \InvalidArgumentException('invalid_title');
        }

        $prev = $this->store->resolveConversation($threadId);
        if ($prev === null) {
            throw new \RuntimeException('not_found');
        }
        if (($prev['status'] ?? '') === 'deleted') {
            throw new \RuntimeException('deleted');
        }
        if ($source === 'auto' && ($prev['title_source'] ?? '') === 'manual') {
            throw new \RuntimeException('manual_locked');
        }

        $this->store->appendConversationMeta(array_merge($prev, [
            'thread_id' => $threadId,
            'admin_user_id' => (int) ($prev['admin_user_id'] ?? $adminUserId),
            'created_by_admin_user_id' => (int) ($prev['created_by_admin_user_id'] ?? $adminUserId),
            'title' => $title,
            'title_source' => $source,
            'status' => 'active',
            'last_activity_at' => $prev['last_activity_at'] ?? microtime(true),
        ]));
    }
}
