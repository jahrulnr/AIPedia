<?php

namespace App\Services\Webchat\Agent;

class WebchatAllowlist
{
    public function tools(): array
    {
        return ['search_docs', 'list_dir', 'read_file', 'grep'];
    }
}
