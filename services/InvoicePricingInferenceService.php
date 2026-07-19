<?php

namespace CRM\Services;

class InvoicePricingInferenceService
{
    private AIService $aiService;

    public function __construct()
    {
        $this->aiService = new AIService();
    }

    public function infer(array $lineItem, ?array $product = null): array
    {
        $currentUnitPrice = (float) ($lineItem['unit_price'] ?? 0);
        if ($currentUnitPrice > 0) {
            return [
                'unit_price' => $currentUnitPrice,
                'inferred' => false,
                'confidence' => 1.0,
                'reasoning' => 'Existing unit price already set.',
            ];
        }

        $pricingContext = trim((string) ($lineItem['pricing_context'] ?? $product['pricing_info'] ?? ''));
        $description = trim((string) ($lineItem['description'] ?? $product['description'] ?? $product['name'] ?? ''));
        $text = trim($description . "\n" . $pricingContext);
        if ($text === '') {
            return [
                'unit_price' => 0.0,
                'inferred' => false,
                'confidence' => 0.0,
                'reasoning' => 'No descriptive pricing text available.',
            ];
        }

        $regexMatch = $this->inferFromPatterns($text);
        if ($regexMatch !== null) {
            return $regexMatch;
        }

        return $this->inferWithAI($text);
    }

    private function inferFromPatterns(string $text): ?array
    {
        $normalized = str_replace([',', "\r"], ['', "\n"], $text);

        if (preg_match('/(?:from|starting at|base(?: price)?|minimum)\s*(?:usd|kes|eur|gbp|\$|€|£)?\s*([0-9]+(?:\.[0-9]{1,2})?)/i', $normalized, $m)) {
            return $this->result((float) $m[1], 0.9, 'Used stated base/starting price.');
        }

        if (preg_match('/(?:usd|kes|eur|gbp|\$|€|£)\s*([0-9]+(?:\.[0-9]{1,2})?)\s*(?:-|to)\s*(?:usd|kes|eur|gbp|\$|€|£)?\s*([0-9]+(?:\.[0-9]{1,2})?)/i', $normalized, $m)) {
            $lower = min((float) $m[1], (float) $m[2]);
            return $this->result($lower, 0.8, 'Used lower bound of stated price range.');
        }

        if (preg_match('/([0-9]+(?:\.[0-9]{1,2})?)\s*(?:-|to)\s*([0-9]+(?:\.[0-9]{1,2})?)\s*(?:per|\/)\s*(?:unit|seat|user|month|mo|item|site|visit|year)/i', $normalized, $m)) {
            $lower = min((float) $m[1], (float) $m[2]);
            return $this->result($lower, 0.78, 'Used lower bound of per-unit price range.');
        }

        if (preg_match('/(?:usd|kes|eur|gbp|\$|€|£)\s*([0-9]+(?:\.[0-9]{1,2})?)/i', $normalized, $m)) {
            return $this->result((float) $m[1], 0.72, 'Used first explicit currency amount found in pricing text.');
        }

        if (preg_match('/([0-9]+(?:\.[0-9]{1,2})?)\s*(?:per|\/)\s*(?:unit|seat|user|month|mo|item|site|visit|year)/i', $normalized, $m)) {
            return $this->result((float) $m[1], 0.7, 'Used first per-unit amount found in pricing text.');
        }

        return null;
    }

    private function inferWithAI(string $text): array
    {
        try {
            $prompt = "Extract a single best unit price from this pricing description.\n"
                . "Return strict JSON with keys: unit_price, confidence, reasoning.\n"
                . "Rules:\n"
                . "- unit_price must be a number only\n"
                . "- choose the base unit price, not total deal price\n"
                . "- if a range exists, choose the lower bound\n"
                . "- if no numeric price can be inferred, use 0\n\n"
                . $text;
            $raw = $this->aiService->process('data_inference', ['text' => $prompt]);
            $decoded = json_decode(trim($raw), true);
            $price = isset($decoded['unit_price']) ? (float) $decoded['unit_price'] : 0.0;
            return [
                'unit_price' => max(0.0, $price),
                'inferred' => $price > 0,
                'confidence' => max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0.55))),
                'reasoning' => (string) ($decoded['reasoning'] ?? 'AI inferred unit price from descriptive text.'),
            ];
        } catch (\Throwable $e) {
            return [
                'unit_price' => 0.0,
                'inferred' => false,
                'confidence' => 0.0,
                'reasoning' => 'Could not infer price from descriptive text.',
            ];
        }
    }

    private function result(float $unitPrice, float $confidence, string $reasoning): array
    {
        return [
            'unit_price' => max(0.0, round($unitPrice, 2)),
            'inferred' => true,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
        ];
    }
}
