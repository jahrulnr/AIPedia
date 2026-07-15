<?php

namespace Tests\Feature;

use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\WebchatConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebchatFloorTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = storage_path('testing/webchat_' . uniqid());
        mkdir($this->tmpRoot . '/threads', 0775, true);
        config([
            'webchat.storage_root' => $this->tmpRoot,
            'webchat.speak_floor_ttl_sec' => 600,
            'webchat.llm_stub' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_second_admin_gets_floor_locked()
    {
        $this->withSession(['admin_user_id' => 1, 'admin_display_name' => 'A']);
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $this->postJson("/aipedia/webchat/threads/{$threadId}/turns", [
            'message' => 'from A',
        ])->assertStatus(202);

        $this->withSession(['admin_user_id' => 2, 'admin_display_name' => 'B']);
        $locked = $this->postJson("/aipedia/webchat/threads/{$threadId}/turns", [
            'message' => 'from B',
        ]);

        $locked->assertStatus(423)
            ->assertJsonPath('code', 'floor_locked')
            ->assertJsonStructure(['holder_admin_user_id', 'remaining_sec']);
        $this->assertGreaterThan(0, $locked->json('remaining_sec'));
    }

    public function test_non_initiator_cannot_interrupt()
    {
        $this->withSession(['admin_user_id' => 1, 'admin_display_name' => 'A']);
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        // Manually set active turn as admin 1 without finishing job clear
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $store->appendConversationMeta([
            'thread_id' => $threadId,
            'created_by_admin_user_id' => 1,
            'admin_user_id' => 1,
            'status' => 'active',
            'active_turn_id' => 'trn_fake',
            'active_turn_initiator_admin_id' => 1,
            'floor_holder_admin_id' => 1,
            'floor_last_turn_at' => microtime(true),
        ]);

        $this->withSession(['admin_user_id' => 2, 'admin_display_name' => 'B']);
        $this->postJson("/aipedia/webchat/threads/{$threadId}/interrupt", [
            'turn_id' => 'trn_fake',
        ])->assertStatus(403);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
