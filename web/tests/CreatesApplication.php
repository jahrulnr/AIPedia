<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        config([
            'webchat.llm_active_provider' => 'TEST',
            'webchat.llm_providers' => [
                'TEST' => [
                    'id' => 'TEST',
                    'base_url' => 'https://example.test/v1',
                    'api_key' => 'test-key',
                    'model' => 'test-model',
                    'api' => 'chat',
                    'timeout_sec' => 60,
                    'max_attempts' => 1,
                    'weight' => 1,
                    'enabled' => true,
                ],
            ],
        ]);

        return $app;
    }
}
