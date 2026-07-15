<?php

namespace App\Services\Webchat\Tools;

/**
 * Repairs common low-cost model argument type drift after the provider accepts
 * a tolerant tool schema, while keeping the tool contract strict internally.
 */
class WebchatToolArgumentHealer
{
    /** @param array<string, mixed> $parameters */
    public function heal(array $args, array $parameters): array
    {
        $properties = is_array($parameters['properties'] ?? null) ? $parameters['properties'] : [];
        $healed = [];

        foreach ($args as $key => $value) {
            $schema = is_array($properties[$key] ?? null) ? $properties[$key] : [];
            $healed[$key] = $this->healValue($value, $schema);
        }

        return $healed;
    }

    /** @param array<string, mixed> $schema */
    private function healValue(mixed $value, array $schema): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $types = $schema['type'] ?? null;
        $types = is_array($types) ? $types : [$types];

        if (in_array('integer', $types, true) && preg_match('/^[+-]?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        if (in_array('number', $types, true) && is_numeric(trim($value))) {
            return (float) trim($value);
        }
        if (in_array('boolean', $types, true)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }
        if (in_array('null', $types, true) && strtolower(trim($value)) === 'null') {
            return null;
        }

        return $value;
    }
}
