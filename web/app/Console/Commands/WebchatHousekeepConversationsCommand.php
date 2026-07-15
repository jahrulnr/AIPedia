<?php

namespace App\Console\Commands;

use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatConversationCleanup;
use Illuminate\Console\Command;

class WebchatHousekeepConversationsCommand extends Command
{
    protected $signature = 'webchat:housekeep-conversations';

    protected $description = 'Soft-delete webchat conversations idle longer than configured TTL';

    public function handle(): int
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $result = (new WebchatConversationCleanup($cfg, $store))->housekeep();

        $this->info(sprintf(
            'housekeep done: deleted=%d skipped_busy=%d',
            $result['deleted'],
            $result['skipped_busy']
        ));

        return 0;
    }
}
