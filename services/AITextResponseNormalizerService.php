<?php

namespace CRM\Services;

class AITextResponseNormalizerService
{
    /**
     * Normalize provider text while preserving ordinary JSON that is not a
     * recognized assistant-response envelope.
     */
    public function normalize(string $output): string
    {
        $trimmed = trim($output);
        if ($trimmed === '' || !preg_match('/^[\{\[]/', $trimmed)) {
            return $trimmed;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return $trimmed;
        }

        $recognized = false;
        $text = $this->extractEnvelope($decoded, $recognized);

        return $recognized ? trim($text) : $trimmed;
    }

    private function extractEnvelope(array $value, bool &$recognized): string
    {
        if (array_is_list($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemRecognized = false;
                $text = $this->extractEnvelope($item, $itemRecognized);
                if ($itemRecognized) {
                    $recognized = true;
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }

            return implode("\n", $parts);
        }

        $type = strtolower(trim((string) ($value['type'] ?? '')));
        if (in_array($type, ['text', 'output_text', 'input_text', 'message', 'assistant', 'content'], true)) {
            $recognized = true;
            foreach (['text', 'output_text', 'content', 'message', 'value'] as $key) {
                if (!array_key_exists($key, $value)) {
                    continue;
                }
                $text = $this->extractPayload($value[$key]);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        foreach (['message', 'response', 'answer', 'output_text', 'text', 'content', 'output', 'choices'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $recognized = true;
            $text = $this->extractPayload($value[$key]);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function extractPayload(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (!is_array($value)) {
            return '';
        }
        if (array_is_list($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $text = trim((string) $item);
                } elseif (is_array($item)) {
                    $itemRecognized = false;
                    $text = $this->extractEnvelope($item, $itemRecognized);
                    if (!$itemRecognized && array_key_exists('value', $item)) {
                        $text = $this->extractPayload($item['value']);
                    }
                } else {
                    $text = '';
                }
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return implode("\n", $parts);
        }

        if (array_key_exists('value', $value) && is_scalar($value['value'])) {
            return trim((string) $value['value']);
        }

        $recognized = false;
        return $this->extractEnvelope($value, $recognized);
    }
}
