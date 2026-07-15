<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\Tools\WebchatDocsFilesystem;
use App\Services\Webchat\WebchatConfig;
use Tests\TestCase;

class DocsFilesystemTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('testing/docs_fs_' . uniqid());
        mkdir($this->root . '/nested', 0775, true);
        file_put_contents($this->root . '/nested/guide.md', "# Guide\n\nsearchable content");
        file_put_contents($this->root . '/secret.txt', 'not readable');
        config(['webchat.docs_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function test_lists_reads_and_greps_only_docs_files(): void
    {
        $tool = new WebchatDocsFilesystem(WebchatConfig::load());

        $this->assertTrue($tool->listDir(['path' => ''])['ok']);
        $read = $tool->readFile(['path' => 'nested/guide.md']);
        $this->assertTrue($read['ok']);
        $this->assertStringContainsString('searchable content', $read['data']['content']);

        $grep = $tool->grep(['query' => 'searchable', 'path' => '']);
        $this->assertTrue($grep['ok']);
        $this->assertSame('nested/guide.md', $grep['data']['matches'][0]['path']);

        $this->assertFalse($tool->readFile(['path' => 'secret.txt'])['ok']);
    }

    public function test_rejects_path_traversal(): void
    {
        $tool = new WebchatDocsFilesystem(WebchatConfig::load());

        $this->expectException(\InvalidArgumentException::class);
        $tool->readFile(['path' => '../../.env']);
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            is_dir($path) ? $this->remove($path) : unlink($path);
        }
        rmdir($dir);
    }
}
