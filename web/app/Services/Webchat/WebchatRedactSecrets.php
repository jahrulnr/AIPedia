<?php

namespace App\Services\Webchat;

/**
 * Portable secret scrubber (pre-JSONL / pre-LLM). Patterns from contracts pack research.
 */
class WebchatRedactSecrets
{
    /**
     * @return array{redacted_text: string, findings: list<array{kind: string, start: int, end: int}>}
     */
    public static function redact(string $text): array
    {
        $out = $text;
        $findings = [];

        $rules = [
            ['kind' => 'PRIVATE_KEY', 'pattern' => '/-----BEGIN[ A-Z0-9_-]{0,100}PRIVATE KEY(?: BLOCK)?-----[\s\S]*?-----END[ A-Z0-9_-]{0,100}PRIVATE KEY(?: BLOCK)?-----/'],
            ['kind' => 'JWT', 'pattern' => '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/'],
            ['kind' => 'OPENAI_KEY', 'pattern' => '/\bsk-(?:proj|svcacct|admin)-[A-Za-z0-9_-]{20,}\b/'],
            ['kind' => 'OPENAI_KEY', 'pattern' => '/\bsk-[A-Za-z0-9]{20,}\b/'],
            ['kind' => 'GROQ_KEY', 'pattern' => '/\bgsk_[A-Za-z0-9]{20,}\b/'],
            ['kind' => 'GITHUB_PAT', 'pattern' => '/\b(?:ghp_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{20,})\b/'],
            ['kind' => 'AWS_ACCESS_KEY', 'pattern' => '/\bAKIA[0-9A-Z]{16}\b/'],
            ['kind' => 'BEARER', 'pattern' => '/(?i)(?:authorization\s*[:=]\s*)?bearer\s+[A-Za-z0-9\-._~+\/]+=*/'],
            ['kind' => 'BASIC_AUTH', 'pattern' => '/(?i)(?:authorization\s*[:=]\s*)?basic\s+[A-Za-z0-9+\/]{8,}={0,3}/'],
            ['kind' => 'BCRYPT', 'pattern' => '/\$2[aby]?\$\d{2}\$[.\/A-Za-z0-9]{53}/'],
            ['kind' => 'GENERIC_ASSIGNED', 'pattern' => '/(?i)(?:api[_-]?key|secret|password|passwd|token|credential)\s*[:=]\s*[\'"]?[^\s\'\"&,;]{8,}/'],
            ['kind' => 'MD5_HEX', 'pattern' => '/\b[a-fA-F0-9]{32}\b/', 'context' => true],
            ['kind' => 'SHA256_HEX', 'pattern' => '/\b[a-fA-F0-9]{64}\b/', 'context' => true],
        ];

        foreach ($rules as $rule) {
            if (!preg_match_all($rule['pattern'], $out, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            // Replace from end so offsets stay valid
            $matches = array_reverse($matches[0]);
            foreach ($matches as $m) {
                $start = (int) $m[1];
                $len = strlen($m[0]);
                $end = $start + $len;
                if (!empty($rule['context']) && !self::contextHasSecretKeyword($out, $start, 40)) {
                    continue;
                }
                $token = '[REDACTED_' . $rule['kind'] . ']';
                $out = substr($out, 0, $start) . $token . substr($out, $end);
                $findings[] = ['kind' => $rule['kind'], 'start' => $start, 'end' => $end];
            }
        }

        return ['redacted_text' => $out, 'findings' => array_reverse($findings)];
    }

    private static function contextHasSecretKeyword(string $text, int $start, int $lookback): bool
    {
        $from = max(0, $start - $lookback);
        $slice = substr($text, $from, $start - $from);
        return (bool) preg_match('/(?i)(pass|md5|hash|secret|token)/', $slice);
    }
}
