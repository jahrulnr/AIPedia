<?php

namespace App\Console\Commands;

use App\Jobs\ReindexWebchatDocsJob;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatDocsIndex;
use Illuminate\Console\Command;

class WebchatReindexDocsCommand extends Command
{
    protected $signature = 'webchat:reindex-docs
                            {--sync : Build index in this process instead of queueing}';

    protected $description = 'Queue a one-shot docs reindex (use --sync to build immediately)';

    public function handle(): int
    {
        $cfg = WebchatConfig::load();
        $index = new WebchatDocsIndex($cfg);

        // Lock AI immediately (before workers pick up the job).
        $index->beginReindex();

        if ($this->option('sync')) {
            try {
                $result = $index->build();
            } catch (\Throwable $e) {
                $index->markFailed($e->getMessage());
                $this->error('docs index failed: ' . $e->getMessage());
                return 1;
            }
            $this->info(sprintf(
                'docs index built: documents=%d path=%s',
                $result['document_count'],
                $result['path']
            ));
            return 0;
        }

        ReindexWebchatDocsJob::dispatch();
        $this->info('docs reindex queued (AI locked until ready)');

        return 0;
    }
}
