<?php

namespace App\Services\Webchat\Tools;

use App\Services\Webchat\Agent\WebchatAllowlist;
use App\Services\Webchat\WebchatConfig;

class WebchatToolRegistry
{
    private WebchatConfig $cfg;
    private WebchatAllowlist $allowlist;
    private SearchDocsTool $searchDocs;
    private WebchatDocsFilesystem $docsFilesystem;

    public function __construct(WebchatConfig $cfg, WebchatAllowlist $allowlist, SearchDocsTool $searchDocs)
    {
        $this->cfg = $cfg;
        $this->allowlist = $allowlist;
        $this->searchDocs = $searchDocs;
        $this->docsFilesystem = new WebchatDocsFilesystem($cfg);
    }

    public function execute(string $name, array $args, array $admin = []): array
    {
        $started = microtime(true);
        $allowed = $this->allowlist->forPhase($this->cfg->phase, $this->cfg->writeEnabled);

        if (!in_array($name, $allowed, true)) {
            return $this->fail('tool_not_allowed', 'Tool not allowlisted', $name, $started);
        }

        if (in_array($name, ['draft_mutation', 'confirm_mutation'], true) && $this->cfg->writeEnabled === false) {
            return $this->fail('write_disabled', 'Writes are disabled', $name, $started);
        }

        if ($name === 'search_docs') {
            $env = $this->searchDocs->execute($args);
            $env['meta']['took_ms'] = (int) ((microtime(true) - $started) * 1000);
            return $env;
        }

        try {
            $env = match ($name) {
                'list_dir' => $this->docsFilesystem->listDir($args),
                'read_file' => $this->docsFilesystem->readFile($args),
                'grep' => $this->docsFilesystem->grep($args),
                default => $this->fail('unknown_tool', 'Unknown tool', $name, $started),
            };
            $env['tool'] = $name;
            $env['meta']['took_ms'] = (int) ((microtime(true) - $started) * 1000);
            return $env;
        } catch (\InvalidArgumentException $e) {
            return $this->fail('invalid_path', $e->getMessage(), $name, $started);
        } catch (\Throwable $e) {
            return $this->fail('tool_error', 'Internal docs tool error', $name, $started);
        }
    }

    public function schemas(): array
    {
        $allowed = $this->allowlist->forPhase($this->cfg->phase, $this->cfg->writeEnabled);
        $out = [];

        foreach ($allowed as $name) {
            $path = $this->cfg->toolsRoot . '/' . $name . '.tool.json';
            if (!file_exists($path)) {
                continue;
            }
            $json = file_get_contents($path);
            if ($json === false) {
                continue;
            }
            $schema = json_decode($json, true);
            if ($schema === null) {
                continue;
            }
            $out[] = $this->toOpenAi($schema);
        }

        return $out;
    }

    private function toOpenAi(array $schema): array
    {
        // Raw chat-completions shape; WebchatLlmClient normalizes parameters per provider.
        return [
            'type' => 'function',
            'function' => [
                'name' => $schema['name'] ?? '',
                'description' => $schema['description'] ?? '',
                'parameters' => $schema['parameters'] ?? ['type' => 'object', 'properties' => []],
            ],
        ];
    }

    private function fail(string $code, string $message, string $name, float $started): array
    {
        return [
            'ok' => false,
            'tool' => $name,
            'data' => null,
            'error' => ['code' => $code, 'message' => $message],
            'meta' => [
                'truncated' => false,
                'took_ms' => (int) ((microtime(true) - $started) * 1000),
            ],
        ];
    }
}
