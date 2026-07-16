<?php

namespace App\Services\Webchat\Agent;

class WebchatInvariants
{
    private WebchatAllowlist $allowlist;

    public function __construct(WebchatAllowlist $allowlist)
    {
        $this->allowlist = $allowlist;
    }

    public function assert(array $llmToolNames = []): void
    {
        $allowed = $this->allowlist->tools();

        foreach ($llmToolNames as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new \RuntimeException('Invariant bad_tool: ' . $name);
            }
        }

        if (in_array('db.fetch', $llmToolNames, true)) {
            throw new \RuntimeException('Invariant db.fetch exposed');
        }
    }
}
