<?php

namespace Tests\Feature;

use App\Services\Webchat\Jsonl\JsonlLine;
use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatDocsIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebchatRetryTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = storage_path('testing/webchat_' . uniqid());
        mkdir($this->tmpRoot . '/threads', 0775, true);
        mkdir($this->tmpRoot . '/docs', 0775, true);
        file_put_contents($this->tmpRoot . '/docs/seed.md', "# Seed\n\ntest");
        config([
            'webchat.storage_root' => $this->tmpRoot,
            'webchat.docs_root' => $this->tmpRoot . '/docs',
            'webchat.llm_stub' => true,
        ]);
        (new WebchatDocsIndex(WebchatConfig::load()))->build();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_retry_continues_same_turn_without_new_user_message()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');
        $turnId = 'trn_retry_' . uniqid();

        $this->seedFailedTurn($threadId, $turnId, 'anti-pattern ada apa aja?', false);

        $response = $this->postJson("/aipedia/webchat/threads/{$threadId}/retry", [
            'turn_id' => $turnId,
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'thread_id' => $threadId,
                'turn_id' => $turnId,
                'status' => 'queued',
            ]);

        $thread = $this->getJson("/aipedia/webchat/threads/{$threadId}?after_seq=0");
        $items = $thread->json('items');
        $types = array_column($items, 'type');

        $this->assertContains('turn.resumed', $types);
        $this->assertContains('agent_message', $types);
        $this->assertContains('turn.completed', $types);
        $this->assertContains('tool_call', $types);
        $this->assertContains('tool_result', $types);

        $userForTurn = array_values(array_filter($items, static function ($i) use ($turnId) {
            return ($i['type'] ?? '') === 'user_message' && ($i['turn_id'] ?? '') === $turnId;
        }));
        $this->assertCount(1, $userForTurn);

        $resumed = array_values(array_filter($items, static function ($i) use ($turnId) {
            return ($i['type'] ?? '') === 'turn.resumed' && ($i['turn_id'] ?? '') === $turnId;
        }));
        $this->assertCount(1, $resumed);
    }

    public function test_retry_rejects_interrupted_and_completed()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $interruptedId = 'trn_intr_' . uniqid();
        $this->seedFailedTurn($threadId, $interruptedId, 'stop me', true);
        $this->postJson("/aipedia/webchat/threads/{$threadId}/retry", [
            'turn_id' => $interruptedId,
        ])->assertStatus(409)->assertJson(['code' => 'not_retryable']);

        $okId = 'trn_ok_' . uniqid();
        $this->seedFailedTurn($threadId, $okId, 'retry me', false);
        $this->postJson("/aipedia/webchat/threads/{$threadId}/retry", [
            'turn_id' => $okId,
        ])->assertStatus(202);

        $this->postJson("/aipedia/webchat/threads/{$threadId}/retry", [
            'turn_id' => $okId,
        ])->assertStatus(409)->assertJson(['code' => 'not_retryable']);
    }

    public function test_map_line_emits_turn_resumed_sse()
    {
        $streamer = new \App\Services\Webchat\WebchatEventStreamer(
            WebchatConfig::load(),
            new WebchatJsonlStore(WebchatConfig::load())
        );
        $event = $streamer->mapLine([
            'seq' => 9,
            'type' => 'turn.resumed',
            'thread_id' => 'thr_x',
            'turn_id' => 'trn_x',
        ]);
        $this->assertSame('turn.resumed', $event['event']);
        $this->assertSame('trn_x', $event['data']['turn_id']);
    }

    private function seedFailedTurn(string $threadId, string $turnId, string $text, bool $interrupted): void
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $now = microtime(true);

        $store->appendThreadLine(new JsonlLine([
            'thread_id' => $threadId,
            'turn_id' => $turnId,
            'type' => 'user_message',
            'id' => 'itm_' . uniqid(),
            'text' => $text,
            'admin_user_id' => 1,
            'admin_display_name' => 'Admin',
        ]));
        $store->appendThreadLine(new JsonlLine([
            'thread_id' => $threadId,
            'turn_id' => $turnId,
            'type' => 'turn.started',
            'id' => 'itm_' . uniqid(),
        ]));
        $store->appendThreadLine(new JsonlLine([
            'thread_id' => $threadId,
            'turn_id' => $turnId,
            'type' => 'tool_call',
            'id' => 'itm_' . uniqid(),
            'call_id' => 'call_' . uniqid(),
            'name' => 'search_docs',
            'arguments' => ['query' => 'anti-pattern'],
        ]));
        $store->appendThreadLine(new JsonlLine([
            'thread_id' => $threadId,
            'turn_id' => $turnId,
            'type' => 'tool_result',
            'id' => 'itm_' . uniqid(),
            'call_id' => 'call_seed',
            'envelope' => ['ok' => true, 'hits' => 5],
        ]));
        $store->appendThreadLine(new JsonlLine([
            'thread_id' => $threadId,
            'turn_id' => $turnId,
            'type' => 'turn.failed',
            'id' => 'itm_' . uniqid(),
            'error' => [
                'code' => $interrupted ? 'interrupted' : 'rate_limit_exceeded',
                'message' => $interrupted ? 'Stopped by user' : 'HTTP_5XX: status=429 TPM',
            ],
        ]));

        $prev = $store->resolveConversation($threadId) ?? [];
        $store->appendConversationMeta(array_merge($prev, [
            'thread_id' => $threadId,
            'status' => 'active',
            'last_activity_at' => $now,
            'floor_holder_admin_id' => 1,
            'floor_last_turn_at' => $now,
            'active_turn_id' => null,
            'active_turn_initiator_admin_id' => null,
        ]));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
