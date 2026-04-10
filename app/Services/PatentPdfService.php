<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PatentPdfService — Download automático de PDFs de patentes
 *
 * Suporta:
 *  - US  patents  → USPTO Full-Text (pdfpiw.uspto.gov)
 *  - EP  patents  → EPO Espacenet
 *  - WO  patents  → WIPO PatentScope
 *  - PT  patents  → INPI Portugal (best-effort)
 */
class PatentPdfService
{
    protected Client $http;
    protected string $storageDir = 'patents';

    public function __construct()
    {
        $this->http = new Client([
            'timeout'         => 60,
            'connect_timeout' => 15,
            'verify'          => false,
            'allow_redirects' => ['max' => 10, 'strict' => false, 'referer' => true],
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (compatible; ClawYard PatentBot/1.0; research@hp-group.org)',
                'Accept'     => 'application/pdf,text/html,*/*',
            ],
        ]);
    }

    /**
     * Extract all patent numbers from a text string.
     * Supports: US1234567B2, EP1234567A1, WO2023/123456, PT1234567
     */
    public function extractPatentNumbers(string $text): array
    {
        $patterns = [
            'US' => '/\b(US\s*\d{6,8}\s*[A-Z]\d?)\b/i',
            'EP' => '/\b(EP\s*\d{6,8}\s*[A-Z]\d?)\b/i',
            'WO' => '/\b(WO\s*\d{4}[\/\-]\d{5,6})\b/i',
            'PT' => '/\b(PT\s*\d{5,7}\s*[A-Z]?)\b/i',
        ];

        $found = [];
        foreach ($patterns as $type => $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $m) {
                    $clean = strtoupper(preg_replace('/\s+/', '', $m));
                    if (!in_array($clean, $found)) {
                        $found[] = $clean;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * Download a patent PDF and save to storage.
     * Returns the storage path on success, null on failure.
     */
    public function download(string $patentNumber): ?string
    {
        $patentNumber = strtoupper(preg_replace('/\s+/', '', $patentNumber));
        $filename     = $patentNumber . '.pdf';
        $storagePath  = $this->storageDir . '/' . $filename;

        // Already downloaded
        if (Storage::disk('local')->exists($storagePath)) {
            Log::info("PatentPdf: already exists — {$patentNumber}");
            return $storagePath;
        }

        // Ensure directory exists
        Storage::disk('local')->makeDirectory($this->storageDir);

        // Try download based on prefix
        $prefix = substr($patentNumber, 0, 2);

        try {
            $pdfContent = match(true) {
                str_starts_with($patentNumber, 'US') => $this->downloadUs($patentNumber),
                str_starts_with($patentNumber, 'EP') => $this->downloadEp($patentNumber),
                str_starts_with($patentNumber, 'WO') => $this->downloadWo($patentNumber),
                str_starts_with($patentNumber, 'PT') => $this->downloadPt($patentNumber),
                default => null,
            };

            if ($pdfContent && strlen($pdfContent) > 1024 && str_starts_with($pdfContent, '%PDF')) {
                Storage::disk('local')->put($storagePath, $pdfContent);
                Log::info("PatentPdf: downloaded {$patentNumber} (" . round(strlen($pdfContent)/1024) . " KB)");
                return $storagePath;
            }

            Log::warning("PatentPdf: failed or invalid PDF for {$patentNumber}");
            return null;

        } catch (\Throwable $e) {
            Log::error("PatentPdf: error downloading {$patentNumber} — " . $e->getMessage());
            return null;
        }
    }

    /**
     * Download multiple patents. Returns array of [patentNumber => storagePath|null].
     */
    public function downloadMultiple(array $patentNumbers): array
    {
        $results = [];
        foreach ($patentNumbers as $pn) {
            $results[$pn] = $this->download($pn);
        }
        return $results;
    }

    /**
     * List all downloaded patent PDFs.
     */
    public function listDownloaded(): array
    {
        $files = Storage::disk('local')->files($this->storageDir);
        $result = [];
        foreach ($files as $f) {
            if (str_ends_with($f, '.pdf')) {
                $result[] = [
                    'patent'   => basename($f, '.pdf'),
                    'path'     => $f,
                    'size_kb'  => round(Storage::disk('local')->size($f) / 1024),
                    'date'     => date('d/m/Y H:i', Storage::disk('local')->lastModified($f)),
                    'url'      => '/patents/download/' . basename($f, '.pdf'),
                ];
            }
        }
        return $result;
    }

    // ─── US Patent (USPTO) ─────────────────────────────────────────────────

    protected function downloadUs(string $patentNumber): ?string
    {
        // Remove prefix and suffix: US10123456B2 → 10123456
        $docid = preg_replace('/^US/', '', $patentNumber);
        $docid = preg_replace('/[A-Z]\d?$/', '', $docid);

        $url = "https://pdfpiw.uspto.gov/.piw?PageNum=0&docid=" . urlencode($docid);

        $response = $this->http->get($url);
        $body     = (string) $response->getBody();

        // USPTO returns HTML that redirects to the actual PDF
        if (str_starts_with($body, '%PDF')) {
            return $body;
        }

        // Try direct Google Patents PDF (more reliable)
        return $this->downloadGooglePatents($patentNumber);
    }

    // ─── EP Patent (EPO Espacenet) ─────────────────────────────────────────

    protected function downloadEp(string $patentNumber): ?string
    {
        // EP1234567A1 → EP1234567A1
        $url = "https://worldwide.espacenet.com/patent/pdf/{$patentNumber}";

        try {
            $response = $this->http->get($url);
            $body     = (string) $response->getBody();
            if (str_starts_with($body, '%PDF')) {
                return $body;
            }
        } catch (\Throwable $e) {
            Log::info("PatentPdf EP Espacenet failed: " . $e->getMessage());
        }

        return $this->downloadGooglePatents($patentNumber);
    }

    // ─── WO Patent (WIPO PatentScope) ─────────────────────────────────────

    protected function downloadWo(string $patentNumber): ?string
    {
        // WO2023/123456 → WO2023123456
        $clean = preg_replace('/[\/\-]/', '', $patentNumber);

        // Try WIPO PDF service
        $url = "https://patentscope.wipo.int/search/en/detail.jsf?docId={$clean}&tab=PDFANDDRAWINGS";

        try {
            $response = $this->http->get($url);
            $body     = (string) $response->getBody();

            // Extract PDF URL from page
            if (preg_match('/href="([^"]+\.pdf)"/', $body, $m)) {
                $pdfUrl   = $m[1];
                $pdfResp  = $this->http->get($pdfUrl);
                $pdfBody  = (string) $pdfResp->getBody();
                if (str_starts_with($pdfBody, '%PDF')) {
                    return $pdfBody;
                }
            }
        } catch (\Throwable $e) {
            Log::info("PatentPdf WO WIPO failed: " . $e->getMessage());
        }

        return $this->downloadGooglePatents($patentNumber);
    }

    // ─── PT Patent (INPI Portugal) ─────────────────────────────────────────

    protected function downloadPt(string $patentNumber): ?string
    {
        // Try Google Patents as fallback (INPI doesn't have easy PDF API)
        return $this->downloadGooglePatents($patentNumber);
    }

    // ─── Google Patents (universal fallback) ──────────────────────────────

    protected function downloadGooglePatents(string $patentNumber): ?string
    {
        try {
            // Google Patents page to find PDF link
            $url      = "https://patents.google.com/patent/{$patentNumber}/en";
            $response = $this->http->get($url, [
                'headers' => ['Accept' => 'text/html,application/xhtml+xml,*/*'],
            ]);
            $html = (string) $response->getBody();

            // Extract PDF storage URL from Google Patents page
            // Pattern: https://patentimages.storage.googleapis.com/.../{patentNumber}.pdf
            if (preg_match('/https:\/\/patentimages\.storage\.googleapis\.com\/[^"\']+\.pdf/', $html, $m)) {
                $pdfUrl   = $m[0];
                $pdfResp  = $this->http->get($pdfUrl);
                $pdfBody  = (string) $pdfResp->getBody();
                if (str_starts_with($pdfBody, '%PDF')) {
                    return $pdfBody;
                }
            }
        } catch (\Throwable $e) {
            Log::info("PatentPdf Google Patents fallback failed for {$patentNumber}: " . $e->getMessage());
        }

        return null;
    }
}
