<?php

namespace App\Services\Webchat\Agent;

use App\Services\Webchat\WebchatConfig;
use Illuminate\Support\Facades\Log;

class WebchatLlmRouter
{
    private WebchatLlmProviderState $state;

    public function __construct(private WebchatConfig $cfg, private WebchatLlmClient $client)
    {
        $this->state = new WebchatLlmProviderState($cfg);
    }

    /** @return array<string, mixed> */
    public function chat(array $messages, array $tools = [], ?string $pinnedProvider = null): array
    {
        $candidates = $this->candidates($pinnedProvider);
        $attempts = 0;
        $last = null;
        foreach ($candidates as $providerId) {
            $provider = $this->cfg->llmProviders[$providerId];
            $attemptsForProvider = 0;
            while ($attemptsForProvider < $provider['max_attempts'] && $attempts < $this->cfg->llmTotalAttemptBudget) {
                $attempts++;
                $attemptsForProvider++;
                try {
                    $response = $this->client->chatWithProvider($providerId, $messages, $tools);
                    $this->state->record($providerId, true);
                    $response['attempt_count'] = $attempts;
                    return $response;
                } catch (WebchatLlmException $e) {
                    $last = $e;
                    $this->state->record($providerId, false);
                    if (!$e->transient) {
                        throw $e;
                    }
                    Log::warning('webchat.llm_provider_failover', [
                        'provider' => $providerId,
                        'status' => $e->status,
                        'attempt' => $attempts,
                    ]);
                    if ($attemptsForProvider >= $provider['max_attempts']) {
                        break;
                    }
                }
            }
            if ($attempts >= $this->cfg->llmTotalAttemptBudget) {
                break;
            }
        }

        if ($last instanceof WebchatLlmException) {
            throw $last;
        }
        throw new WebchatLlmException('LLM providers exhausted', 'none', 0, true);
    }

    /** @return list<string> */
    private function candidates(?string $pinnedProvider): array
    {
        $enabled = array_values(array_filter(
            array_keys($this->cfg->llmProviders),
            fn (string $id): bool => $this->cfg->llmProviders[$id]['enabled']
                && $this->state->isAvailable($id, $this->state->read(), microtime(true))
        ));
        if ($enabled === []) {
            $enabled = array_values(array_filter(
                array_keys($this->cfg->llmProviders),
                fn (string $id): bool => $this->cfg->llmProviders[$id]['enabled']
            ));
        }
        if ($this->cfg->llmStrategy === 'switch') {
            return [$this->cfg->llmActiveProvider];
        }
        if ($pinnedProvider !== null && in_array($pinnedProvider, $enabled, true)) {
            return array_values(array_unique(array_merge([$pinnedProvider], $enabled)));
        }
        if ($this->cfg->llmStrategy === 'round_robin' && $enabled !== []) {
            $cursorPath = $this->cfg->storageRoot . '/llm/round_robin.cursor';
            if (!is_dir(dirname($cursorPath))) {
                mkdir(dirname($cursorPath), 0775, true);
            }
            $cursor = is_file($cursorPath) ? (int) file_get_contents($cursorPath) : 0;
            $start = $cursor % count($enabled);
            file_put_contents($cursorPath, (string) (($start + 1) % count($enabled)), LOCK_EX);
            $enabled = array_merge(array_slice($enabled, $start), array_slice($enabled, 0, $start));
        }
        if ($this->cfg->llmStrategy === 'failover') {
            $active = $this->cfg->llmActiveProvider;
            return array_values(array_unique(array_merge([$active], array_diff($enabled, [$active]))));
        }
        return $enabled;
    }
}
