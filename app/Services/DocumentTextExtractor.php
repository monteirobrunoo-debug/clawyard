<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

/**
 * Multi-format text extractor: PDF / DOCX / EML.
 *
 * Pedido 2026-05-20: "aceita pdf, word e email". Roteia para o engine
 * certo conforme a extensão; devolve sempre o mesmo shape:
 *   ['ok' => bool, 'text' => string, 'error' => string|null]
 *
 * Engines:
 *   • PDF  → PdfTextExtractor (smalot/pdfparser, existente)
 *   • DOCX → PhpWord IOFactory ::load + walk dos elements
 *   • EML  → split raw MIME header/body + quoted-printable/base64 decode
 *           dos parts text/plain (best-effort, sem libs externas)
 */
class DocumentTextExtractor
{
    public function __construct(
        private PdfTextExtractor $pdf,
    ) {}

    /**
     * @return array{ok:bool, text:string, error:string|null}
     */
    public function extract(string $absolutePath, ?string $extHint = null): array
    {
        if (!is_file($absolutePath)) {
            return ['ok' => false, 'text' => '', 'error' => 'file_not_found'];
        }

        $ext = strtolower($extHint ?? pathinfo($absolutePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'         => $this->pdf->extract($absolutePath),
            'docx', 'doc' => $this->extractDocx($absolutePath),
            'eml'         => $this->extractEml($absolutePath),
            default       => ['ok' => false, 'text' => '', 'error' => "Formato '{$ext}' não suportado"],
        };
    }

    /** DOCX → texto via PhpWord. */
    private function extractDocx(string $path): array
    {
        try {
            $phpWord = WordIOFactory::load($path);
        } catch (\Throwable $e) {
            Log::warning('DocumentTextExtractor: docx load failed', [
                'path'  => basename($path),
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'text' => '', 'error' => 'docx_load: ' . $e->getMessage()];
        }

        $text = '';
        foreach ($phpWord->getSections() as $section) {
            $this->walkElements($section->getElements(), $text);
        }
        $text = trim($text);
        return [
            'ok'    => $text !== '',
            'text'  => $text,
            'error' => $text === '' ? 'docx_empty' : null,
        ];
    }

    /** Recursivo: PhpWord elements (TextRun, Table, ListItem, etc). */
    private function walkElements(array $elements, string &$out): void
    {
        foreach ($elements as $el) {
            if (method_exists($el, 'getText')) {
                $t = (string) $el->getText();
                if ($t !== '') $out .= $t . "\n";
            }
            if (method_exists($el, 'getElements')) {
                $children = (array) $el->getElements();
                if (!empty($children)) $this->walkElements($children, $out);
            }
            if (method_exists($el, 'getRows')) {
                // Tabela
                foreach ((array) $el->getRows() as $row) {
                    if (method_exists($row, 'getCells')) {
                        foreach ((array) $row->getCells() as $cell) {
                            if (method_exists($cell, 'getElements')) {
                                $this->walkElements((array) $cell->getElements(), $out);
                            }
                        }
                        $out .= "\n";
                    }
                }
            }
        }
    }

    /** EML → headers + body text/plain (best-effort). */
    private function extractEml(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return ['ok' => false, 'text' => '', 'error' => 'eml_read_failed'];
        }

        // Headers / body split na primeira linha em branco
        $sep = strpos($raw, "\r\n\r\n");
        if ($sep === false) $sep = strpos($raw, "\n\n");
        if ($sep === false) {
            return ['ok' => true, 'text' => $raw, 'error' => null];
        }

        $headersRaw = substr($raw, 0, $sep);
        $bodyRaw    = substr($raw, $sep + ($raw[$sep] === "\r" ? 4 : 2));

        // Parse headers básicos (Subject, From, To, Date)
        $headers = $this->parseEmlHeaders($headersRaw);
        $headerSummary = "";
        foreach (['From', 'To', 'Subject', 'Date'] as $h) {
            if (!empty($headers[$h])) {
                $headerSummary .= $h . ": " . $headers[$h] . "\n";
            }
        }
        if ($headerSummary !== '') $headerSummary .= "\n";

        // Body: handle multipart vs single
        $body = $this->extractEmlBody($bodyRaw, $headers);

        // Decode transfer-encoding (top-level se não foi multipart)
        $te = strtolower((string) ($headers['Content-Transfer-Encoding'] ?? ''));
        if ($te === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        } elseif ($te === 'base64') {
            $decoded = base64_decode($body, true);
            if ($decoded !== false) $body = $decoded;
        }

        $text = $headerSummary . trim($body);
        // UTF-8 sanitize
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = @iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: $text;
        }

        return [
            'ok'    => mb_strlen($text) > 20,
            'text'  => $text,
            'error' => mb_strlen($text) <= 20 ? 'eml_empty_body' : null,
        ];
    }

    private function parseEmlHeaders(string $headersRaw): array
    {
        $headers = [];
        // Unfold continuation lines
        $unfolded = preg_replace("/\r?\n[ \t]/", ' ', $headersRaw);
        foreach (preg_split("/\r?\n/", (string) $unfolded) as $line) {
            if (!preg_match('/^([A-Za-z\-]+)\s*:\s*(.*)$/', $line, $m)) continue;
            $name  = $m[1];
            $value = trim($m[2]);
            // Decode =?UTF-8?B?...?= em Subject
            if (str_contains($value, '=?')) {
                $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
                if ($decoded !== false) $value = $decoded;
            }
            $headers[$name] = $value;
        }
        return $headers;
    }

    private function extractEmlBody(string $bodyRaw, array $headers): string
    {
        $ct = (string) ($headers['Content-Type'] ?? '');
        // Multipart: procura boundary, junta text/plain parts
        if (preg_match('/multipart\/[^;]+;\s*boundary="?([^"\s;]+)"?/i', $ct, $bm)) {
            $boundary = $bm[1];
            $parts = preg_split('/--' . preg_quote($boundary, '/') . '(?:--)?\r?\n/', $bodyRaw);
            $textParts = [];
            foreach ($parts as $part) {
                if (trim($part) === '') continue;
                // Cada part tem o seu próprio mini-header
                $partSep = strpos($part, "\r\n\r\n");
                if ($partSep === false) $partSep = strpos($part, "\n\n");
                if ($partSep === false) continue;
                $pHeaders = $this->parseEmlHeaders(substr($part, 0, $partSep));
                $pBody    = substr($part, $partSep + 2);
                $pCt      = strtolower((string) ($pHeaders['Content-Type'] ?? 'text/plain'));
                if (!str_starts_with($pCt, 'text/')) continue;  // skip attachments/html quando há plain
                $pTe = strtolower((string) ($pHeaders['Content-Transfer-Encoding'] ?? ''));
                if ($pTe === 'quoted-printable') $pBody = quoted_printable_decode($pBody);
                elseif ($pTe === 'base64')        $pBody = base64_decode($pBody, true) ?: $pBody;
                // Prefere text/plain a text/html
                if (str_starts_with($pCt, 'text/plain')) {
                    array_unshift($textParts, trim($pBody));
                } else {
                    // text/html → strip tags
                    $textParts[] = trim(strip_tags($pBody));
                }
            }
            return implode("\n\n", array_filter($textParts));
        }

        // Single part — devolve body raw (transfer-encoding decoded fora)
        return $bodyRaw;
    }
}
