<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\WebchatRedactSecrets;
use App\Services\Webchat\WebchatTurnRateLimit;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatRateLimitedException;
use Tests\TestCase;

class RedactAndRateLimitTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = storage_path('testing/webchat_' . uniqid());
        mkdir($this->tmpRoot . '/threads', 0775, true);
        config([
            'webchat.storage_root' => $this->tmpRoot,
            'webchat.turn_rate_limit_per_min' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_redact_secrets_masks_groq_and_openai_keys()
    {
        $text = 'key gsk_abcdefghijklmnopqrstuvwxyz123456 and sk-abcdefghijklmnopqrstuvwx';
        $out = WebchatRedactSecrets::redact($text);

        $this->assertStringContainsString('[REDACTED_GROQ_KEY]', $out['redacted_text']);
        $this->assertStringContainsString('[REDACTED_OPENAI_KEY]', $out['redacted_text']);
        $this->assertNotEmpty($out['findings']);
    }

    public function test_rate_limit_raises_after_limit()
    {
        $cfg = WebchatConfig::load();
        $rl = new WebchatTurnRateLimit($cfg);

        $rl->assert(7);
        $rl->assert(7);
        $rl->assert(7);

        $this->expectException(WebchatRateLimitedException::class);
        $rl->assert(7);
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
