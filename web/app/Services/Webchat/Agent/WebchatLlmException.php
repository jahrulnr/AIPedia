<?php

namespace App\Services\Webchat\Agent;

class WebchatLlmException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $providerId,
        public readonly int $status = 0,
        public readonly bool $transient = false,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
