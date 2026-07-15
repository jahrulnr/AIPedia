<?php

namespace App\Services\Webchat\Tools;

use App\Services\Webchat\WebchatConfig;

class SearchDocsTool
{
    private WebchatConfig $cfg;

    public function __construct(WebchatConfig $cfg)
    {
        $this->cfg = $cfg;
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

        $docsRoot = $this->cfg->docsRoot;
        if (!is_dir($docsRoot)) {
            return $this->ok([]);
        }

        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($docsRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $path = $file->getPathname();
            if (!str_starts_with(realpath($path), realpath($docsRoot))) {
                continue;
            }

            $text = file_get_contents($path);
            if ($text === false) {
                continue;
            }

            $score = $this->keywordScore($query, $path, $text);
            if ($score > 0) {
                $rel = ltrim(str_replace(realpath($docsRoot), '', realpath($path)), '/');
                $hits[] = [
                    'path' => $rel,
                    'title' => $this->firstHeading($text, $path),
                    'excerpt' => $this->snippet($text, $query, 500),
                    'score' => $score,
                ];
            }
        }

        usort($hits, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $hits = array_slice($hits, 0, $topK);

        return $this->ok($hits, count($hits) >= $topK);
    }

    private function keywordScore(string $query, string $path, string $text): float
    {
        $q = mb_strtolower($query);
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $textLower = mb_strtolower($text);
        $pathLower = mb_strtolower($path);

        $score = 0.0;
        foreach ($words as $word) {
            $count = substr_count($textLower, $word);
            $score += $count * 1.0;
            if (str_contains($pathLower, $word)) {
                $score += 5.0;
            }
        }

        $headings = $this->extractHeadings($text);
        foreach ($headings as $heading) {
            $headingLower = mb_strtolower($heading);
            foreach ($words as $word) {
                if (str_contains($headingLower, $word)) {
                    $score += 3.0;
                }
            }
        }

        return $score;
    }

    private function extractHeadings(string $text): array
    {
        preg_match_all('/^#+\s+(.+)$/m', $text, $matches);
        return $matches[1] ?? [];
    }

    private function firstHeading(string $text, string $path): string
    {
        preg_match('/^#\s+(.+)$/m', $text, $matches);
        if (!empty($matches[1])) {
            return trim($matches[1]);
        }
        return basename($path, '.md');
    }

    private function snippet(string $text, string $query, int $maxLen): string
    {
        $lower = mb_strtolower($text);
        $pos = mb_stripos($lower, mb_strtolower($query));
        if ($pos === false) {
            return mb_substr($text, 0, $maxLen);
        }
        $start = max(0, $pos - 80);
        $out = mb_substr($text, $start, $maxLen);
        return trim($out);
    }

    private function ok(array $hits, bool $truncated = false): array
    {
        return [
            'ok' => true,
            'tool' => 'search_docs',
            'data' => ['chunks' => $hits],
            'error' => null,
            'meta' => [
                'truncated' => $truncated,
                'count' => count($hits),
            ],
        ];
    }

    private function fail(string $code, string $message): array
    {
        return [
            'ok' => false,
            'tool' => 'search_docs',
            'data' => null,
            'error' => ['code' => $code, 'message' => $message],
            'meta' => ['truncated' => false],
        ];
    }
}
