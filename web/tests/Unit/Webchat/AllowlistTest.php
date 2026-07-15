<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\Agent\WebchatAllowlist;
use App\Services\Webchat\WebchatConfig;
use Tests\TestCase;

class AllowlistTest extends TestCase
{
    public function test_phase_1_has_no_tools()
    {
        $cfg = new WebchatConfig();
        $cfg->phase = 1;
        $allowlist = new WebchatAllowlist($cfg);

        $this->assertSame([], $allowlist->forPhase(1));
    }

    public function test_phase_2_has_read_only_docs_tools()
    {
        $cfg = new WebchatConfig();
        $cfg->phase = 2;
        $allowlist = new WebchatAllowlist($cfg);

        $this->assertSame(
            ['search_docs', 'list_dir', 'read_file', 'grep'],
            $allowlist->forPhase(2)
        );
    }

    public function test_phase_3_includes_discovery_tools()
    {
        $cfg = new WebchatConfig();
        $cfg->phase = 3;
        $allowlist = new WebchatAllowlist($cfg);

        $this->assertSame(
            ['search_docs', 'list_dir', 'read_file', 'grep', 'list_modules', 'search_admin_routes'],
            $allowlist->forPhase(3)
        );
    }

    public function test_phase_5_without_write_approval_excludes_mutations()
    {
        $cfg = new WebchatConfig();
        $cfg->phase = 5;
        $cfg->writeEnabled = false;
        $allowlist = new WebchatAllowlist($cfg);

        $allowed = $allowlist->forPhase(5, false);

        $this->assertNotContains('draft_mutation', $allowed);
        $this->assertNotContains('confirm_mutation', $allowed);
    }

    public function test_phase_5_with_write_approval_includes_mutations()
    {
        $cfg = new WebchatConfig();
        $cfg->phase = 5;
        $cfg->writeEnabled = true;
        $allowlist = new WebchatAllowlist($cfg);

        $allowed = $allowlist->forPhase(5, true);

        $this->assertContains('draft_mutation', $allowed);
        $this->assertContains('confirm_mutation', $allowed);
    }
}
