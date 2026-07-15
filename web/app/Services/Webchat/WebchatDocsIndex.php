<?php

namespace App\Services\Webchat;

use Illuminate\Support\Facades\Log;

/**
 * Boot-time docs index for search_docs. Built once via queue on container start.
 */
class WebchatDocsIndex
{
    private WebchatConfig $cfg;

    public function __construct(WebchatConfig $cfg)
    {
        $this->cfg = $cfg;
    }

    public function indexPath(): string
    {
        return $this->cfg->storageRoot . '/docs_index.json';
    }

    public function statusPath(): string
    {
        return $this->cfg->storageRoot . '/docs_index.status.json';
    }

    public function ready(): bool
    {
        return is_file($this->indexPath());
    }

    /** AI may run only when status=ready and index file exists. */
    public function isUsable(): bool
    {
        return $this->status() === 'ready' && $this->ready();
    }

    /**
     * @return 'ready'|'building'|'failed'|'missing'
     */
    public function status(): string
    {
        $meta = $this->statusMeta();
        $st = (string) ($meta['status'] ?? '');
        if ($st === 'building' || $st === 'failed') {
            return $st;
        }
        if ($st === 'ready' && $this->ready()) {
            return 'ready';
        }
        if ($this->ready()) {
            return 'ready';
        }
        return 'missing';
    }

