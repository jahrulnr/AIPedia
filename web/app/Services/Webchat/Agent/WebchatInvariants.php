<?php

namespace App\Services\Webchat\Agent;

use App\Services\Webchat\WebchatConfig;

class WebchatInvariants
{
    private WebchatConfig $cfg;
    private WebchatAllowlist $allowlist;

    public function __construct(WebchatConfig $cfg, WebchatAllowlist $allowlist)
    {
        $this->cfg = $cfg;
        $this->allowlist = $allowlist;
    }

    public function assert(array $llmToolNames = []): void
    {
        if ($this->cfg->writeEnabled !== false) {
            throw new \RuntimeException('Invariant write_enabled must be false');
        }

        $allowed = $this->allowlist->forPhase($this->cfg->phase, $this->cfg->writeEnabled);

        foreach ($llmToolNames as $name) {
            if ($name === 'draft_mutation' || $name === 'confirm_mutation') {
                throw new \RuntimeException('Invariant bad_tool: mutation tool exposed');
            }
            if (!in_array($name, $allowed, true)) {
                throw new \RuntimeException('Invariant bad_tool: ' . $name);
            }
        }

        if (in_array('db.fetch', $llmToolNames, true)) {
            throw new \RuntimeException('Invariant db.fetch exposed');
        }
    }
}
