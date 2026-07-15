<?php

namespace App\Services\Webchat\Jsonl;

class WebchatBusyException extends \RuntimeException
{
    public function __construct(string $message = 'Thread is busy')
    {
        parent::__construct($message);
    }
}
