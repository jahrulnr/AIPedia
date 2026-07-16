<?php

namespace App\Services\Webchat\Agent;

use App\Services\Webchat\WebchatConfig;

class WebchatPromptBuilder
{
    private WebchatConfig $cfg;

    public function __construct(WebchatConfig $cfg)
    {
        $this->cfg = $cfg;
    }

    public function build(array $messages, array $admin): array
    {
        $systemPath = $this->cfg->promptsRoot . '/system.md';
        $developerPath = $this->cfg->promptsRoot . '/developer.md';

        if (!file_exists($systemPath) || !file_exists($developerPath)) {
            throw new \RuntimeException('Missing prompt files');
        }

        $system = file_get_contents($systemPath);
        $developer = file_get_contents($developerPath);

        $vars = $this->variables($admin);
        foreach ($vars as $key => $value) {
            $system = str_replace('{{' . $key . '}}', (string) $value, $system);
            $developer = str_replace('{{' . $key . '}}', (string) $value, $developer);
        }

        $out = [
            ['role' => 'system', 'content' => $system],
            // Keep application instructions as system for provider compatibility.
            // The separate message preserves the stable static prefix above.
            ['role' => 'system', 'content' => $developer],
        ];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') !== 'system' && ($msg['role'] ?? '') !== 'developer') {
                $out[] = $msg;
            }
        }

        return $out;
    }

    public function formatUserMessage(string $text, array $admin = []): array
    {
        $templatePath = $this->cfg->promptsRoot . '/user-message-template.md';
        if (!file_exists($templatePath)) {
            return ['role' => 'user', 'content' => $text];
        }

        $template = file_get_contents($templatePath);
        $vars = array_merge($this->variables($admin), ['user_message' => $text]);
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        return ['role' => 'user', 'content' => $template];
    }

    private function variables(array $admin): array
    {
        return [
            'admin_display_name' => $admin['admin_display_name'] ?? 'Admin',
            'admin_user_id' => $admin['admin_user_id'] ?? '0',
            'admin_role_name' => $admin['admin_role_name'] ?? 'admin',
            'admin_role_id' => $admin['admin_role_id'] ?? '0',
            'locale' => $admin['locale'] ?? 'id',
            'cms_environment' => $admin['cms_environment'] ?? 'local',
            'available_tools' => $admin['available_tools'] ?? 'search_docs',
            'indexed_document_count' => $admin['indexed_document_count'] ?? '0',
            'pii_redaction' => $admin['pii_redaction'] ?? 'false',
            'current_admin_path' => $admin['current_admin_path'] ?? '',
            'last_entity_ref' => $admin['last_entity_ref'] ?? '',
            'conversation_goal' => $admin['conversation_goal'] ?? '',
        ];
    }
}
