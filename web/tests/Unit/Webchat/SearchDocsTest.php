<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\Tools\SearchDocsTool;
use App\Services\Webchat\WebchatConfig;
use Tests\TestCase;

class SearchDocsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = storage_path('testing/docs_' . uniqid());
        mkdir($this->tmpRoot . '/sample', 0775, true);

        file_put_contents(
            $this->tmpRoot . '/sample/voucher.md',
            "# Voucher Guide\n\nCara membuat voucher di CMS.",
        );
        file_put_contents(
            $this->tmpRoot . '/sample/package.md',
            "# Package Guide\n\nCara membuat package di CMS.",
        );

        config([
            'webchat.docs_root' => $this->tmpRoot,
            'webchat.docs_top_k' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_search_docs_returns_hits_for_matching_query()
    {
        $cfg = WebchatConfig::load();
        $tool = new SearchDocsTool($cfg);

        $result = $tool->execute(['query' => 'voucher']);

        $this->assertTrue($result['ok']);
        $this->assertSame('search_docs', $result['tool']);
        $this->assertCount(1, $result['data']['chunks']);
        $this->assertSame('sample/voucher.md', $result['data']['chunks'][0]['path']);
        $this->assertSame('Voucher Guide', $result['data']['chunks'][0]['title']);
    }

    public function test_search_docs_returns_empty_for_unknown_query()
    {
        $cfg = WebchatConfig::load();
        $tool = new SearchDocsTool($cfg);

        $result = $tool->execute(['query' => 'xyzunknown']);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['data']['chunks']);
    }

    public function test_search_docs_fails_without_query()
    {
        $cfg = WebchatConfig::load();
        $tool = new SearchDocsTool($cfg);

        $result = $tool->execute([]);

        $this->assertFalse($result['ok']);
        $this->assertSame('validation', $result['error']['code']);
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
