<?php

namespace Tests\Feature;

use App\Jobs\ReindexWebchatDocsJob;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatDocsIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebchatDocsIndexTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpRoot;
    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = storage_path('testing/docs_' . uniqid());
        $this->tmpStorage = storage_path('testing/webchat_idx_' . uniqid());
        mkdir($this->tmpRoot . '/a', 0775, true);
        mkdir($this->tmpStorage, 0775, true);
        file_put_contents($this->tmpRoot . '/a/hello.md', "# Hello\n\nindexing docs search");

        config([
            'webchat.docs_root' => $this->tmpRoot,
            'webchat.storage_root' => $this->tmpStorage,
        ]);
        // Default for most tests: usable index. Blocking tests call beginReindex().
        (new WebchatDocsIndex(WebchatConfig::load()))->build();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        $this->rrmdir($this->tmpStorage);
        parent::tearDown();
    }

    public function test_reindex_command_dispatches_queue_job()
    {
        Queue::fake();

        $this->artisan('webchat:reindex-docs')
            ->expectsOutput('docs reindex queued (AI locked until ready)')
            ->assertExitCode(0);

        Queue::assertPushed(ReindexWebchatDocsJob::class);
        $this->assertSame('building', (new WebchatDocsIndex(WebchatConfig::load()))->status());
    }

    public function test_create_and_turn_blocked_while_building()
    {
        (new WebchatDocsIndex(WebchatConfig::load()))->beginReindex();

        $this->postJson('/aipedia/webchat/threads')
            ->assertStatus(503)
            ->assertJsonPath('code', 'docs_index_not_ready');

        // Create a thread by temporarily making index ready, then lock again for turn.
        $this->artisan('webchat:reindex-docs', ['--sync' => true])->assertExitCode(0);
        $created = $this->postJson('/aipedia/webchat/threads')->assertStatus(201);
        $threadId = $created->json('thread_id');

        (new WebchatDocsIndex(WebchatConfig::load()))->beginReindex();

        $this->postJson("/aipedia/webchat/threads/{$threadId}/turns", ['message' => 'halo'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'docs_index_not_ready');
    }

    public function test_reindex_sync_builds_index_file()
    {
        $this->artisan('webchat:reindex-docs', ['--sync' => true])
            ->assertExitCode(0);

        $index = new WebchatDocsIndex(WebchatConfig::load());
        $this->assertTrue($index->ready());
        $data = $index->load();
        $this->assertSame(1, $data['document_count']);
        $this->assertSame('a/hello.md', $data['documents'][0]['path']);
    }

    public function test_job_builds_index()
    {
        (new ReindexWebchatDocsJob())->handle();

        $index = new WebchatDocsIndex(WebchatConfig::load());
        $hits = $index->search('indexing', 5);
        $this->assertNotEmpty($hits);
        $this->assertSame('a/hello.md', $hits[0]['path']);
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
