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
     * @return list<array{path:string,title:string,excerpt:string,score:float}>
     */
    public function search(string $query, int $topK): array
    {
        $index = $this->load();
        if ($index === null) {
            return [];
        }

        return $this->rankDocuments($index['documents'], $query, $topK);
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
    public function rankDocuments(array $documents, string $query, int $topK): array
    {
        $hits = [];
        foreach ($documents as $doc) {
            $path = (string) ($doc['path'] ?? '');
            $text = (string) ($doc['text'] ?? '');
            $score = $this->keywordScore($query, $path, $text, $doc['headings'] ?? null);
            if ($score <= 0) {
                continue;
            }
            $hits[] = [
                'path' => $path,
                'title' => (string) ($doc['title'] ?? basename($path, '.md')),
                'excerpt' => $this->snippet($text, $query, 500),
                'score' => $score,
            ];
        }

        usort($hits, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($hits, 0, max(1, $topK));
    }

    /**
     * @param  list<string>|null  $headings
     */
    public function keywordScore(string $query, string $path, string $text, ?array $headings = null): float
    {
        $q = mb_strtolower($query);
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $textLower = mb_strtolower($text);
        $pathLower = mb_strtolower($path);

        $score = 0.0;
        foreach ($words as $word) {
            $score += substr_count($textLower, $word) * 1.0;
            if (str_contains($pathLower, $word)) {
                $score += 5.0;
            }
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
