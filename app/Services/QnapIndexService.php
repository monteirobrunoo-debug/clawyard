<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * QnapIndexService
 *
 * Scans /var/www/qnapbackup recursively, extracts text from
 * PDF, Excel, CSV, TXT and MSG files, and stores each document
 * in the `documents` table for RAG retrieval.
 */
class QnapIndexService
{
    protected string $basePath;
    protected int $indexed   = 0;
    protected int $skipped   = 0;
    protected int $errors    = 0;

    public function __construct(string $basePath = '/var/www/qnapbackup')
    {
        $this->basePath = rtrim($basePath, '/');
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Index all files. Returns summary stats.
     * @param callable|null $progress  called with (string $message) for each file
     */
    public function indexAll(?callable $progress = null): array
    {
        $files = $this->scanFiles();

        foreach ($files as $path) {
            try {
                $this->indexFile($path, $progress);
            } catch (\Throwable $e) {
                $this->errors++;
                Log::warning("QnapIndex: failed {$path}: " . $e->getMessage());
                if ($progress) $progress("⚠️  ERRO: " . basename($path) . ' — ' . $e->getMessage());
            }
        }

        return [
            'total'   => count($files),
            'indexed' => $this->indexed,
            'skipped' => $this->skipped,
            'errors'  => $this->errors,
        ];
    }

    /**
     * Re-index a single file by path.
     */
    public function indexFile(string $path, ?callable $progress = null): bool
    {
        // Skip temp files
        if (str_starts_with(basename($path), '~$')) {
            $this->skipped++;
            return false;
        }

        // Check if already indexed and unchanged
        $existing = Document::where('file_path', $path)->first();
        $mtime    = filemtime($path);
        if ($existing && ($existing->metadata['mtime'] ?? 0) >= $mtime) {
            $this->skipped++;
            return false;
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $text = match($ext) {
            'pdf'              => $this->extractPdf($path),
            'xlsx', 'xls'     => $this->extractExcel($path),
            'csv'              => $this->extractCsv($path),
            'txt', 'md'       => file_get_contents($path),
            'msg'              => $this->extractMsg($path),
            'doc', 'docx'     => $this->extractWordShell($path),
            default            => null,
        };

        if (!$text || mb_strlen(trim($text)) < 20) {
            $this->skipped++;
            return false;
        }

        // Sanitize UTF-8
        $text = $this->safeUtf8($text);

        // Build title from relative path
        $relative = str_replace($this->basePath . '/', '', $path);
        $title    = pathinfo($path, PATHINFO_FILENAME);
        $category = $this->detectCategory($relative);

        $data = [
            'title'     => $title,
            'source'    => 'qnap',
            'file_path' => $path,
            'content'   => mb_substr($text, 0, 100000), // cap at 100k chars
            'summary'   => mb_substr($text, 0, 600),
            'chunks'    => $this->chunkText($text),
            'metadata'  => [
                'path'      => $relative,
                'category'  => $category,
                'ext'       => $ext,
                'size'      => filesize($path),
                'mtime'     => $mtime,
            ],
        ];

        if ($existing) {
            $existing->update($data);
        } else {
            Document::create($data);
        }

        $this->indexed++;
        if ($progress) $progress("✅ " . $relative);
        return true;
    }

    /**
     * List all indexed QNAP documents (summary).
     */
    public function listIndexed(): array
    {
        return Document::where('source', 'qnap')
            ->select('id', 'title', 'summary', 'metadata', 'created_at')
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Full-text search across indexed QNAP documents.
     */
    public function search(string $query, int $limit = 8): array
    {
        $terms = array_filter(explode(' ', strtolower($query)));

        return Document::where('source', 'qnap')
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->where(function ($inner) use ($term) {
                        $inner->whereRaw('LOWER(content) LIKE ?', ["%{$term}%"])
                              ->orWhereRaw('LOWER(title) LIKE ?', ["%{$term}%"]);
                    });
                }
            })
            ->limit($limit)
            ->get(['id', 'title', 'content', 'summary', 'metadata'])
            ->toArray();
    }

    // ── File scanners ─────────────────────────────────────────────────────────

