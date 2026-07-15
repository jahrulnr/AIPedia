<?php

namespace Tests\Feature;

use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\WebchatConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebchatTitleAndHousekeepTest extends TestCase
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
            'webchat.llm_stub' => true,
            'webchat.conversation_idle_ttl_days' => 7,
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_first_completed_turn_applies_auto_title()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $this->postJson("/aipedia/webchat/threads/{$threadId}/turns", [
            'message' => 'Bagaimana cara reset password admin?',
        ])->assertStatus(202);

        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $meta = $store->resolveConversation($threadId);

        $this->assertNotNull($meta);
        $this->assertSame('auto', $meta['title_source']);
        $this->assertNotEmpty($meta['title']);
        $this->assertStringContainsString('reset password', mb_strtolower($meta['title']));
    }

    public function test_manual_rename_locks_out_auto_overwrite()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $this->patchJson("/aipedia/webchat/threads/{$threadId}", [
            'title' => 'Judul Manual',
        ])->assertStatus(200)->assertJson(['title' => 'Judul Manual']);

        $this->postJson("/aipedia/webchat/threads/{$threadId}/turns", [
            'message' => 'halo lagi setelah rename',
        ])->assertStatus(202);

        $cfg = WebchatConfig::load();
        $meta = (new WebchatJsonlStore($cfg))->resolveConversation($threadId);
        $this->assertSame('manual', $meta['title_source']);
        $this->assertSame('Judul Manual', $meta['title']);
    }

    public function test_housekeep_deletes_idle_conversations()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $prev = $store->resolveConversation($threadId);
        $store->appendConversationMeta(array_merge($prev, [
            'last_activity_at' => microtime(true) - (8 * 24 * 60 * 60),
            'status' => 'active',
        ]));

        $this->artisan('webchat:housekeep-conversations')
            ->assertExitCode(0);

        $meta = $store->resolveConversation($threadId);
        $this->assertSame('deleted', $meta['status']);
        $this->assertFileDoesNotExist($this->tmpRoot . '/threads/' . $threadId . '.jsonl');
    }

    public function test_housekeep_skips_recent_conversations()
    {
        $created = $this->postJson('/aipedia/webchat/threads');
        $threadId = $created->json('thread_id');

        $this->artisan('webchat:housekeep-conversations')->assertExitCode(0);

        $cfg = WebchatConfig::load();
        $meta = (new WebchatJsonlStore($cfg))->resolveConversation($threadId);
        $this->assertSame('active', $meta['status']);
    }

    public function test_dashboard_includes_float_launcher()
    {
        $this->get('/dashboard')
            ->assertStatus(200)
            ->assertSee('id="wmFloat"', false)
            ->assertSee('webchat-float.js', false);
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