    /**
     * @return array{status:string,at?:float,message?:string,document_count?:int}
     */
    public function statusMeta(): array
    {
        $path = $this->statusPath();
        if (!is_file($path)) {
            return ['status' => $this->ready() ? 'ready' : 'missing'];
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['status'])) {
            return ['status' => $this->ready() ? 'ready' : 'missing'];
        }
        return $data;
    }

    /**
     * @return array{usable:bool,status:string,message:string,document_count:?int}
     */
    public function gate(): array
    {
        $meta = $this->statusMeta();
        $status = $this->status();
        $usable = $status === 'ready' && $this->ready();
        $message = match ($status) {
            'building' => 'Docs index sedang dibangun. AI sementara tidak tersedia.',
            'failed' => (string) ($meta['message'] ?? 'Docs index gagal dibangun. Restart container untuk coba lagi.'),
            'missing' => 'Docs index belum siap.',
            default => 'Docs index siap.',
        };

        return [
            'usable' => $usable,
            'status' => $status,
            'message' => $message,
            'document_count' => isset($meta['document_count']) ? (int) $meta['document_count'] : null,
        ];
    }

    /** Mark AI locked before queueing / while building. Removes previous index file. */
    public function beginReindex(): void
    {
        $this->ensureStorageDir();
        @unlink($this->indexPath());
        $this->writeStatus([
            'status' => 'building',
            'at' => microtime(true),
        ]);
        Log::info('webchat.docs_index_building');
    }

    public function markFailed(string $message): void
    {
        $this->writeStatus([
            'status' => 'failed',
            'at' => microtime(true),
            'message' => mb_substr($message, 0, 500),
        ]);
        Log::error('webchat.docs_index_failed', ['message' => $message]);
    }

    /**
     * @return array{built_at:float,docs_root:string,document_count:int,documents:list<array<string,mixed>>}|null
     */
    public function load(): ?array
    {
        if (!$this->isUsable()) {
            return null;
        }
        $path = $this->indexPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['documents']) || !is_array($data['documents'])) {
            return null;
        }
        return $data;
    }

    /**
     * Walk docs_root and atomically write docs_index.json.
     *
     * @return array{document_count:int,path:string}
     */
    public function build(): array
    {
        $docsRoot = $this->cfg->docsRoot;
        $documents = $this->collectDocuments($docsRoot);
        $payload = [
            'built_at' => microtime(true),
            'docs_root' => $docsRoot,
            'app_id' => $this->cfg->docsAppId,
            'document_count' => count($documents),
            'documents' => $documents,
        ];

        $path = $this->indexPath();
        $this->ensureStorageDir();

        $tmp = $path . '.' . getmypid() . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('docs_index encode failed');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('docs_index write failed');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('docs_index rename failed');
        }

        $this->writeStatus([
            'status' => 'ready',
            'at' => microtime(true),
            'document_count' => count($documents),
        ]);

        Log::info('webchat.docs_index_built', [
            'path' => $path,
            'document_count' => count($documents),
        ]);

        return [
            'document_count' => count($documents),
            'path' => $path,
        ];
    }

    /**
     * Search indexed documents (or empty if index not usable).
     *
     * @return list<array{path:string,title:string,excerpt:string,score:float,language?:string,domain?:string,heading?:string,chunk_id?:string}>
     */
    public function search(string $query, int $topK, array $filters = []): array
    {
        $index = $this->load();
        if ($index === null) {
            return [];
        }

        return $this->rankDocuments($index['documents'], $query, $topK, $filters);
    }

    private function ensureStorageDir(): void
    {
        $dir = $this->cfg->storageRoot;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /** @param array<string,mixed> $payload */
    private function writeStatus(array $payload): void
    {
        $this->ensureStorageDir();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->statusPath(), $json === false ? '{"status":"failed"}' : $json, LOCK_EX);
    }

    /**
     * @return list<array{path:string,title:string,headings:list<string>,text:string,mtime:int}>
     */
    public function collectDocuments(string $docsRoot): array
    {
        if (!is_dir($docsRoot)) {
            return [];
        }

        $rootReal = realpath($docsRoot);
        if ($rootReal === false) {
            return [];
        }

        $documents = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $path = $file->getPathname();
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $rootReal)) {
                continue;
            }

            $text = file_get_contents($real);
            if ($text === false) {
                continue;
            }

            $rel = ltrim(str_replace($rootReal, '', $real), DIRECTORY_SEPARATOR);
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

            $documents[] = [
                'path' => $rel,
                'title' => $this->firstHeading($text, $rel),
                'headings' => $this->extractHeadings($text),
                'text' => $text,
                'language' => $this->languageFromPath($rel),
                'domain' => $this->domainFromPath($rel),
                'app_id' => $this->cfg->docsAppId,
                'chunks' => $this->chunkMarkdown($text),
                'mtime' => (int) $file->getMTime(),
            ];
        }

        usort($documents, static fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $documents;
    }

    /**
     * @param  list<array<string,mixed>>  $documents
     * @return list<array{path:string,title:string,excerpt:string,score:float}>
     */
    public function rankDocuments(array $documents, string $query, int $topK, array $filters = []): array
    {
        $hits = [];
        foreach ($documents as $doc) {
            $path = (string) ($doc['path'] ?? '');
            if (($filters['language'] ?? '') !== ''
                && (string) ($doc['language'] ?? '') !== (string) $filters['language']) {
                continue;
            }
            if (($filters['domain'] ?? '') !== ''
                && (string) ($doc['domain'] ?? '') !== (string) $filters['domain']) {
                continue;
            }
            $chunks = is_array($doc['chunks'] ?? null) && $doc['chunks'] !== []
                ? $doc['chunks']
                : [['id' => 'doc', 'heading' => $doc['title'] ?? '', 'text' => (string) ($doc['text'] ?? '')]];
            foreach ($chunks as $chunk) {
                $text = (string) ($chunk['text'] ?? '');
                $heading = (string) ($chunk['heading'] ?? '');
                $score = $this->keywordScore($query, $path, $text, [$heading]);
                if ($score < (float) config('webchat.docs_min_score', 1.5)) {
                    continue;
                }
                $hits[] = [
                    'path' => $path,
                    'title' => (string) ($doc['title'] ?? basename($path, '.md')),
                    'heading' => $heading,
                    'chunk_id' => (string) ($chunk['id'] ?? 'doc'),
                    'language' => (string) ($doc['language'] ?? 'unknown'),
                    'domain' => (string) ($doc['domain'] ?? ''),
                    'app_id' => (string) ($doc['app_id'] ?? $this->cfg->docsAppId),
                    'excerpt' => $this->snippet($text, $query, 500),
                    'score' => $score,
                ];
            }
        }

        usort($hits, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['path'], $b['path']));
        return array_slice($hits, 0, max(1, $topK));
    }

    /**
     * @param  list<string>|null  $headings
     */
    public function keywordScore(string $query, string $path, string $text, ?array $headings = null): float
    {
        $q = mb_strtolower(trim($query));
        $words = $this->meaningfulQueryWords($q);
        if ($words === []) {
            return 0.0;
        }
        $textLower = mb_strtolower($text);
        $pathLower = mb_strtolower($path);

        $score = 0.0;
        $matched = 0;
        foreach ($words as $word) {
            $inText = substr_count($textLower, $word);
            $inPath = str_contains($pathLower, $word);
            if ($inText === 0 && !$inPath && (bool) config('webchat.docs_fuzzy_enabled', true)) {
                $fuzzy = $this->closestToken($word, $textLower . ' ' . $pathLower);
                if ($fuzzy !== null) {
                    $matched++;
                    $score += 0.75;
                }
            }
            if ($inText > 0 || $inPath) {
                $matched++;
            }
            $score += $inText * 1.0;
            if ($inPath) {
                $score += 5.0;
            }
        }

        // A multi-word question must match at least two meaningful terms.
        // This prevents a generic word such as "admin" from returning an
        // unrelated document that happens to mention it once.
        if ($matched < min(2, count($words))) {
            return 0.0;
        }

        if (str_contains($textLower, $q)) {
            $score += 12.0;
        }

        $headingList = $headings ?? $this->extractHeadings($text);
        foreach ($headingList as $heading) {
            $headingLower = mb_strtolower((string) $heading);
            foreach ($words as $word) {
                if (str_contains($headingLower, $word)) {
                    $score += 3.0;
                }
            }
        }

        return $score;
    }

    /** @return list<string> */
    private function meaningfulQueryWords(string $query): array
    {
        $stopWords = [
            'a', 'an', 'and', 'are', 'buat', 'cara', 'di', 'do', 'for', 'how',
            'in', 'ke', 'of', 'on', 'the', 'to', 'untuk', 'use', 'yang',
        ];
        $words = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(static fn (string $word): string => mb_strtolower($word), $words);
        $words = array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 3 && !in_array($word, $stopWords, true));
        return array_values(array_unique($words));
    }

    private function closestToken(string $needle, string $haystack): ?string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $haystack, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $maxDistance = mb_strlen($needle) >= 7 ? 2 : 1;
        $best = null;
        $bestDistance = $maxDistance + 1;
        foreach (array_unique($tokens) as $token) {
            $token = mb_strtolower($token);
            if (abs(mb_strlen($token) - mb_strlen($needle)) > $maxDistance) {
                continue;
            }
            $distance = levenshtein($needle, $token);
            if ($distance <= $maxDistance && $distance < $bestDistance) {
                $best = $token;
                $bestDistance = $distance;
            }
        }
        return $best;
    }

    /** @return list<array{id:string,heading:string,text:string}> */
    private function chunkMarkdown(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $chunks = [];
        $heading = '';
        $body = [];
        $flush = function () use (&$chunks, &$heading, &$body): void {
            $content = trim(implode("\n", $body));
            if ($content !== '') {
                $chunks[] = [
                    'id' => 'chunk_' . count($chunks),
                    'heading' => $heading,
                    'text' => $content,
                ];
            }
            $body = [];
        };
        foreach ($lines as $line) {
            if (preg_match('/^#{1,3}\s+(.+)$/u', $line, $matches) === 1) {
                $flush();
                $heading = trim($matches[1]);
                continue;
            }
            $body[] = $line;
        }
        $flush();
        return $chunks === [] ? [['id' => 'chunk_0', 'heading' => '', 'text' => trim($text)]] : $chunks;
    }

    private function languageFromPath(string $path): string
    {
        if (preg_match('/(?:^|\/)[^\/]+_id\.md$/i', $path) === 1 || str_ends_with(strtolower($path), '_id.md')) {
            return 'id';
        }
        if (preg_match('/(?:^|\/)[^\/]+_en\.md$/i', $path) === 1 || str_ends_with(strtolower($path), '_en.md')) {
            return 'en';
        }
        return 'unknown';
    }

    private function domainFromPath(string $path): string
    {
        $parts = explode('/', trim($path, '/'));
        return count($parts) > 1 ? (string) $parts[0] : 'general';
    }

    /** @return list<string> */
    public function extractHeadings(string $text): array
    {
        preg_match_all('/^#+\s+(.+)$/m', $text, $matches);
        return $matches[1] ?? [];
    }

    public function firstHeading(string $text, string $path): string
    {
        preg_match('/^#\s+(.+)$/m', $text, $matches);
        if (!empty($matches[1])) {
            return trim($matches[1]);
        }
        return basename($path, '.md');
    }

    public function snippet(string $text, string $query, int $maxLen): string
    {
        $lower = mb_strtolower($text);
        $pos = mb_stripos($lower, mb_strtolower($query));
        if ($pos === false) {
            $words = preg_split('/\s+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                $pos = mb_stripos($lower, $word);
                if ($pos !== false) {
                    break;
                }
            }
        }
        if ($pos === false) {
            return mb_substr($text, 0, $maxLen);
        }
        $start = max(0, $pos - 80);
        return trim(mb_substr($text, $start, $maxLen));
    }
}
