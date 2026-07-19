<?php
/**
 * AI Document Extractor
 *
 * Extracts entities and suggests tags from document content.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AIService;

class AIDocumentExtractor
{
    private AIService $aiService;
    private Documents $documents;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->documents = new Documents();
    }

    /**
     * Extract entities and suggest tags from a document.
     *
     * @return array { entities: { names, dates, amounts }, suggested_tags: [], summary: string }
     */
    public function extractFromDocument(int $documentId): array
    {
        $doc = $this->documents->getById($documentId);
        if (!$doc) {
            throw new \Exception("Document not found");
        }

        $filePath = $doc['file_path'] ?? '';
        if (!is_file($filePath)) {
            throw new \Exception("Document file not found");
        }

        $text = $this->extractText($filePath, $doc['mime_type'] ?? '');
        $text = mb_substr($text, 0, 15000);

        if (empty(trim($text))) {
            return [
                'entities' => ['names' => [], 'dates' => [], 'amounts' => []],
                'suggested_tags' => [],
                'summary' => 'Could not extract text from document.',
            ];
        }

        $raw = $this->aiService->process('document_extraction', [
            'text' => $text,
            'content' => $text,
            'source_type' => 'document',
        ]);

        return $this->parseResponse($raw);
    }

    private function extractText(string $filePath, string $mimeType): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($ext, ['txt', 'text', 'log', 'md', 'csv']) || strpos($mimeType, 'text') === 0) {
            $content = @file_get_contents($filePath);
            return $content !== false ? $content : '';
        }

        if ($ext === 'pdf' || strpos($mimeType, 'pdf') !== false) {
            return $this->extractTextFromPdf($filePath);
        }

        if (in_array($ext, ['docx', 'doc'])) {
            return $this->extractTextFromDocx($filePath);
        }

        return '';
    }

    private function extractTextFromPdf(string $filePath): string
    {
        if (class_exists('\Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filePath);
                return $pdf->getText();
            } catch (\Throwable $e) {
                return '';
            }
        }
        return '';
    }

    private function extractTextFromDocx(string $filePath): string
    {
        if (class_exists('\PhpOffice\PhpWord\IOFactory')) {
            try {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        $text .= $element->getText();
                    }
                }
                return $text;
            } catch (\Throwable $e) {
                return '';
            }
        }
        return '';
    }

    private function parseResponse(string $raw): array
    {
        $default = [
            'entities' => ['names' => [], 'dates' => [], 'amounts' => []],
            'suggested_tags' => [],
            'summary' => '',
        ];

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [
                'entities' => [
                    'names' => $decoded['entities']['names'] ?? [],
                    'dates' => $decoded['entities']['dates'] ?? [],
                    'amounts' => $decoded['entities']['amounts'] ?? [],
                ],
                'suggested_tags' => $decoded['suggested_tags'] ?? [],
                'summary' => $decoded['summary'] ?? '',
            ];
        }

        $default['summary'] = trim($raw) ?: 'No extraction result.';
        return $default;
    }
}
