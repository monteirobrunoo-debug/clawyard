<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Pure-PHP PDF text extraction via smalot/pdfparser.
 *
 * Why not pdftotext (Poppler):
 *   • smalot is already in composer.json — no system dep to provision.
 *   • Both produce comparable text quality on RFP/RFQ-style documents.
 *   • Pure-PHP keeps the path container-independent.
 *
 * Output is normalised: collapsed whitespace, BOM stripped, capped at
 * MAX_CHARS so a 200-page contract doesn't blow Postgres TEXT pages
 * or (more importantly) LLM context windows downstream.
 */
class PdfTextExtractor
{
    /** Hard cap on extracted text — ~50KB. RFPs are dense, but the
     *  tail beyond this rarely changes the supplier-suggester decision. */
    public const MAX_CHARS = 50000;

    public function extract(string $absolutePath): array
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return ['ok' => false, 'text' => '', 'error' => 'file_not_readable'];
        }

        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile($absolutePath);
            $text   = (string) $pdf->getText();
        } catch (\Throwable $e) {
            Log::warning('PdfTextExtractor: parser failed', [
                'path'  => $absolutePath,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'text' => '', 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        $text = $this->normalise($text);
        if ($text === '') {
            return ['ok' => false, 'text' => '', 'error' => 'no_text_extracted'];
        }

        $truncated = mb_strlen($text) > self::MAX_CHARS;
        if ($truncated) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }

        return [
            'ok'        => true,
            'text'      => $text,
            'truncated' => $truncated,
        ];
    }

    private function normalise(string $text): string
    {
        // Replace BOM and weird control chars; collapse runs of whitespace.
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t\x0B\f]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        // Drop pages-worth of repeated runs that some PDFs emit (footers).
        $text = preg_replace('/(.{40,}?)\1{3,}/u', '$1', $text) ?? $text;
        return trim($text);
    }
}