    protected function scanFiles(): array
    {
        $supported = ['pdf','xlsx','xls','csv','txt','msg','doc','docx','md'];
        $files     = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $supported)) continue;
            if (str_starts_with($file->getFilename(), '~$')) continue;
            $files[] = $file->getPathname();
        }

        return $files;
    }

    // ── Text extractors ───────────────────────────────────────────────────────

    protected function extractPdf(string $path): ?string
    {
        // Try pdftotext shell command first (faster, better quality)
        $escaped = escapeshellarg($path);
        $out     = shell_exec("pdftotext {$escaped} - 2>/dev/null");
        if ($out && mb_strlen(trim($out)) > 20) {
            return $out;
        }

        // Fallback: PHP PDF parser
        try {
            $parser   = new PdfParser();
            $pdf      = $parser->parseFile($path);
            return $pdf->getText();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function extractExcel(string $path): ?string
    {
        try {
            $spreadsheet = IOFactory::load($path);
            $lines       = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $lines[] = '=== Sheet: ' . $sheet->getTitle() . ' ===';
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    foreach ($row->getCellIterator() as $cell) {
                        $val = $cell->getFormattedValue();
                        if ($val !== '' && $val !== null) {
                            $cells[] = $val;
                        }
                    }
                    if (!empty($cells)) {
                        $lines[] = implode(' | ', $cells);
                    }
                }
            }

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            Log::warning("QnapIndex Excel error {$path}: " . $e->getMessage());
            return null;
        }
    }

    protected function extractCsv(string $path): ?string
    {
        $lines   = [];
        $handle  = fopen($path, 'r');
        if (!$handle) return null;
        $count = 0;
        while (($row = fgetcsv($handle)) !== false && $count < 5000) {
            $lines[] = implode(' | ', array_filter($row));
            $count++;
        }
        fclose($handle);
        return implode("\n", $lines);
    }

    protected function extractMsg(string $path): ?string
    {
        // MSG files are binary Outlook messages — extract readable text via regex
        $raw  = @file_get_contents($path);
        if (!$raw) return null;

        // Extract readable ASCII/Latin-1 strings of 4+ chars
        preg_match_all('/[\x20-\x7E\x09\x0A\x0D]{4,}/u', $raw, $matches);
        $strings = $matches[0] ?? [];

        // Filter noise (short hex strings, binary artifacts)
        $strings = array_filter($strings, fn($s) => mb_strlen($s) > 6 && !preg_match('/^[0-9a-f]{8,}$/i', $s));

        return implode("\n", array_unique(array_values($strings)));
    }

    protected function extractWordShell(string $path): ?string
    {
        $escaped = escapeshellarg($path);
        $out     = shell_exec("catdoc {$escaped} 2>/dev/null");
        return ($out && mb_strlen(trim($out)) > 10) ? $out : null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function detectCategory(string $relativePath): string
    {
        $lower = strtolower($relativePath);
        return match(true) {
            str_contains($lower, 'invoice')   || str_contains($lower, 'fatura')   => 'invoice',
            str_contains($lower, 'license')   || str_contains($lower, 'licen')    => 'license',
            str_contains($lower, 'credit')    || str_contains($lower, 'payment')  => 'finance',
            str_contains($lower, 'contract')  || str_contains($lower, 'contrato') => 'contract',
            str_contains($lower, 'concurso')  || str_contains($lower, 'tender')   => 'tender',
            str_contains($lower, 'supplier')  || str_contains($lower, 'fornec')   => 'supplier',
            default => 'document',
        };
    }

    protected function safeUtf8(string $str): string
    {
        $clean = @mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $clean ?? $str);
        return $clean !== false ? $clean : '';
    }

    protected function chunkText(string $text, int $size = 800): array
    {
        $words  = explode(' ', $text);
        $chunks = [];
        $chunk  = [];
        $count  = 0;

        foreach ($words as $word) {
            $chunk[] = $word;
            if (++$count >= $size) {
                $chunks[] = implode(' ', $chunk);
                $chunk    = [];
                $count    = 0;
            }
        }
        if (!empty($chunk)) $chunks[] = implode(' ', $chunk);

        return $chunks;
    }
}
