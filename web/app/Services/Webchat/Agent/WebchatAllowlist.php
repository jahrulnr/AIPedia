<?php

namespace App\Services\Webchat\Agent;

use App\Services\Webchat\WebchatConfig;

class WebchatAllowlist
{
    private WebchatConfig $cfg;

    public function __construct(WebchatConfig $cfg)
    {
        $this->cfg = $cfg;
    }

    public function forPhase(int $phase, bool $productApprovedWrites = false): array
    {
        if ($phase < 2) {
            return [];
        }
        if ($phase === 2) {
            return ['search_docs', 'list_dir', 'read_file', 'grep'];
        }
        if ($phase === 3) {
            return ['search_docs', 'list_dir', 'read_file', 'grep', 'list_modules', 'search_admin_routes'];
        }
        if ($phase === 4) {
            return ['search_docs', 'list_dir', 'read_file', 'grep', 'list_modules', 'search_admin_routes', 'get_voucher', 'list_vouchers'];
        }
        if ($phase >= 5 && $productApprovedWrites) {
            return ['search_docs', 'list_dir', 'read_file', 'grep', 'list_modules', 'search_admin_routes', 'get_voucher', 'list_vouchers', 'draft_mutation', 'confirm_mutation'];
        }
        return ['search_docs', 'list_dir', 'read_file', 'grep', 'list_modules', 'search_admin_routes', 'get_voucher', 'list_vouchers'];
    }
}
