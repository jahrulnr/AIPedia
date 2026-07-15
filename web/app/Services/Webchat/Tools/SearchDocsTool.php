<?php

namespace App\Services\Webchat\Tools;

use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatDocsIndex;

class SearchDocsTool
{
    private WebchatConfig $cfg;
    private WebchatDocsIndex $index;

    public function __construct(WebchatConfig $cfg, ?WebchatDocsIndex $index = null)
    {
        $this->cfg = $cfg;
        $this->index = $index ?? new WebchatDocsIndex($cfg);
    }

    public function execute(array $args): array
    {
        $query = trim($args['query'] ?? '');
        if ($query === '') {
            return $this->fail('validation', 'query required');
        }

        $topK = (int) ($args['top_k'] ?? $this->cfg->docsTopK);
        if ($topK < 1) {
            $topK = 5;
        }

        if (!$this->index->isUsable()) {
            $gate = $this->index->gate();
            return [
                'ok' => false,
                'tool' => 'search_docs',
                'data' => null,
                'error' => [
                    'code' => 'docs_index_not_ready',
                    'message' => $gate['message'],
                ],
                'meta' => [
                    'truncated' => false,
                    'count' => 0,
                    'index_ready' => false,
                    'index_status' => $gate['status'],
                ],
            ];
        }

        $hits = $this->index->search($query, $topK);

        return [
            'ok' => true,
            'tool' => 'search_docs',
            'data' => ['chunks' => $hits],
            'error' => null,
            'meta' => [
                'truncated' => count($hits) >= $topK,
                'count' => count($hits),
                'index_ready' => true,
                'index_status' => 'ready',
            ],
        ];
    }

    private function fail(string $code, string $message): array
    {
        $gate = $this->index->gate();
        return [
            'ok' => false,
            'tool' => 'search_docs',
            'data' => null,
            'error' => ['code' => $code, 'message' => $message],
            'meta' => [
                'truncated' => false,
                'index_ready' => $gate['usable'],
                'index_status' => $gate['status'],
            ],
        ];
    }
}
