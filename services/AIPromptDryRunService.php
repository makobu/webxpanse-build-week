<?php

namespace CRM\Services;

class AIPromptDryRunService
{
    private AIContextAssemblyService $contextAssembly;
    private AIRetrievalQualityService $retrievalQuality;
    private AIService $aiService;
    private AIPromptRegistryService $registry;

    public function __construct()
    {
        $this->contextAssembly = new AIContextAssemblyService();
        $this->retrievalQuality = new AIRetrievalQualityService();
        $this->aiService = new AIService();
        $this->registry = new AIPromptRegistryService();
    }

    public function dryRun(
        string $surface,
        string $promptKey,
        int $version = 0,
        array $sampleInput = [],
        int $maxBlocks = 12,
        int $maxChars = 12000
    ): array {
        $sampleInput['max_blocks'] = $maxBlocks;
        $sampleInput['max_chars'] = $maxChars;

        $bundle = $this->contextAssembly->buildContextBundle($surface, $promptKey, $sampleInput);
        $bundleSummary = $this->contextAssembly->summarizeBundle($bundle);
        $bundleQuality = $this->retrievalQuality->scoreBundle($bundle);
        $resolvedPrompt = $this->aiService->buildPromptFromRegistry($surface, $promptKey, $bundle, $sampleInput, $version > 0 ? $version : null);
        $resolvedVersion = (int) ($resolvedPrompt['prompt_version'] ?? 0);
        $promptRow = $resolvedVersion > 0
            ? $this->registry->getPromptVersion($surface, $promptKey, $resolvedVersion)
            : $this->registry->getActivePrompt($surface, $promptKey);

        return [
            'surface' => $surface,
            'prompt_key' => $promptKey,
            'prompt_version' => $resolvedVersion,
            'prompt_status' => (string) ($promptRow['status'] ?? 'active'),
            'rendered_prompt' => (string) ($resolvedPrompt['rendered_prompt'] ?? ''),
            'system_prompt' => (string) ($resolvedPrompt['system_prompt'] ?? ''),
            'instruction_prompt' => (string) ($resolvedPrompt['instruction_prompt'] ?? ''),
            'output_contract' => $resolvedPrompt['output_contract'] ?? null,
            'response_style_contract' => (array) ($bundle['response_style_contract'] ?? []),
            'context_bundle_summary' => $bundleSummary,
            'context_bundle_quality' => $bundleQuality,
            'retrieval_warnings' => (array) ($bundleQuality['warnings'] ?? []),
            'block_count' => count((array) ($bundle['blocks'] ?? [])),
            'bundle' => $bundle,
        ];
    }
}
