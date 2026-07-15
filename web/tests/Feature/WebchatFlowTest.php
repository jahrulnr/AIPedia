<?php

namespace Tests\Feature;

use App\Services\Webchat\WebchatConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebchatFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = storage_path('testing/webchat_' . uniqid());
        mkdir($this->tmpRoot . '/threads', 0775, true);
        config(['webchat.storage_root' => $this->tmpRoot]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_create_thread_returns_201_with_thread_id()
    {
        $response = $this->postJson('/aipedia/webchat/threads');

        $response->assertStatus(201)
            ->assertJsonStructure(['thread_id', 'seq_head']);

        $this->assertStringStartsWith('thr_', $response->json('thread_id'));
        $this->assertSame(1, $response->json('seq_head'));
    }

    public function test_get_thread_returns_items_and_seq_head()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $response = $this->getJson("/aipedia/webchat/threads/{$threadId}?after_seq=0");

        $response->assertStatus(200)
            ->assertJsonStructure(['thread_id', 'seq_head', 'busy', 'items'])
            ->assertJsonCount(1, 'items');
    }

    public function test_start_turn_queues_and_completes_stub_turn()
    {
        config(['webchat.llm_stub' => true]);

        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $response = $this->postJson("/aipedia/webchat/threads/{$threadId}/turns", [
            'message' => 'halo',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['thread_id', 'turn_id', 'seq_head', 'status'])
            ->assertJson(['status' => 'queued']);

        $thread = $this->getJson("/aipedia/webchat/threads/{$threadId}?after_seq=0");
        $types = array_column($thread->json('items'), 'type');

        $this->assertContains('user_message', $types);
        $this->assertContains('turn.started', $types);
        $this->assertContains('agent_message', $types);
        $this->assertContains('turn.completed', $types);
    }

    public function test_start_turn_with_empty_message_returns_422()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $response = $this->postJson("/aipedia/webchat/threads/{$threadId}/turns", [
            'message' => '   ',
        ]);

        $response->assertStatus(422);
    }

    public function test_list_conversations_returns_shared_active_rooms()
    {
        $this->postJson('/aipedia/webchat/threads');
        $response = $this->getJson('/aipedia/webchat/conversations');

        $response->assertStatus(200)
            ->assertJsonStructure(['conversations']);
        $this->assertNotEmpty($response->json('conversations'));
    }

    public function test_start_turn_redacts_secrets_in_user_message()
    {
        config(['webchat.llm_stub' => true]);

        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $this->postJson("/aipedia/webchat/threads/{$threadId}/turns", [
            'message' => 'here is gsk_abcdefghijklmnopqrstuvwxyz123456',
        ])->assertStatus(202);

        $thread = $this->getJson("/aipedia/webchat/threads/{$threadId}?after_seq=0");
        $users = array_values(array_filter($thread->json('items'), static function ($i) {
            return ($i['type'] ?? '') === 'user_message';
        }));

        $this->assertNotEmpty($users);
        $this->assertStringContainsString('[REDACTED_GROQ_KEY]', $users[0]['text']);
        $this->assertArrayHasKey('admin_display_name', $users[0]);
    }

    public function test_get_thread_includes_floor_fields()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $response = $this->getJson("/aipedia/webchat/threads/{$threadId}?after_seq=0");
        $response->assertStatus(200)
            ->assertJsonStructure([
                'floor_holder_admin_id',
                'floor_remaining_sec',
                'active_turn_id',
                'active_turn_initiator_admin_id',
            ]);
    }

    public function test_events_stream_returns_sse_events()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $this->postJson("/aipedia/webchat/threads/{$threadId}/turns", [
            'message' => 'halo',
        ]);

        $response = $this->get("/aipedia/webchat/threads/{$threadId}/events?after_seq=0");

        $response->assertStatus(200)
            ->assertHeader('Content-Type');

        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
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
