<?php

namespace App\Services\Webchat\Jsonl;

class JsonlLine
{
    public int $seq = 0;
    public float $ts;
    public string $thread_id;
    public ?string $turn_id = null;
    public string $type;
    public string $id;
    public ?string $text = null;
    public ?string $call_id = null;
    public ?string $name = null;
    public ?array $arguments = null;
    public ?array $envelope = null;
    public ?array $usage = null;
    public ?array $error = null;

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function toArray(): array
    {
        return array_filter(
            (array) $this,
            static fn ($v) => $v !== null && $v !== []
        );
    }
}
