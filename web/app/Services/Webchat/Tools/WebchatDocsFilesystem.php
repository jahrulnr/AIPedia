<?php

namespace App\Services\Webchat\Tools;

use App\Services\Webchat\WebchatConfig;

class WebchatDocsFilesystem
{
    private string $root;

    public function __construct(WebchatConfig $cfg)
    {
        $root = realpath($cfg->docsRoot);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('docs root unavailable');
        }
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function listDir(array $args): array
    {
        $relative = $this->relativePath($args['path'] ?? '');
        $directory = $this->resolve($relative, true);
        if (!is_dir($directory)) {
            return $this->fail('not_directory', 'path is not a directory');
        }

        $limit = min(100, max(1, (int) ($args['max_entries'] ?? 50)));
        $entries = [];
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            $entryPath = $entry->getPathname();
            $entries[] = [
                'name' => $entry->getFilename(),
                'path' => $this->relativeToRoot($entryPath),
                'type' => $entry->isDir() ? 'directory' : 'file',
            ];
            if (count($entries) >= $limit) {
                break;
            }
        }

        usort($entries, static fn (array $a, array $b): int => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

        return $this->ok(['path' => $relative, 'entries' => $entries], count($entries) >= $limit);
    }

    public function readFile(array $args): array
    {
        $relative = $this->relativePath($args['path'] ?? '');
        $file = $this->resolve($relative, false);
        if (!is_file($file)) {
            return $this->fail('not_file', 'path is not a file');
        }
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'md') {
            return $this->fail('file_type_not_allowed', 'only Markdown files are readable');
        }

        $maxChars = min(20000, max(1, (int) ($args['max_chars'] ?? 12000)));
        $content = file_get_contents($file);
        if ($content === false) {
            return $this->fail('read_failed', 'file could not be read');
        }

        return $this->ok([
            'path' => $relative,
            'content' => mb_substr($content, 0, $maxChars),
            'truncated' => mb_strlen($content) > $maxChars,
        ], mb_strlen($content) > $maxChars);
    }

    public function grep(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return $this->fail('validation', 'query required');
        }
        if (mb_strlen($query) > 200) {
            return $this->fail('validation', 'query too long');
        }

        $relative = $this->relativePath($args['path'] ?? '');
        $target = $this->resolve($relative, true);
        $maxResults = min(100, max(1, (int) ($args['max_results'] ?? 30)));
        $caseSensitive = (bool) ($args['case_sensitive'] ?? false);
        $needle = $caseSensitive ? $query : mb_strtolower($query);
        $files = is_file($target) ? [$target] : $this->markdownFiles($target);
        $matches = [];

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $number => $line) {
                $haystack = $caseSensitive ? $line : mb_strtolower($line);
                if (!str_contains($haystack, $needle)) {
                    continue;
                }
                $matches[] = [
                    'path' => $this->relativeToRoot($file),
                    'line' => $number + 1,
                    'text' => mb_substr($line, 0, 500),
                ];
                if (count($matches) >= $maxResults) {
                    break 2;
                }
            }
        }

        return $this->ok([
            'query' => $query,
            'matches' => $matches,
            'count' => count($matches),
        ], count($matches) >= $maxResults);
    }

    private function relativePath(mixed $value): string
    {
        $path = str_replace('\\', '/', trim((string) $value));
        if ($path === '' || $path === '.') {
            return '';
        }
        if (str_starts_with($path, '/') || str_contains($path, "\0")
            || preg_match('~(^|/)\.\.?(/|$)~', $path) === 1) {
            throw new \InvalidArgumentException('path traversal is not allowed');
        }
        return trim($path, '/');
    }

    private function resolve(string $relative, bool $directoryAllowed): string
    {
        $candidate = $this->root . ($relative === '' ? '' : DIRECTORY_SEPARATOR . $relative);
        $real = realpath($candidate);
        if ($real === false || !$this->withinRoot($real)) {
            throw new \InvalidArgumentException('path is outside docs root');
        }
        if (!$directoryAllowed && !is_file($real)) {
            throw new \InvalidArgumentException('file path required');
        }
        return $real;
    }

    /** @return list<string> */
    private function markdownFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private function withinRoot(string $path): bool
    {
        return $path === $this->root || str_starts_with($path, $this->root . DIRECTORY_SEPARATOR);
    }

    private function relativeToRoot(string $path): string
    {
        return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($this->root))), '/');
    }

    private function ok(array $data, bool $truncated = false): array
    {
        return [
            'ok' => true,
            'tool' => 'docs_filesystem',
            'data' => $data,
            'error' => null,
            'meta' => ['truncated' => $truncated, 'count' => $data['count'] ?? count($data['entries'] ?? $data['matches'] ?? [])],
        ];
    }

    private function fail(string $code, string $message): array
    {
        return [
            'ok' => false,
            'tool' => 'docs_filesystem',
            'data' => null,
            'error' => ['code' => $code, 'message' => $message],
            'meta' => ['truncated' => false, 'count' => 0],
        ];
    }
}
