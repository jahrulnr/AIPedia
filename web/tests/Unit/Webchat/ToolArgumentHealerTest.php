<?php

namespace Tests\Unit\Webchat;

use App\Services\Webchat\Tools\WebchatToolArgumentHealer;
use Tests\TestCase;

class ToolArgumentHealerTest extends TestCase
{
    public function test_it_converts_common_string_scalar_drift_to_schema_types(): void
    {
        $healer = new WebchatToolArgumentHealer();

        $args = $healer->heal([
            'max_entries' => '1',
            'case_sensitive' => 'false',
            'unused' => 'kept',
        ], [
            'properties' => [
                'max_entries' => ['type' => 'integer'],
                'case_sensitive' => ['type' => 'boolean'],
            ],
        ]);

        $this->assertSame(1, $args['max_entries']);
        $this->assertFalse($args['case_sensitive']);
        $this->assertSame('kept', $args['unused']);
    }
}
