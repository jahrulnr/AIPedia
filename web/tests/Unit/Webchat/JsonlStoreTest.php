<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\Jsonl\JsonlLine;
use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\WebchatConfig;
use Tests\TestCase;

class JsonlStoreTest extends TestCase
{
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

    public function test_append_thread_line_assigns_increasing_sequence()
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);

        $line1 = $store->appendThreadLine(new JsonlLine([
            'thread_id' => 'thr_test',
            'type' => 'user_message',
            'id' => 'itm_1',
            'text' => 'hello',
        ]));
        $line2 = $store->appendThreadLine(new JsonlLine([
            'thread_id' => 'thr_test',
            'type' => 'agent_message',
            'id' => 'itm_2',
            'text' => 'hi',
        ]));

        $this->assertSame(1, $line1->seq);
        $this->assertSame(2, $line2->seq);
    }

    public function test_read_thread_returns_lines_in_order()
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);

        $store->appendThreadLine(new JsonlLine([
            'thread_id' => 'thr_test',
            'type' => 'user_message',
            'id' => 'itm_1',
            'text' => 'first',
        ]));
        $store->appendThreadLine(new JsonlLine([
            'thread_id' => 'thr_test',
            'type' => 'agent_message',
            'id' => 'itm_2',
            'text' => 'second',
        ]));

        $lines = $store->readThread('thr_test');

        $this->assertCount(2, $lines);
        $this->assertSame('first', $lines[0]['text']);
        $this->assertSame('second', $lines[1]['text']);
        $this->assertSame(1, $lines[0]['seq']);
        $this->assertSame(2, $lines[1]['seq']);
    }

    public function test_owns_thread_respects_session_index()
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);

        $store->appendSessionIndex('thr_test', 42, 'Demo thread');

        $this->assertTrue($store->ownsThread('thr_test', 42));
        $this->assertFalse($store->ownsThread('thr_test', 99));
    }

    public function test_missing_thread_returns_empty_array()
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);

        $this->assertSame([], $store->readThread('thr_does_not_exist'));
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
