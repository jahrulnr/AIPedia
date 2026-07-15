<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\Agent\WebchatContextCompactor;
use App\Services\Webchat\WebchatConfig;
use Tests\TestCase;

class ContextCompactorTest extends TestCase
{
    public function test_it_returns_checkpoint_and_keeps_recent_turns_out_of_summary(): void
    {
        config([
            'webchat.context_compaction_enabled' => true,
            'webchat.context_max_input_tokens' => 1000,
            'webchat.context_reserve_tokens' => 0,
            'webchat.context_recent_turns' => 1,
            'webchat.context_summary_max_chars' => 12000,
            'webchat.llm_providers.TEST.max_input_tokens' => 1000,
        ]);

        $result = (new WebchatContextCompactor(WebchatConfig::load()))->compact([
            ['seq' => 1, 'turn_id' => 'turn_old', 'type' => 'user_message', 'text' => str_repeat('old ', 1200)],
            ['seq' => 2, 'turn_id' => 'turn_old', 'type' => 'agent_message', 'text' => 'old answer'],
            ['seq' => 3, 'turn_id' => 'turn_new', 'type' => 'user_message', 'text' => 'keep this'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(2, $result['compacted_through_seq']);
        $this->assertStringContainsString('old answer', $result['summary']);
        $this->assertStringNotContainsString('keep this', $result['summary']);
    }
}
