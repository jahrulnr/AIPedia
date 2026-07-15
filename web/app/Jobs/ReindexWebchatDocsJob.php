<?php

namespace App\Jobs;

use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatDocsIndex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReindexWebchatDocsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 3;

    public function handle(): void
    {
        $cfg = WebchatConfig::load();
        $index = new WebchatDocsIndex($cfg);

        if ($index->status() !== 'building') {
            $index->beginReindex();
        }

        try {
            $result = $index->build();
            Log::info('webchat.docs_reindex_job_done', $result);
        } catch (\Throwable $e) {
            $index->markFailed($e->getMessage());
            throw $e;
        }
    }
}
