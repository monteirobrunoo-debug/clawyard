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
        // SECURITY: PDF downloads must be TLS-verified — a MITM could swap a
        // legitimate patent PDF for a doctored one and poison the prior-art
        // knowledge base. All targeted sources (EPO, USPTO, Google Patents,
        // WIPO) publish valid certificates.
        $this->http = new Client([
            'timeout'         => 60,
            'connect_timeout' => 15,
            'verify'          => true,
            'allow_redirects' => ['max' => 10, 'strict' => false, 'referer' => true],
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (compatible; ClawYard PatentBot/1.0; research@hp-group.org)',
                'Accept'     => 'application/pdf,text/html,*/*',
            ],
        ]);
    }

    /**
     * Extract all patent numbers from a text string.
     * Supports:
     *   US1234567B2, US 1234567 B2  (USPTO)
     *   EP1234567A1, EP 1234567     (EPO — with or without kind code)
     *   WO2023/123456, WO2023123456 (WIPO PCT)
     *   PT1234567                   (INPI Portugal)
     *   EPO:EP1234567B1             (EPO_NUMBER field format from QuantumAgent)
     */
    public function extractPatentNumbers(string $text): array
    {
        $patterns = [
            // EPO_NUMBER= format from QuantumAgent: EPO_NUMBER=EP1234567B1
            'EPO_FIELD' => '/EPO_NUMBER\s*[=:]\s*([A-Z]{2}\s*\d{5,10}\s*[A-Z]\d?)/i',
            // EPO: prefix format: EPO:EP1234567B1
            'EPO_PREFIX' => '/EPO\s*:\s*([A-Z]{2}\s*\d{5,10}\s*[A-Z]\d?)/i',
            // Standard US with kind code
            'US' => '/\b(US\s*\d{6,8}\s*[A-Z]\d?)\b/i',
            // EP with optional kind code
            'EP' => '/\b(EP\s*\d{5,8}(?:\s*[A-Z]\d?)?)\b/i',
            // WO/PCT
            'WO' => '/\b(WO\s*\d{4}\s*[\/\-]?\s*\d{5,6})\b/i',
            // PT
            'PT' => '/\b(PT\s*\d{5,7}(?:\s*[A-Z])?)\b/i',
        ];

        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $m) {
                    $clean = strtoupper(preg_replace('/[\s\-]/', '', $m));
                    // Normalise WO: WO2023123456 → WO2023/123456
                    if (str_starts_with($clean, 'WO') && !str_contains($clean, '/')) {
                        $clean = 'WO' . substr($clean, 2, 4) . '/' . substr($clean, 6);
                    }
                    if (!in_array($clean, $found) && strlen($clean) >= 7) {
                        $found[] = $clean;
                    }
                }
            }
        }
        return array_values(array_unique($found));
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

        // 1) USPTO Direct PDF URL (most reliable, no redirect)
        $directUrls = [
            "https://pdfpiw.uspto.gov/patents/pdf/{$docid}.pdf",
            "https://pdfpiw.uspto.gov/.piw?PageNum=0&docid=" . urlencode($docid),
        ];

        foreach ($directUrls as $url) {
            try {
                $response = $this->http->get($url, [
                    'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; ClawYard/1.0; research@hp-group.org)'],
                ]);
                $body = (string) $response->getBody();
                if (str_starts_with($body, '%PDF')) return $body;
            } catch (\Throwable $e) {
                Log::info("PatentPdf USPTO direct failed ({$url}): " . $e->getMessage());
            }
        }

        // 2) Google Patents fallback
        return $this->downloadGooglePatents($patentNumber);
    }

    // ─── EP Patent (EPO) ──────────────────────────────────────────────────

    protected function downloadEp(string $patentNumber): ?string
    {
        // Strip kind code to get base number: EP3456789B1 → EP3456789
        $baseNumber = preg_replace('/^(EP\d+)[A-Z]\d*$/', '$1', $patentNumber);
        $numOnly    = preg_replace('/^EP/', '', $baseNumber);

        // 1) EPO Register REST API — lists actual patent documents (no auth required)
        try {
            $regUrl  = "https://register.epo.org/rest-services/application/EP{$numOnly}/documents";
            $regResp = $this->http->get($regUrl, [
                'headers' => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (compatible; ClawYard/1.0; research@hp-group.org)',
                ],
            ]);
            $regData = json_decode((string) $regResp->getBody(), true);

            // Find the granted patent or B-document PDF in the document list
            if (!empty($regData['documents'])) {
                foreach ($regData['documents'] as $doc) {
                    $docType = strtolower($doc['type'] ?? '');
                    $docUrl  = $doc['url'] ?? '';
                    if (str_contains($docType, 'patent') || str_contains($docType, 'b1') || str_contains($docType, 'b2')) {
                        if ($docUrl) {
                            $pdfResp = $this->http->get($docUrl);
                            $pdfBody = (string) $pdfResp->getBody();
                            if (str_starts_with($pdfBody, '%PDF')) return $pdfBody;
                        }
                    }
                }
                // If no specific match, try first document
                foreach ($regData['documents'] as $doc) {
                    $docUrl = $doc['url'] ?? '';
                    if ($docUrl) {
                        $pdfResp = $this->http->get($docUrl);
                        $pdfBody = (string) $pdfResp->getBody();
                        if (str_starts_with($pdfBody, '%PDF')) return $pdfBody;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info("PatentPdf EPO Register API failed for {$patentNumber}: " . $e->getMessage());
        }

        // 2) EPO OPS v3.2 — correct images endpoint (different from fulltext)
        try {
            // First get the list of image links
            $opsUrl  = "https://ops.epo.org/3.2/rest-services/published-data/publication/epodoc/{$patentNumber}/images";
            $opsResp = $this->http->get($opsUrl, [
                'headers' => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (compatible; ClawYard/1.0; research@hp-group.org)',
                ],
            ]);
            $opsData = json_decode((string) $opsResp->getBody(), true);

            // EPO OPS images response has @link arrays pointing to actual pages
            $opsLink = $opsData['ops:world-patent-data']['ops:patent-family']['ops:family-member'][0]['ops:document-instance'][0]['@link'] ?? null;
            if (!$opsLink) {
                // Try direct published-data path
                $instances = $opsData['ops:world-patent-data']['ops:biblio-search']['ops:search-result']['exchange-documents'][0]['exchange-document']['document-instance'] ?? [];
                foreach ($instances as $inst) {
                    if (($inst['@desc'] ?? '') === 'Drawing' || ($inst['@desc'] ?? '') === 'DRAWING') continue;
                    $opsLink = $inst['@link'] ?? null;
                    if ($opsLink) break;
                }
            }

            if ($opsLink) {
                // Download as PDF directly
                $pdfUrl  = "https://ops.epo.org/3.2/rest-services/" . ltrim($opsLink, '/') . "?Range=1-30";
                $pdfResp = $this->http->get($pdfUrl, ['headers' => ['Accept' => 'application/pdf']]);
                $pdfBody = (string) $pdfResp->getBody();
                if (str_starts_with($pdfBody, '%PDF')) return $pdfBody;
            }
        } catch (\Throwable $e) {
            Log::info("PatentPdf EPO OPS images failed for {$patentNumber}: " . $e->getMessage());
        }

        // 3) Lens.org — free patent API (no key required for basic access)
        try {
            $lensUrl  = "https://api.lens.org/patent/search";
            $lensResp = $this->http->post($lensUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'query'   => ['term' => ['publication_number' => $patentNumber]],
                    'include' => ['lens_id', 'publication_number'],
                    'size'    => 1,
                ],
            ]);
            $lensData = json_decode((string) $lensResp->getBody(), true);
            $lensId   = $lensData['data'][0]['lens_id'] ?? null;
            if ($lensId) {
                // Try direct Lens PDF download
                $lensPdf  = $this->http->get("https://lens.org/api/patent/{$lensId}/pdf");
                $lensBody = (string) $lensPdf->getBody();
                if (str_starts_with($lensBody, '%PDF')) return $lensBody;
            }
        } catch (\Throwable $e) {
            Log::info("PatentPdf Lens.org failed for {$patentNumber}: " . $e->getMessage());
        }

        // 4) Google Patents (best-effort — may be blocked)
        $pdf = $this->downloadGooglePatents($patentNumber);
        if ($pdf) return $pdf;

        // 5) Espacenet direct (best-effort)
        try {
            $url      = "https://worldwide.espacenet.com/patent/pdf/{$patentNumber}";
            $response = $this->http->get($url, [
                'headers' => [
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept'          => 'application/pdf,*/*',
                    'Accept-Language' => 'en-GB,en;q=0.9',
                    'Referer'         => 'https://worldwide.espacenet.com/',
                ],
            ]);
            $body     = (string) $response->getBody();
            if (str_starts_with($body, '%PDF')) return $body;
        } catch (\Throwable $e) {
            Log::info("PatentPdf Espacenet failed for {$patentNumber}: " . $e->getMessage());
        }

        return null;
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
