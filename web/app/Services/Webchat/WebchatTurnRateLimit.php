<?php

namespace App\Services\Webchat;

class WebchatRateLimitedException extends \RuntimeException
{
    public int $retryAfterSec;

    public function __construct(int $retryAfterSec = 60)
    {
        parent::__construct('rate_limited');
        $this->retryAfterSec = max(1, $retryAfterSec);
    }
}

/**
 * Sliding-window StartTurn rate limit per admin (file-backed).
 */
class WebchatTurnRateLimit
{
    private WebchatConfig $cfg;

    public function __construct(WebchatConfig $cfg)
    {
        $this->cfg = $cfg;
    }

    public function assert(int $adminUserId): void
    {
        $limit = max(1, $this->cfg->turnRateLimitPerMin);
        $window = 60.0;
        $now = microtime(true);
        $path = $this->cfg->storageRoot . '/rl/turns_' . $adminUserId . '.json';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($path, 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new \RuntimeException('rate_limit_io');
        }

        $raw = stream_get_contents($fh);
        $events = [];
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $events = $decoded;
            }
        }

        $events = array_values(array_filter($events, static function ($t) use ($now, $window) {
            return is_numeric($t) && ($now - (float) $t) < $window;
        }));

        if (count($events) >= $limit) {
            $oldest = (float) min($events);
            $retry = (int) max(1, ceil($window - ($now - $oldest)));
            flock($fh, LOCK_UN);
            fclose($fh);
            throw new WebchatRateLimitedException($retry);
        }

        $events[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($events));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
