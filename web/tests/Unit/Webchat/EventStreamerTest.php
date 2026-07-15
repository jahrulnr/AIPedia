<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\Jsonl\JsonlLine;
use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatEventStreamer;
use Tests\TestCase;

class EventStreamerTest extends TestCase
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

    public function test_stream_emits_sse_events_for_lines()
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $streamer = new WebchatEventStreamer($cfg, $store);

        $store->appendThreadLine(new JsonlLine([
            'thread_id' => 'thr_test',
            'type' => 'thread.started',
            'id' => 'itm_1',
        ]));
        $store->appendThreadLine(new JsonlLine([
            'thread_id' => 'thr_test',
            'type' => 'user_message',
            'id' => 'itm_2',
            'text' => 'hello',
        ]));

        $output = '';
        $streamer->stream('thr_test', 0, static function ($bytes) use (&$output) {
            $output .= $bytes;
        }, 1);

        $this->assertStringContainsString('event: thread.started', $output);
        $this->assertStringContainsString('event: item.completed', $output);
        $this->assertStringContainsString('"text":"hello"', $output);
    }

    public function test_map_line_wraps_item_completed()
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $streamer = new WebchatEventStreamer($cfg, $store);

        $event = $streamer->mapLine([
            'seq' => 2,
            'type' => 'user_message',
            'thread_id' => 'thr_test',
            'text' => 'hello',
        ]);

        $this->assertSame('item.completed', $event['event']);
        $this->assertSame('user_message', $event['data']['item']['type']);
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
